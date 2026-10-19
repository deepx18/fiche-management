<?php

declare(strict_types=1);

namespace App\Services\Telephony\Kavkom;

use App\Models\CallRecord;
use App\Services\Telephony\ProviderCall;
use App\Services\Telephony\TelephonyException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Traduit un CDR Kavkom reçu par webhook en ProviderCall.
 *
 * Champs documentés (Kavkom, « Données disponibles pour les appels terminés ») :
 * `xml_cdr_uuid`, `direction`, `status`, `duration`, `billsec`,
 * `outside_number`, `destination_number`, `caller_id_number`,
 * `referenced_extension`, `start_epoch`, `end_epoch`, `recording_url`.
 *
 * `recording_url` n'est jamais conservée : elle peut être signée et expirer.
 * Seule l'existence d'une référence est mémorisée. Le fichier audio n'est pas
 * transmis par le webhook, et Kavkom ne documente pas d'endpoint de
 * téléchargement : la récupération est donc explicitement non prise en charge.
 */
class KavkomCdrParser
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws TelephonyException si un CDR identifiable est malformé
     */
    public function parse(array $payload): ?ProviderCall
    {
        $id = $this->string($payload['xml_cdr_uuid'] ?? null);

        // Un événement temps réel n'a pas d'identifiant de CDR stable : il est
        // ignoré (202) plutôt que stocké comme un appel final.
        if ($id === null) {
            return null;
        }

        $direction = $this->string($payload['direction'] ?? null);

        // Priorité au champ qui correspond réellement au sens de l'appel :
        // `outside_number` seul peut désigner un DID ou un autre numéro externe
        // et ne doit pas provoquer une association erronée.
        $rawPhone = $this->isInbound($direction)
            ? $this->firstString([
                $payload['caller_id_number'] ?? null,
                $payload['outside_number'] ?? null,
                $payload['destination_number'] ?? null,
            ])
            : $this->firstString([
                $payload['destination_number'] ?? null,
                $payload['outside_number'] ?? null,
                $payload['caller_id_number'] ?? null,
            ]);

        $recordingUrl = $this->string($payload['recording_url'] ?? null);

        return new ProviderCall(
            providerCallId: $id,
            rawPhone: $rawPhone,
            rawDestination: $this->string($payload['destination_number'] ?? null),
            matchedPhoneE164: null,
            direction: $direction,
            status: $this->firstString([$payload['status'] ?? null, $payload['disposition'] ?? null]),
            startedAt: $this->epoch($payload['start_epoch'] ?? null)
                ?? $this->date($payload['start_stamp'] ?? null),
            endedAt: $this->epoch($payload['end_epoch'] ?? null)
                ?? $this->date($payload['end_stamp'] ?? null),
            durationSeconds: $this->int($payload['duration'] ?? null),
            billableSeconds: $this->int($payload['billsec'] ?? null),
            agent: $this->firstString([
                $payload['referenced_extension'] ?? null,
                $payload['extension'] ?? null,
            ]),
            recordingState: $recordingUrl !== null
                ? CallRecord::RECORDING_REFERENCE_ONLY
                : CallRecord::RECORDING_UNAVAILABLE,
            // Jamais l'URL : uniquement un état de disponibilité.
            recordingProviderReference: null,
            metadata: [
                'recording_url_present' => $recordingUrl !== null,
            ],
        );
    }

    private function isInbound(?string $direction): bool
    {
        return $direction !== null
            && in_array(strtolower(trim($direction)), ['in', 'inbound', 'incoming'], true);
    }

    private function epoch(mixed $value): ?CarbonImmutable
    {
        if (! is_numeric($value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromTimestampUTC((int) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            $string = $this->string($value);
            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    private function string(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
