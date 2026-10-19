<?php

namespace Tests;

use App\Services\Google\GoogleSheetsException;
use App\Services\Google\SheetsReader;

/**
 * Outils de test pour la synchronisation Production : injection du lecteur
 * simulé et configuration minimale (mapping identique aux valeurs par défaut
 * de config/production.php, sauf surcharge explicite).
 */
trait InteractsWithProductionSheets
{
    /** @var list<string> */
    private array $productionHeaders = ['N° contrat', 'Client', 'Ville', 'Produit', 'Statut'];

    /**
     * Injecte un lecteur simulé renvoyant les lignes fournies (les en-têtes
     * par défaut sont ajoutés en première ligne).
     *
     * @param  list<list<string|null>>  $dataRows
     */
    protected function fakeSheet(array $dataRows, ?array $headers = null): FakeSheetsReader
    {
        return $this->bindReader([$headers ?? $this->productionHeaders, ...$dataRows]);
    }

    /** @param list<list<string|null>> $rows */
    protected function bindReader(array $rows): FakeSheetsReader
    {
        $reader = new FakeSheetsReader;
        $reader->rows = $rows;
        $this->app->instance(SheetsReader::class, $reader);

        return $reader;
    }

    protected function failReaderWith(GoogleSheetsException $error): FakeSheetsReader
    {
        $reader = new FakeSheetsReader;
        $reader->error = $error;
        $this->app->instance(SheetsReader::class, $reader);

        return $reader;
    }

    /** En-têtes d'une feuille « Production » par défaut. */
    protected function sheetHeaders(): array
    {
        return $this->productionHeaders;
    }
}
