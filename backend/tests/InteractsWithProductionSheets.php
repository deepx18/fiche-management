<?php

namespace Tests;

use App\Services\Google\GoogleSheetsException;
use App\Services\Google\SheetsReader;

/**
 * Outils de test pour la synchronisation Production.
 *
 * Les en-têtes par défaut sont ceux réellement présents dans la feuille
 * (vérifiés dans le classeur), dans leur ordre réel. Les lignes sont des
 * fixtures synthétiques : aucune donnée personnelle réelle n'est utilisée.
 */
trait InteractsWithProductionSheets
{
    /** @var list<string> */
    private array $productionHeaders = [
        'DATE',
        'CIAL',
        'NOM',
        'PRENOM',
        'COTISATION',
        'PRIME ANNUELLE',
        'CIE',
        'PRODUIT',
        "Date d'effet",
        'VALIDATION',
        'TYPE DE SIGNATURE',
    ];

    /** Valeurs synthétiques par défaut, indexées par en-tête. */
    private array $productionRowDefaults = [
        'DATE' => '01/10/2026',
        'CIAL' => '0123456',
        'NOM' => 'FOURRIQUES',
        'PRENOM' => 'Danielle',
        'COTISATION' => '91.55',
        'PRIME ANNUELLE' => '1098.6',
        'CIE' => 'KIASSURE',
        'PRODUIT' => 'COVER UP - N3',
        "Date d'effet" => '01/12/2026',
        'VALIDATION' => 'CHLOE',
        'TYPE DE SIGNATURE' => 'SMS',
    ];

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

    /**
     * Construit une ligne complète (11 cellules) à partir d'overrides indexés
     * par en-tête, dans l'ordre des en-têtes.
     *
     * @param  array<string, string|null>  $overrides
     * @return list<string|null>
     */
    protected function sheetRow(array $overrides = []): array
    {
        $values = array_merge($this->productionRowDefaults, $overrides);

        return array_map(
            static fn (string $header): ?string => $values[$header] ?? null,
            $this->productionHeaders,
        );
    }

    /**
     * Construit une ligne complète pour une liste d'en-têtes donnée (ordre ou
     * libellé modifiés), en réutilisant les valeurs synthétiques par défaut.
     *
     * @param  list<string>  $headers
     * @param  array<string, string|null>  $overrides  indexés par en-tête canonique
     * @return list<string|null>
     */
    protected function sheetRowFor(array $headers, array $overrides = []): array
    {
        $values = array_merge($this->productionRowDefaults, $overrides);

        return array_map(function (string $header) use ($values): ?string {
            $canonical = $this->canonicalTestHeader($header);

            return $canonical === null ? null : ($values[$canonical] ?? null);
        }, $headers);
    }

    /** Ramène un en-tête de test (espaces, NBSP, apostrophe, casse) au libellé réel. */
    private function canonicalTestHeader(string $header): ?string
    {
        $value = str_replace(
            ["\u{00A0}", "\u{202F}", "\u{2019}", "\u{2018}"],
            [' ', ' ', "'", "'"],
            $header,
        );
        $value = mb_strtoupper(trim($value));
        $transliterator = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
        if ($transliterator !== null) {
            $value = mb_strtoupper($transliterator->transliterate($value) ?: $value);
        }
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return [
            'DATE' => 'DATE',
            'CIAL' => 'CIAL',
            'NOM' => 'NOM',
            'PRENOM' => 'PRENOM',
            'COTISATION' => 'COTISATION',
            'PRIME ANNUELLE' => 'PRIME ANNUELLE',
            'CIE' => 'CIE',
            'PRODUIT' => 'PRODUIT',
            "DATE D'EFFET" => "Date d'effet",
            'VALIDATION' => 'VALIDATION',
            'TYPE DE SIGNATURE' => 'TYPE DE SIGNATURE',
        ][$value] ?? null;
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
