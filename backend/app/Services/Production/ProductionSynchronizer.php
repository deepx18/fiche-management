<?php

namespace App\Services\Production;

use App\Models\ProductionRecord;
use App\Models\ProductionSync;
use App\Models\User;
use App\Services\Google\GoogleSheetsException;
use App\Services\Google\SheetsReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Synchronisation Google Sheets → production_records.
 *
 * Garanties :
 *  - **mapping vérifié** : les 11 en-têtes réels doivent être présents, une
 *    seule fois chacun. Un en-tête manquant ou dupliqué interrompt la
 *    synchronisation avant toute écriture. Les colonnes inconnues sont
 *    signalées, jamais devinées ;
 *  - **idempotence** : l'identité est `source_key`, SHA-256 d'une clé métier de
 *    7 champs (date, cial, nom, prénom, CIE, produit, date d'effet). Rejouer la
 *    même feuille met à jour les lignes existantes ; le numéro de ligne n'est
 *    jamais une identité ;
 *  - **possession des champs** : seuls les 11 champs de la feuille sont écrits.
 *    Le téléphone saisi dans l'application et `reference_contrat` ne sont
 *    jamais touchés ;
 *  - **non destructif** : aucune ligne absente de la réponse n'est supprimée ;
 *    une lecture tronquée est signalée « partielle », jamais présentée comme
 *    un succès complet ;
 *  - **traçable** : chaque tentative crée un ProductionSync (succès comme
 *    échec) avec compteurs, avertissements et erreurs par ligne, sans données
 *    personnelles ni secrets.
 *
 * L'appel réseau est effectué AVANT la transaction : aucune transaction base
 * n'est maintenue ouverte pendant l'attente de Google.
 */
class ProductionSynchronizer
{
    private const CHUNK_SIZE = 500;

    private const MAX_ROW_ERRORS = 250;

    private const MAX_WARNINGS = 25;

    private ProductionSheetSchema $schema;

    public function __construct(private readonly SheetsReader $reader)
    {
        $this->schema = new ProductionSheetSchema;
    }

    public function sync(?User $user = null): ProductionSync
    {
        $sync = ProductionSync::create([
            'user_id' => $user?->id,
            'status' => ProductionSync::STATUS_FAILED,
            'started_at' => now(),
        ]);

        try {
            $this->assertConfiguration();
            $rows = $this->reader->rows();

            return $this->apply($sync, $rows);
        } catch (GoogleSheetsException|ProductionMappingException $e) {
            $this->markFailed($sync, $e->getMessage());

            throw $e;
        } catch (Throwable $e) {
            // Erreur inattendue (base de données…) : la trace reste dans les
            // journaux, l'utilisateur reçoit un message générique.
            report($e);
            $this->markFailed($sync, 'Échec inattendu de la synchronisation. Consultez les journaux du backend.');

            throw $e;
        }
    }

    /**
     * @param  list<list<string|null>>  $rows
     */
    private function apply(ProductionSync $sync, array $rows): ProductionSync
    {
        $headerIndex = $this->locateHeaderRow($rows);
        if ($headerIndex === null) {
            throw ProductionMappingException::headerRowNotFound();
        }

        $resolution = $this->schema->resolveHeaderRow($rows[$headerIndex]);
        if ($resolution['missing'] !== [] || $resolution['duplicates'] !== []) {
            throw ProductionMappingException::invalidHeaders($resolution['missing'], $resolution['duplicates']);
        }

        $headerMap = $resolution['map'];
        $dataRows = array_slice($rows, $headerIndex + 1, null, true);

        $warnings = [];
        $this->collectColumnWarnings($rows[$headerIndex], $dataRows, $headerMap, $resolution['unrecognized'], $warnings);

        $truncated = $this->isFetchedRangeTruncated(count($rows));
        if ($truncated) {
            $warnings[] = 'La feuille contient au moins autant de lignes que la plage configurée '
                .'GOOGLE_SHEETS_RANGE ('.(string) config('production.range')
                .') : la lecture peut être incomplète. Aucune ligne locale n\'a été supprimée.';
        }

        $identityFields = $this->identity();
        $requiredFields = $this->required();

        $skipped = 0;
        $rowErrors = [];
        $valid = [];
        $seenKeys = [];

        foreach ($dataRows as $index => $row) {
            $sheetRow = (int) $index + 1; // numéro de ligne 1-indexé (informatif)

            $raw = [];
            foreach (array_keys(ProductionRecord::SHEET_FIELDS) as $field) {
                $raw[$field] = $this->schema->cleanText($this->cell($row, $headerMap[$field] ?? null));
            }

            if (array_filter($raw, static fn (?string $value): bool => $value !== null) === []) {
                $skipped++;

                continue;
            }

            [$values, $errors] = $this->parseRow($sheetRow, $raw, $requiredFields);

            if ($errors !== []) {
                array_push($rowErrors, ...$errors);

                continue;
            }

            $key = $this->sourceKey($values, $identityFields);
            if (isset($seenKeys[$key])) {
                $rowErrors[] = [
                    'row' => $sheetRow,
                    'field' => 'IDENTITÉ',
                    'reason' => 'Ligne identique à la ligne '.$seenKeys[$key].' de la feuille (même date, CIAL, nom, prénom, CIE, produit et date d\'effet).',
                ];

                continue;
            }
            $seenKeys[$key] = $sheetRow;

            $valid[] = ['row' => $sheetRow, 'key' => $key, 'values' => $values];
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($valid, &$created, &$updated): void {
            foreach (array_chunk($valid, self::CHUNK_SIZE) as $chunk) {
                $existing = ProductionRecord::query()
                    ->whereIn('source_key', array_column($chunk, 'key'))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('source_key');

                foreach ($chunk as $item) {
                    $attributes = $this->spreadsheetAttributes($item['values'], $item['row']);
                    $record = $existing->get($item['key']);

                    if ($record !== null) {
                        // Seuls les champs de la feuille sont écrits : `phone`,
                        // `phone_normalise` et `reference_contrat` sont absents
                        // de $attributes.
                        $record->update($attributes);
                        $updated++;
                    } else {
                        ProductionRecord::createWithReference($attributes + ['source_key' => $item['key']]);
                        $created++;
                    }
                }
            }
        });

        $failed = count(array_unique(array_column($rowErrors, 'row')));
        $rowsRead = count($dataRows);

        [$status, $summary] = $this->summarize($rowsRead, $created, $updated, $skipped, $failed, $rowErrors, $truncated);

        $sync->update([
            'status' => $status,
            'rows_read' => $rowsRead,
            'created_count' => $created,
            'updated_count' => $updated,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'error_summary' => $summary,
            'warnings' => $warnings === [] ? null : array_slice($warnings, 0, self::MAX_WARNINGS),
            'row_errors' => $rowErrors === [] ? null : array_slice($rowErrors, 0, self::MAX_ROW_ERRORS),
            'completed_at' => now(),
        ]);

        Log::info('Synchronisation production terminée', [
            'sync_id' => $sync->id,
            'status' => $status,
            'rows_read' => $rowsRead,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'warnings' => count($warnings),
        ]);

        return $sync->refresh();
    }

    /**
     * @param  array<string, string|null>  $raw
     * @param  list<string>  $requiredFields
     * @return array{0: array<string, string|null>, 1: list<array{row:int, field:string, reason:string}>}
     */
    private function parseRow(int $sheetRow, array $raw, array $requiredFields): array
    {
        $values = $raw;
        $errors = [];

        foreach ($requiredFields as $field) {
            if (($raw[$field] ?? null) === null) {
                $errors[] = $this->error($sheetRow, $field, 'Champ obligatoire vide.');
            }
        }

        foreach (ProductionRecord::SHEET_FIELDS as $field => $column) {
            $value = $raw[$field] ?? null;
            $max = ProductionSheetSchema::MAX_LENGTH[$field] ?? null;

            if ($value !== null && $max !== null && mb_strlen($value) > $max) {
                $errors[] = $this->error($sheetRow, $field, ProductionValueException::tooLong($max)->getMessage());
            }
        }

        foreach (['date', 'date_effet'] as $field) {
            if ($raw[$field] === null) {
                continue; // déjà signalé comme obligatoire
            }
            try {
                $values[$field] = $this->schema->parseDate($raw[$field]);
            } catch (ProductionValueException $e) {
                $errors[] = $this->error($sheetRow, $field, $e->getMessage());
            }
        }

        foreach (['cotisation', 'prime_annuelle'] as $field) {
            if ($raw[$field] === null) {
                $values[$field] = null;

                continue;
            }
            try {
                $values[$field] = $this->schema->parseAmount($raw[$field]);
            } catch (ProductionValueException $e) {
                $errors[] = $this->error($sheetRow, $field, $e->getMessage());
            }
        }

        return [$values, $errors];
    }

    /**
     * @return array{row:int, field:string, reason:string}
     */
    private function error(int $sheetRow, string $field, string $reason): array
    {
        return [
            'row' => $sheetRow,
            'field' => ProductionSheetSchema::FIELD_LABELS[$field] ?? mb_strtoupper($field),
            'reason' => $reason,
        ];
    }

    /**
     * Signale les colonnes que l'application n'utilise pas.
     *
     * @param  list<string|null>  $headerRow
     * @param  iterable<int, list<string|null>>  $dataRows
     * @param  array<string, int>  $headerMap
     * @param  list<string>  $unrecognized
     * @param  list<string>  $warnings
     */
    private function collectColumnWarnings(
        array $headerRow,
        iterable $dataRows,
        array $headerMap,
        array $unrecognized,
        array &$warnings,
    ): void {
        if ($unrecognized !== []) {
            $warnings[] = 'Colonne(s) non reconnue(s) ignorée(s) : '.implode(', ', $unrecognized)
                .'. Aucune valeur de ces colonnes n\'est importée.';
        }

        $mappedIndexes = array_values($headerMap);
        $anonymousPopulated = [];

        foreach ($dataRows as $row) {
            foreach ($row as $index => $cell) {
                if (in_array((int) $index, $mappedIndexes, true)) {
                    continue;
                }
                if ($this->schema->cleanText($cell) !== null) {
                    $anonymousPopulated[(int) $index] = true;
                }
            }
        }

        foreach (array_keys($anonymousPopulated) as $index) {
            $label = $this->schema->cleanText($headerRow[$index] ?? null);
            if ($label !== null && in_array($label, $unrecognized, true)) {
                continue; // déjà signalée
            }
            $warnings[] = 'Colonne sans en-tête reconnu (position '.($index + 1).') contenant des données ignorée.';
        }
    }

    /** @return array{0: string, 1: string} statut + résumé lisible */
    private function summarize(
        int $rowsRead,
        int $created,
        int $updated,
        int $skipped,
        int $failed,
        array $rowErrors,
        bool $truncated,
    ): array {
        $parts = [];

        if ($created + $updated === 0) {
            $status = ProductionSync::STATUS_FAILED;
            $parts[] = $rowsRead === 0
                ? 'La plage configurée ne contient aucune ligne de données.'
                : 'Aucune ligne exploitable : toutes les lignes lues sont vides ou invalides.';
        } elseif ($truncated || $failed > 0) {
            $status = ProductionSync::STATUS_PARTIAL;
            $parts[] = $truncated
                ? 'Synchronisation partielle : lecture potentiellement tronquée.'
                : $failed.' ligne(s) rejetée(s) (détail ci-dessous).';
        } else {
            $status = ProductionSync::STATUS_COMPLETED;
            $parts[] = 'Synchronisation terminée sans erreur.';
        }

        if ($truncated) {
            $parts[] = 'La plage configurée a été atteinte : augmentez GOOGLE_SHEETS_RANGE pour lire toute la feuille.';
        }
        if ($failed > 0) {
            $parts[] = $failed.' ligne(s) rejetée(s), aucune n\'a été écrite.';
        }
        if ($skipped > 0) {
            $parts[] = $skipped.' ligne(s) vide(s) ignorée(s).';
        }
        if (count($rowErrors) > self::MAX_ROW_ERRORS) {
            $parts[] = 'Seules les '.self::MAX_ROW_ERRORS.' premières erreurs de ligne sont conservées.';
        }

        return [$status, implode(' ', array_unique($parts))];
    }

    private function markFailed(ProductionSync $sync, string $message): void
    {
        $sync->update([
            'status' => ProductionSync::STATUS_FAILED,
            'error_summary' => $message,
            'completed_at' => now(),
        ]);

        Log::warning('Synchronisation production échouée', [
            'sync_id' => $sync->id,
            'message' => $message,
        ]);
    }

    /**
     * @param  array<string, string|null>  $values
     * @param  list<string>  $identityFields
     */
    private function sourceKey(array $values, array $identityFields): string
    {
        $parts = [];
        foreach ($identityFields as $field) {
            $parts[] = $this->schema->identityValue($field, $values[$field] ?? null);
        }

        return hash('sha256', implode("\x1f", $parts));
    }

    /**
     * Champs mis à jour par la synchronisation — jamais `phone`, jamais
     * `reference_contrat`.
     *
     * @param  array<string, string|null>  $values
     * @return array<string, string|int|null>
     */
    private function spreadsheetAttributes(array $values, int $sheetRow): array
    {
        $attributes = [];
        foreach (ProductionRecord::SHEET_FIELDS as $field => $column) {
            $attributes[$column] = $values[$field] ?? null;
        }
        $attributes['source_row'] = $sheetRow;
        $attributes['last_synced_at'] = now();

        return $attributes;
    }

    /** @param list<string|null> $row */
    private function cell(array $row, ?int $index): ?string
    {
        if ($index === null || ! array_key_exists($index, $row)) {
            return null;
        }

        return $row[$index];
    }

    /** @param list<list<string|null>> $rows */
    private function locateHeaderRow(array $rows): ?int
    {
        $start = max(0, (int) config('production.header_row', 1) - 1);
        $count = count($rows);

        for ($i = $start; $i < $count; $i++) {
            if (array_filter($rows[$i], static fn (?string $cell): bool => $cell !== null && trim($cell) !== '') !== []) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Détecte une lecture bornée par la plage (ex. « A1:Z5000 »).
     *
     * Si le nombre de lignes lues atteint la dernière ligne de la plage, la
     * feuille peut contenir davantage de données : la synchronisation est
     * marquée partielle plutôt que présentée comme complète.
     */
    private function isFetchedRangeTruncated(int $fetchedRows): bool
    {
        $range = (string) config('production.range', '');
        if (preg_match('/(?:[A-Za-z0-9_]+!)?[A-Z]+(\d+)(?::[A-Z]+(\d+))?$/', $range, $matches) !== 1) {
            return false;
        }

        $lastRow = (int) ($matches[2] ?? $matches[1]);

        return $lastRow > 0 && $fetchedRows >= $lastRow;
    }

    /** @return array<string, list<string>> */
    private function mapping(): array
    {
        return (array) config('production.mapping', []);
    }

    /** @return list<string> */
    private function identity(): array
    {
        return array_values((array) config('production.identity', []));
    }

    /** @return list<string> */
    private function required(): array
    {
        return array_values((array) config('production.required', []));
    }

    /** Refuse une configuration incohérente avant tout appel réseau. */
    private function assertConfiguration(): void
    {
        $mapping = $this->mapping();
        if ($mapping === []) {
            throw ProductionMappingException::invalidConfiguration('aucune correspondance de colonnes définie (production.mapping).');
        }

        foreach (array_keys($mapping) as $field) {
            if (! array_key_exists($field, ProductionRecord::SHEET_FIELDS)) {
                throw ProductionMappingException::invalidConfiguration('champ inconnu « '.$field.' » dans production.mapping.');
            }
        }

        foreach (array_keys(ProductionRecord::SHEET_FIELDS) as $field) {
            if (! isset($mapping[$field])) {
                throw ProductionMappingException::invalidConfiguration('le champ « '.$field.' » doit figurer dans production.mapping.');
            }
        }

        $identity = $this->identity();
        if ($identity === []) {
            throw ProductionMappingException::invalidConfiguration('aucun champ d\'identité défini (production.identity).');
        }

        foreach ([...$identity, ...$this->required()] as $field) {
            if (! isset($mapping[$field])) {
                throw ProductionMappingException::invalidConfiguration('le champ « '.$field.' » doit figurer dans production.mapping.');
            }
        }
    }
}
