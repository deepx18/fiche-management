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
                RunCallSearchJob::dispatch($item->id);
                $queued++;
            }
        }

        $search->update([
            'requested_count' => CallSearchItem::query()->where('call_search_id', $search->id)->count(),
        ]);

        if ($queued === 0) {
            $this->progress->refresh($search);
        }

        return $search->refresh();
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
