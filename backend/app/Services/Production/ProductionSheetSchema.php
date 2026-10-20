<?php

namespace App\Services\Production;

use DateTimeImmutable;

/**
 * Schéma réel de la feuille « Production » : normalisation des en-têtes,
 * reconnaissance des colonnes et analyse des valeurs.
 *
 * Cette classe ne touche ni à la base ni au réseau : elle est testable seule.
 *
 * Règles de lecture (vérifiées sur la feuille de production) :
 *  - en-têtes : casse, accents, espaces multiples, espaces insécables et
 *    apostrophes typographiques sont neutralisés ; aucun autre libellé n'est
 *    accepté implicitement ;
 *  - dates : `jj/mm/aaaa` (format affiché par Google Sheets en mode
 *    FORMATTED_VALUE), `aaaa-mm-jj` en secours, et numéro de série Google
 *    Sheets (cellule non formatée en date) ;
 *  - montants : nombre avec « . » ou « , » comme séparateur décimal, espaces
 *    de milliers tolérés, aucun symbole monétaire deviné ; plus de 4 décimales
 *    est refusé plutôt qu'arrondi ;
 *  - textes : espaces superflus réduits, casse et accents conservés, zéros
 *    initiaux préservés (CIAL reste une chaîne).
 */
class ProductionSheetSchema
{
    /** Libellé de la feuille pour chaque champ canonique (diagnostics). */
    public const FIELD_LABELS = [
        'date' => 'DATE',
        'cial' => 'CIAL',
        'nom' => 'NOM',
        'prenom' => 'PRENOM',
        'cotisation' => 'COTISATION',
        'prime_annuelle' => 'PRIME ANNUELLE',
        'cie' => 'CIE',
        'produit' => 'PRODUIT',
        'date_effet' => "Date d'effet",
        'validation' => 'VALIDATION',
        'type_signature' => 'TYPE DE SIGNATURE',
    ];

    /** Longueurs maximales acceptées, alignées sur le schéma physique. */
    public const MAX_LENGTH = [
        'cial' => 60,
        'nom' => 190,
        'prenom' => 60,
        'cotisation' => 40,
        'prime_annuelle' => 40,
        'cie' => 190,
        'produit' => 190,
        'validation' => 190,
        'type_signature' => 300,
    ];

    /** @var array<string, list<string>> */
    private array $mapping;

    /** @param array<string, list<string>>|null $mapping */
    public function __construct(?array $mapping = null)
    {
        $this->mapping = $mapping ?? (array) config('production.mapping', []);
    }

    /**
     * Neutralise les différences inoffensives d'un en-tête.
     */
    public function normalizeHeader(string $header): string
    {
        $value = str_replace(
            ["\u{00A0}", "\u{202F}", "\u{2007}", "\u{2060}"],
            ' ',
            $header,
        );
        $value = str_replace(["\u{2019}", "\u{2018}", "\u{02BC}", '`', "\u{00B4}"], "'", $value);
        $value = mb_strtolower(trim($value));

        $transliterator = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
        if ($transliterator !== null) {
            $value = $transliterator->transliterate($value) ?: $value;
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Reconnaît la ligne d'en-têtes.
     *
     * @param  list<string|null>  $headerRow
     * @return array{
     *     map: array<string, int>,
     *     missing: list<string>,
     *     duplicates: list<string>,
     *     unrecognized: list<string>
     * }
     */
    public function resolveHeaderRow(array $headerRow): array
    {
        $lookup = [];
        foreach ($this->mapping as $field => $aliases) {
            foreach ((array) $aliases as $alias) {
                $lookup[$this->normalizeHeader((string) $alias)] = $field;
            }
        }

        $map = [];
        $duplicates = [];
        $unrecognized = [];

        foreach ($headerRow as $index => $cell) {
            $label = $this->cleanText($cell);
            if ($label === null) {
                continue; // colonne vide : ignorée
            }

            $field = $lookup[$this->normalizeHeader($label)] ?? null;
            if ($field === null) {
                $unrecognized[] = $label;

                continue;
            }

            if (isset($map[$field])) {
                $duplicates[] = $label;

                continue;
            }

            $map[$field] = (int) $index;
        }

        $missing = array_values(array_diff(array_keys($this->mapping), array_keys($map)));

        return [
            'map' => $map,
            'missing' => $missing,
            'duplicates' => $duplicates,
            'unrecognized' => $unrecognized,
        ];
    }

    /**
     * Nettoie un texte sans altérer son contenu métier.
     */
    public function cleanText(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = str_replace(["\u{00A0}", "\u{202F}", "\u{2007}", "\u{2060}"], ' ', $raw);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Convertit une date de la feuille en `Y-m-d`.
     *
     * @throws ProductionValueException date vide ou invalide
     */
    public function parseDate(?string $raw): string
    {
        $value = $this->cleanText($raw);
        if ($value === null) {
            throw ProductionValueException::invalidDate();
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $matches) === 1) {
            [$day, $month, $year] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
            if (! checkdate($month, $day, $year)) {
                throw ProductionValueException::invalidDate();
            }

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) === 1) {
            [$year, $month, $day] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
            if (! checkdate($month, $day, $year)) {
                throw ProductionValueException::invalidDate();
            }

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        // Cellule numérique non formatée en date : série Google Sheets.
        if (preg_match('/^\d{5,7}$/', $value) === 1) {
            $serial = (int) $value;
            if ($serial < 1 || $serial > 2958465) {
                throw ProductionValueException::invalidDate();
            }

            return (new DateTimeImmutable('1899-12-30'))->modify('+'.$serial.' days')->format('Y-m-d');
        }

        throw ProductionValueException::invalidDate();
    }

    /**
     * Valide un montant et renvoie sa forme décimale exacte (sans arrondi).
     *
     * @throws ProductionValueException montant invalide
     */
    public function parseAmount(?string $raw): ?string
    {
        $value = $this->cleanText($raw);
        if ($value === null) {
            return null;
        }

        // Espaces de milliers uniquement en groupes de trois (ex. « 1 098,60 »).
        // Un espace isolé (« 12 34 ») est une saisie invalide, pas un montant.
        if (preg_match('/^-?\d{1,3}(?: \d{3})+(?:[.,]\d{1,4})?$/', $value) === 1) {
            $value = str_replace(' ', '', $value);
        } elseif (preg_match('/^-?\d+(?:[.,]\d{1,4})?$/', $value) !== 1) {
            throw ProductionValueException::invalidAmount();
        }

        return str_replace(',', '.', $value);
    }

    /**
     * Valeur normalisée utilisée dans la clé d'identité.
     */
    public function identityValue(string $field, ?string $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($field === 'date' || $field === 'date_effet') {
            return $value;
        }

        return $this->normalizeHeader($value);
    }
}
