<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Jobs\RunCallSearchJob;
use App\Models\CallSearch;
use App\Models\CallSearchItem;
use App\Models\ProductionRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Orchestrateur d'une recherche groupée.
 *
 * La requête HTTP ne contacte jamais un fournisseur : elle valide les lignes,
 * crée un élément par ligne × fournisseur, puis dispatche un job par élément.
 * Les états restent distincts (succès, aucun résultat, échec, non pris en
 * charge, ambigu, sans numéro, numéro invalide).
 */
class CallSearchService
{
    public function __construct(
        private readonly CallProviderRegistry $registry,
        private readonly ProductionPhoneMatcher $matcher,
        private readonly CallSearchProgress $progress,
    ) {}

    /**
     * @param  list<int>  $recordIds
     * @param  list<string>  $providers
     */
    public function start(
        User $user,
        array $recordIds,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $providers,
        bool $retrieveRecordings,
    ): CallSearch {
        $providers = array_values(array_intersect($providers, $this->registry->names()));
        if ($providers === []) {
            // Par défaut, ne pas interroger un fournisseur qui ne prend pas en
            // charge la recherche (Kavkom) : cela produirait un lot « partiel »
            // systématique sans appel recherché.
            $providers = $this->registry->searchableNames();
        }

        if ($providers === []) {
            $providers = $this->registry->names();
        }

        if ($providers === []) {
            throw new InvalidArgumentException('Aucun fournisseur téléphonique n\'est configuré.');
        }

        $from = $from->startOfDay();
        $to = $to->endOfDay();

        if ($from->greaterThan($to)) {
            throw new InvalidArgumentException('La date de début est postérieure à la date de fin.');
        }

        /** @var Collection<int, ProductionRecord> $records */
        $records = ProductionRecord::query()
            ->whereIn('id', $recordIds)
            ->get()
            ->keyBy('id');

        if ($records->count() !== count(array_unique($recordIds))) {
            throw new InvalidArgumentException('Une ou plusieurs lignes de production sont introuvables.');
        }

        // Double-clic ou nouvel envoi du même formulaire : un lot identique
        // encore en cours est réutilisé au lieu d'empiler des jobs en double.
        $existing = $this->findActiveDuplicate($user, $recordIds, $from, $to, $providers, $retrieveRecordings);
        if ($existing !== null) {
            Log::info('Recherche d\'appels déjà en cours réutilisée', [
                'search_id' => $existing->id,
                'records' => count($recordIds),
                'providers' => $providers,
            ]);

            return $existing;
        }

        $search = CallSearch::create([
            'user_id' => $user->id,
            'status' => CallSearch::STATUS_QUEUED,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'providers' => $providers,
            'retrieve_recordings' => $retrieveRecordings,
            'requested_count' => 0,
        ]);

        $queued = 0;

        foreach ($recordIds as $recordId) {
            $record = $records->get($recordId);
            if (! $record instanceof ProductionRecord) {
                continue;
            }

            $number = TelephonyNumber::frenchWithReason($record->phone);

            if ($number['e164'] === null) {
                $status = trim((string) $record->phone) === ''
                    ? CallSearchItem::STATUS_SKIPPED_NO_PHONE
                    : CallSearchItem::STATUS_INVALID_PHONE;

                $this->createItem($search, $record, $providers, $from, $to, $status, null, [
                    'error_code' => $status,
                    'error_message' => $number['reason'],
                ]);

                continue;
            }

            $matches = $this->matcher->matchingRecords($number['e164']);
            if ($matches->count() > 1) {
                $this->createItem($search, $record, $providers, $from, $to, CallSearchItem::STATUS_AMBIGUOUS, $number['e164'], [
                    'error_code' => 'ambiguous',
                    'error_message' => 'Plusieurs lignes de production partagent ce numéro : '
                        .'aucune association automatique n\'est effectuée. Corrigez les doublons de numéro.',
                ]);

                continue;
            }

            foreach ($providers as $provider) {
                $item = $this->createItem(
                    $search,
                    $record,
                    [$provider],
                    $from,
                    $to,
                    CallSearchItem::STATUS_QUEUED,
                    $number['e164'],
                );
                // afterCommit : si l'appelant ouvre une transaction, le job ne
                // part pas avant validation (aucune transaction ici, donc
                // dispatch immédiat).
                RunCallSearchJob::dispatch($item->id)->afterCommit();
                $queued++;
            }
        }

        $search->update([
            'requested_count' => CallSearchItem::query()->where('call_search_id', $search->id)->count(),
        ]);

        Log::info('Recherche d\'appels planifiée', [
            'search_id' => $search->id,
            'records' => count($recordIds),
            'providers' => $providers,
            'retrieve_recordings' => $retrieveRecordings,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);

        if ($queued === 0) {
            $this->progress->refresh($search);
        }

        return $search->refresh();
    }

    /**
     * Un lot identique (même utilisateur, même plage, mêmes fournisseurs,
     * mêmes lignes) encore queued/running est considéré comme déjà lancé.
     *
     * @param  list<int>  $recordIds
     * @param  list<string>  $providers
     */
    private function findActiveDuplicate(
        User $user,
        array $recordIds,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $providers,
        bool $retrieveRecordings,
    ): ?CallSearch {
        $expected = array_values(array_unique(array_map('intval', $recordIds)));
        sort($expected);
        sort($providers);

        $active = CallSearch::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [CallSearch::STATUS_QUEUED, CallSearch::STATUS_RUNNING])
            ->whereDate('from_date', $from->toDateString())
            ->whereDate('to_date', $to->toDateString())
            ->where('retrieve_recordings', $retrieveRecordings)
            ->latest('id')
            ->limit(20)
            ->get();

        foreach ($active as $candidate) {
            $candidateProviders = (array) $candidate->providers;
            sort($candidateProviders);

            if ($candidateProviders !== $providers) {
                continue;
            }

            $candidateRecords = $candidate->items()
                ->whereNotNull('production_record_id')
                ->pluck('production_record_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            sort($candidateRecords);

            if ($candidateRecords === $expected) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $providers
     * @param  array<string, mixed>  $extra
     */
    private function createItem(
        CallSearch $search,
        ProductionRecord $record,
        array $providers,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $status,
        ?string $e164,
        array $extra = [],
    ): CallSearchItem {
        $first = null;

        foreach ($providers as $provider) {
            $first = CallSearchItem::create(array_merge([
                'call_search_id' => $search->id,
                'production_record_id' => $record->id,
                'provider' => $provider,
                'status' => $status,
                'phone_e164' => $e164,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'started_at' => in_array($status, CallSearchItem::TERMINAL_STATUSES, true) ? now() : null,
                'completed_at' => in_array($status, CallSearchItem::TERMINAL_STATUSES, true) ? now() : null,
            ], $extra));
        }

        return $first ?? new CallSearchItem;
    }
}
