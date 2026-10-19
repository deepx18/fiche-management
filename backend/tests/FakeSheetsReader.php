<?php

namespace Tests;

use App\Services\Google\GoogleSheetsException;
use App\Services\Google\SheetsReader;

/**
 * Lecteur Google Sheets simulé : les tests d'import n'ont besoin ni
 * d'identifiants réels ni d'accès réseau, et n'écrivent jamais dans un vrai
 * classeur. Les lignes retournées sont des fixtures explicites.
 */
class FakeSheetsReader implements SheetsReader
{
    /** @var list<list<string|null>> */
    public array $rows = [];

    public ?GoogleSheetsException $error = null;

    public int $calls = 0;

    public function rows(): array
    {
        $this->calls++;

        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->rows;
    }
}
