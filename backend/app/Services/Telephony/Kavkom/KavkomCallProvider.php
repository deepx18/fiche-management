<?php

declare(strict_types=1);

namespace App\Services\Telephony\Kavkom;

use App\Services\Telephony\CallProvider;
use App\Services\Telephony\CallSearchQuery;
use App\Services\Telephony\TelephonyException;

/**
 * Adaptateur Kavkom.
 *
 * Vérifié dans la documentation officielle (octobre 2026) : l'API PBX expose
 * les extensions et les appels en cours ; les appels terminés arrivent par
 * webhook (CDR), avec éventuellement `recording_url`. Aucune API documentée de
 * recherche d'historique n'existe. On ne fabrique donc ni endpoint ni schéma :
 * la recherche est déclarée non prise en charge, et les CDR reçus par webhook
 * sont stockés par le contrôleur d'ingestion.
 */
class KavkomCallProvider implements CallProvider
{
    public function name(): string
    {
        return 'kavkom';
    }

    public function supportsSearch(): bool
    {
        return false;
    }

    public function capabilities(): array
    {
        return [
            'search' => false,
            'recording_download' => false,
            'recording_reference' => true,
            'ingest' => 'webhook_cdr',
        ];
    }

    public function search(CallSearchQuery $query): array
    {
        throw TelephonyException::unsupported(
            'Kavkom ne documente pas d\'API de recherche d\'historique d\'appels. '
            .'Les CDR sont reçus par webhook ; la récupération audio n\'est pas documentée. '
            .'Aucun appel n\'a donc été recherché chez Kavkom.'
        );
    }
}
