<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use Carbon\CarbonImmutable;

/** Requête de recherche bornée : un numéro E.164, une plage, une limite. */
final class CallSearchQuery
{
    public function __construct(
        public readonly string $e164,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly int $limit,
    ) {
        if (! preg_match('/^\+[1-9]\d{6,14}$/', $e164)) {
            throw TelephonyException::invalidNumber('Le numéro de recherche n\'est pas au format E.164.');
        }

        if ($from->greaterThan($to)) {
            throw TelephonyException::configuration('La date de début de recherche est postérieure à la date de fin.');
        }

        if ($limit < 1 || $limit > 1000) {
            throw TelephonyException::configuration('La limite de résultats doit être comprise entre 1 et 1000.');
        }
    }
}
