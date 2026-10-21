<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CallSearch;
use App\Models\CallSearchItem;
use App\Services\Telephony\CallPersister;
use App\Services\Telephony\CallProviderRegistry;
use App\Services\Telephony\CallSearchProgress;
use App\Services\Telephony\CallSearchQuery;
use App\Services\Telephony\RecordingRetrieval;
use App\Services\Telephony\TelephonyException;
use App\Services\Telephony\TelephonyNumber;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recherche les appels d'une ligne pour un fournisseur.
 *
 * Le job est idempotent : l'écriture se fait sur (provider, provider_call_id).
 * Les erreurs transitoires (délai, quota, erreur fournisseur) sont retentées
 * avec un backoff borné ; une capacité non prise en charge est terminale et
 * n'est jamais convertie en « aucun appel ».
 */
class RunCallSearchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $itemId) {}

    public function handle(
        CallProviderRegistry $registry,
        CallPersister $persister,
        CallSearchProgress $progress,
        RecordingRetrieval $recordings,
    ): void {
        $item = CallSearchItem::query()
            ->with(['callSearch', 'productionRecord'])
            ->find($this->itemId);

        if ($item === null || $item->isTerminal()) {
            return;
        }

        $search = $item->callSearch;
        $search->update([
            'status' => CallSearch::STATUS_RUNNING,
            'started_at' => $search->started_at ?? now(),
        ]);

        $item->update([
            'status' => CallSearchItem::STATUS_RUNNING,
            'started_at' => $item->started_at ?? now(),
            'error_code' => null,
            'error_message' => null,
        ]);

        Log::info('Recherche d\'appels démarrée', [
            'search_id' => $search->id,
            'item_id' => $item->id,
            'provider' => $item->provider,
            'phone' => TelephonyNumber::mask($item->phone_e164),
            'from' => $item->from_date?->toDateString(),
            'to' => $item->to_date?->toDateString(),
        ]);

        try {
            $provider = $registry->get($item->provider);

            if (! $provider->supportsSearch()) {
                throw TelephonyException::unsupported(
                    $item->provider === 'kavkom'
                        ? 'Kavkom ne documente pas d\'API de recherche d\'historique. '
                            .'Les CDR sont reçus par webhook ; aucun appel n\'a été recherché chez Kavkom.'
                        : $item->provider.' ne prend pas en charge la recherche d\'appels dans cette configuration.'
                );
            }

            $calls = $provider->search(new CallSearchQuery(
                e164: (string) $item->phone_e164,
                from: $item->from_date->toImmutable()->startOfDay(),
                to: $item->to_date->toImmutable()->endOfDay(),
                limit: (int) config('telephony.search.max_results', 200),
            ));

            $saved = $persister->persist(
                $calls,
                $item->productionRecord,
                $item->provider,
                'provider_search',
            );

            $item->update([
                'status' => $saved === [] ? CallSearchItem::STATUS_NO_RESULTS : CallSearchItem::STATUS_COMPLETED,
                'result_count' => count($saved),
                'error_code' => null,
                'error_message' => null,
                'completed_at' => now(),
            ]);

            Log::info('Recherche d\'appels terminée', [
                'search_id' => $search->id,
                'provider' => $item->provider,
                'phone' => TelephonyNumber::mask($item->phone_e164),
                'matches' => count($saved),
            ]);

            // Récupération automatique : l'interface n'a pas à déclencher un
            // job caché. Chaque appel n'est enqueué qu'une fois, même si le
            // job est rejoué ou si une autre recherche porte sur la même
            // ligne (voir RecordingRetrieval).
            if ($search->retrieve_recordings && ($provider->capabilities()['recording_download'] ?? false)) {
                try {
                    foreach ($saved as $call) {
                        $recordings->queue($call);
                    }
                } catch (Throwable $e) {
                    // Un problème de file ne doit pas transformer une
                    // recherche réussie en échec : les métadonnées d'appel
                    // restent valides et la récupération peut être relancée.
                    report($e);

                    Log::error('Planification de la récupération en échec', [
                        'search_id' => $search->id,
                        'exception' => $e::class,
                    ]);
                }
            }
        } catch (TelephonyException $e) {
            if ($this->isRetryable($e) && $this->attempts() < $this->tries) {
                $item->update([
                    'status' => CallSearchItem::STATUS_QUEUED,
                    'error_code' => $e->kind(),
                    'error_message' => $e->getMessage(),
                ]);
                $progress->refresh($search->refresh());

                throw $e;
            }

            $this->markTerminal($item, $e);
        } catch (Throwable $e) {
            report($e);

            Log::error('Recherche d\'appels en échec inattendu', [
                'search_id' => $search->id,
                'provider' => $item->provider,
                'phone' => TelephonyNumber::mask($item->phone_e164),
                'exception' => $e::class,
            ]);

            $item->update([
                'status' => CallSearchItem::STATUS_FAILED,
                'error_code' => 'unexpected',
                'error_message' => 'Échec inattendu de la recherche. Consultez les journaux du backend.',
                'completed_at' => now(),
            ]);
        } finally {
            $progress->refresh($search->refresh());
        }
    }

    private function isRetryable(TelephonyException $e): bool
    {
        return in_array($e->kind(), [
            TelephonyException::KIND_RATE_LIMITED,
            TelephonyException::KIND_TIMEOUT,
            TelephonyException::KIND_PROVIDER_ERROR,
        ], true);
    }

    private function markTerminal(CallSearchItem $item, TelephonyException $e): void
    {
        Log::warning('Recherche d\'appels échouée', [
            'item_id' => $item->id,
            'provider' => $item->provider,
            'phone' => TelephonyNumber::mask($item->phone_e164),
            'code' => $e->kind(),
        ]);

        $status = match ($e->kind()) {
            TelephonyException::KIND_UNSUPPORTED => CallSearchItem::STATUS_UNSUPPORTED,
            TelephonyException::KIND_INVALID_NUMBER => CallSearchItem::STATUS_INVALID_PHONE,
            default => CallSearchItem::STATUS_FAILED,
        };

        $item->update([
            'status' => $status,
            'error_code' => $e->kind(),
            'error_message' => mb_substr($e->getMessage(), 0, 500),
            'completed_at' => now(),
        ]);
    }
}
