<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\CallRecord;
use App\Models\ProductionRecord;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Écriture idempotente des appels.
 *
 * L'identité est (provider, provider_call_id). Une réexécution met à jour les
 * métadonnées et `last_seen_at` sans créer de doublon. Un enregistrement déjà
 * stocké n'est jamais remplacé par un état plus faible (référence seule,
 * indisponible ou échec).
 */
class CallPersister
{
    /**
     * @param  list<ProviderCall>  $calls
     * @return list<CallRecord>
     */
    public function persist(array $calls, ?ProductionRecord $record, string $provider, string $matchBasis): array
    {
        $saved = [];
        $seen = [];

        foreach ($calls as $call) {
            if (isset($seen[$call->providerCallId])) {
                continue;
            }
            $seen[$call->providerCallId] = true;
            $saved[] = $this->persistOne($call, $record, $provider, $matchBasis);
        }

        return $saved;
    }

    public function persistOne(
        ProviderCall $call,
        ?ProductionRecord $record,
        string $provider,
        string $matchBasis,
    ): CallRecord {
        $existing = CallRecord::query()
            ->where('provider', $provider)
            ->where('provider_call_id', $call->providerCallId)
            ->first();

        $matchedPhone = $call->matchedPhoneE164
            ?? ($record !== null ? TelephonyNumber::french($record->phone) : null);

        $attributes = [
            'matched_phone_e164' => $matchedPhone,
            'raw_phone' => $this->limit($call->rawPhone, 120),
            'raw_destination' => $this->limit($call->rawDestination, 120),
            'direction' => $this->limit($call->direction, 20),
            'status' => $this->limit($call->status, 40),
            'started_at' => $call->startedAt,
            'ended_at' => $call->endedAt,
            'duration_seconds' => $call->durationSeconds,
            'billable_seconds' => $call->billableSeconds,
            'agent' => $this->limit($call->agent, 60),
            'match_basis' => $matchBasis,
            'last_seen_at' => now(),
            'provider_metadata' => $call->metadata === [] ? null : $call->metadata,
        ];

        // Ne jamais réaffecter un appel déjà associé à une autre ligne.
        if ($existing === null) {
            $attributes['production_record_id'] = $record?->id;
        } elseif ($existing->production_record_id === null && $record !== null) {
            $attributes['production_record_id'] = $record->id;
        }

        $recordingAttributes = $this->recordingAttributes($call, $existing);
        $attributes += $recordingAttributes;

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing->refresh();
        }

        try {
            return CallRecord::create($attributes + [
                'provider' => $provider,
                'provider_call_id' => $call->providerCallId,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Deux recherches simultanées pour la même ligne : l'index unique
            // (provider, provider_call_id) tranche, la seconde met à jour la
            // ligne déjà créée au lieu d'échouer.
            $existing = CallRecord::query()
                ->where('provider', $provider)
                ->where('provider_call_id', $call->providerCallId)
                ->first();

            if ($existing === null) {
                throw $e;
            }

            $existing->update($attributes);

            return $existing->refresh();
        }
    }

    /** @return array<string, mixed> */
    private function recordingAttributes(ProviderCall $call, ?CallRecord $existing): array
    {
        $newState = $call->recordingState;

        // Un fichier déjà vérifié et stocké reste la référence de vérité.
        if ($existing?->hasStoredRecording()) {
            return [];
        }

        // Une récupération en cours ne doit pas être rétrogradée en « simple
        // référence » par une nouvelle recherche tant que le job travaille.
        if ($existing?->recording_state === CallRecord::RECORDING_PENDING) {
            return [
                'recording_provider_reference' => $call->recordingProviderReference
                    ?? $existing->recording_provider_reference,
            ];
        }

        // Une simple référence ne doit pas être effacée par une réponse
        // ultérieure qui ne mentionne plus l'enregistrement.
        if ($existing?->recording_state === CallRecord::RECORDING_REFERENCE_ONLY
            && $newState === CallRecord::RECORDING_UNAVAILABLE) {
            return [];
        }

        return [
            'recording_state' => $newState,
            'recording_provider_reference' => $call->recordingProviderReference
                ?? $existing?->recording_provider_reference,
        ];
    }

    private function limit(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
