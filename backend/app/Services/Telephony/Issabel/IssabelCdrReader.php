<?php

declare(strict_types=1);

namespace App\Services\Telephony\Issabel;

use App\Services\Telephony\CallSearchQuery;
use App\Services\Telephony\TelephonyException;

/**
 * Lecture des CDR Issabel. L'implémentation réelle interroge une connexion
 * MySQL en lecture seule ; les tests injectent un lecteur simulé.
 */
interface IssabelCdrReader
{
    /**
     * @return list<array<string, mixed>> lignes brutes, bornées par la requête
     *
     * @throws TelephonyException
     */
    public function search(CallSearchQuery $query): array;
}
