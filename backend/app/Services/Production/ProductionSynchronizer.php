<?php

namespace App\Services\Production;

use App\Models\ProductionRecord;
use App\Models\ProductionSync;
use App\Models\User;
use App\Services\Google\GoogleSheetsException;
use App\Services\Google\SheetsReader;
use App\Support\TextNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Synchronisation Google Sheets → production_records.
 *
 * Garanties :
 *  - **idempotence** : l'identité d'une ligne est `source_key`, le SHA-256 de
 *    la clé métier normalisée (config `production.identity`). Rejouer la même
 *    feuille met à jour les lignes existantes au lieu d'en créer des doublons ;
 *  - **possession des champs** : seuls les champs possédés par la feuille
 *    (ProductionRecord::SPREADSHEET_FIELDS) sont écrits. Le numéro de téléphone
 *    saisi dans l'application n'est jamais touché ;
 *  - **non destructif** : une ligne absente d'une réponse n'est jamais
 *    supprimée (aucune politique de suppression de source en Phase 1) ;
 *  - **traçable** : chaque tentative crée un ProductionSync (succès comme
 *    échec) avec un résumé chiffré et des erreurs par ligne, sans données
 *    personnelles ni secrets.
 *
 * Les en-têtes sont reconnus via la correspondance explicite
 * `config('production.mapping')`. Si un champ obligatoire n'a pas d'en-tête
 * correspondant, la synchronisation est refusée (ProductionMappingException)
 * au lieu de deviner une colonne.
 */
class ProductionSynchronizer
{
    /** Longueurs maximales acceptées par champ (alignées sur le schéma). */
    private const MAX_LENGTH = [
        'contract_reference' => 120,
        'client_name' => 190,
        'city' => 120,
        'product' => 190,
        'status' => 60,
    ];

    private const CHUNK_SIZE = 500;

    private const MAX_ROW_ERRORS = 250;

    public function __construct(private readonly SheetsReader $reader) {}

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
        $mapping = $this->mapping();
        $identityFields = $this->identity();
        $requiredFields = $this->required();

        $headerIndex = $this->locateHeaderRow($rows);
        $headerMap = $headerIndex === null ? [] : $this->mapHeaderRow($rows[$headerIndex]);

        $missing = array_values(array_filter(
            $requiredFields,
            static fn (string $field): bool => ! isset($headerMap[$field]),
        ));
        if ($missing !== []) {
            throw ProductionMappingException::missingHeaders($missing);
        }

        $dataRows = $headerIndex === null ? [] : array_slice($rows, $headerIndex + 1, null, true);

        $skipped = 0;
        $rowErrors = [];
        $valid = [];
        $seenKeys = [];

        foreach ($dataRows as $index => $row) {
            $sheetRow = (int) $index + 1; // numéro de ligne 1-indexé (informatif)

            if ($this->isEmptyRow($row)) {
                $skipped++;

                continue;
            }

            $values = [];
            foreach (ProductionRecord::SPREADSHEET_FIELDS as $field) {
                $values[$field] = $this->cell($row, $headerMap[$field] ?? null);
            }

            $errors = $this->validateRow($sheetRow, $values, $requiredFields);
            if ($errors !== []) {
                array_push($rowErrors, ...$errors);

                continue;
            }

            $key = $this->sourceKey($values, $identityFields);
            if (isset($seenKeys[$key])) {
                $rowErrors[] = [
                    'row' => $sheetRow,
                    'field' => implode(' + ', array_map('mb_strtoupper', $identityFields)),
                    'reason' => 'Clé métier identique à la ligne '.$seenKeys[$key].' de la feuille.',
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
                    ->get()
                    ->keyBy('source_key');

                foreach ($chunk as $item) {
                    $attributes = $this->spreadsheetAttributes($item['values'], $item['row']);
                    $record = $existing->get($item['key']);

                    if ($record !== null) {
                        // Mise à jour des seuls champs possédés par la feuille :
                        // `phone` et `phone_normalise` ne sont pas dans $attributes.
                        $record->update($attributes);
                        $updated++;
                    } else {
                        ProductionRecord::create($attributes + ['source_key' => $item['key']]);
                        $created++;
                    }
                }
            }
        });

        $failed = count(array_unique(array_column($rowErrors, 'row')));
        $rowsRead = count($dataRows);

        [$status, $summary] = $this->summarize($rowsRead, $created, $updated, $skipped, $failed, $rowErrors);

        $sync->update([
            'status' => $status,
            'rows_read' => $rowsRead,
            'created_count' => $created,
            'updated_count' => $updated,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'error_summary' => $summary,
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
        ]);

        return $sync->refresh();
    }

    /** @return array{0: string, 1: string} statut + résumé lisible */
    private function summarize(int $rowsRead, int $created, int $updated, int $skipped, int $failed, array $rowErrors): array
    {
        $parts = [];

        if ($created + $updated === 0) {
            $status = ProductionSync::STATUS_FAILED;
            $parts[] = $rowsRead === 0
                ? 'La plage configurée ne contient aucune ligne de données.'
                : 'Aucune ligne exploitable : toutes les lignes lues sont vides ou invalides.';
        } elseif ($failed > 0) {
            $status = ProductionSync::STATUS_PARTIAL;
            $parts[] = $failed.' ligne(s) rejetée(s) (détail ci-dessous).';
        } else {
            $status = ProductionSync::STATUS_COMPLETED;
            $parts[] = 'Synchronisation terminée sans erreur.';
        }

        if ($skipped > 0) {
            $parts[] = $skipped.' ligne(s) vide(s) ignorée(s).';
        }
        if (count($rowErrors) > self::MAX_ROW_ERRORS) {
            $parts[] = 'Seules les '.self::MAX_ROW_ERRORS.' premières erreurs de ligne sont conservées.';
        }

        return [$status, implode(' ', $parts)];
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
     * @param  list<string>  $requiredFields
     * @return list<array{row:int, field:string, reason:string}>
     */
    private function validateRow(int $sheetRow, array $values, array $requiredFields): array
    {
        $errors = [];

        foreach (ProductionRecord::SPREADSHEET_FIELDS as $field) {
            $value = $values[$field] ?? null;
            if ($value === null) {
                continue;
            }
            $max = self::MAX_LENGTH[$field] ?? 255;
            if (mb_strlen($value) > $max) {
                $errors[] = [
                    'row' => $sheetRow,
                    'field' => mb_strtoupper($field),
                    'reason' => 'Valeur trop longue ('.$max.' caractères maximum).',
                ];
            }
        }

        foreach ($requiredFields as $field) {
            if (($values[$field] ?? null) === null) {
                $errors[] = [
                    'row' => $sheetRow,
                    'field' => mb_strtoupper($field),
                    'reason' => 'Champ obligatoire vide.',
                ];
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, string|null>  $values
     * @param  list<string>  $identityFields
     */
    private function sourceKey(array $values, array $identityFields): string
    {
        $parts = [];
        foreach ($identityFields as $field) {
            $parts[] = $this->normalizeIdentityValue($values[$field] ?? null);
        }

        return hash('sha256', implode("\x1f", $parts));
    }

    /** Normalisation de la clé métier : espaces réduits, casse unifiée. */
    private function normalizeIdentityValue(?string $value): string
    {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Champs mis à jour par la synchronisation — jamais `phone`.
     *
     * @param  array<string, string|null>  $values
     * @return array<string, string|int|null>
     */
    private function spreadsheetAttributes(array $values, int $sheetRow): array
    {
        $attributes = [];
        foreach (ProductionRecord::SPREADSHEET_FIELDS as $field) {
            $attributes[$field] = $values[$field] ?? null;
        }
        $attributes['source_row'] = $sheetRow;
        $attributes['last_synced_at'] = now();

        return $attributes;
    }

    /** @param list<string|null> $row */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell === null) {
                continue;
            }
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param list<string|null> $row */
    private function cell(array $row, ?int $index): ?string
    {
        if ($index === null || ! array_key_exists($index, $row)) {
            return null;
        }
        $value = $row[$index];
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /** @param list<list<string|null>> $rows */
    private function locateHeaderRow(array $rows): ?int
    {
        $start = max(0, (int) config('production.header_row', 1) - 1);
        $count = count($rows);

        for ($i = $start; $i < $count; $i++) {
            if (! $this->isEmptyRow($rows[$i])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  list<string|null>  $headerRow
     * @return array<string, int> champ interne → index de colonne
     */
    private function mapHeaderRow(array $headerRow): array
    {
        $lookup = [];
        foreach ($this->mapping() as $field => $aliases) {
            foreach ((array) $aliases as $alias) {
                $lookup[TextNormalizer::header((string) $alias)] = $field;
            }
        }

        $map = [];
        foreach ($headerRow as $index => $cell) {
            if ($cell === null || trim((string) $cell) === '') {
                continue;
            }
            $field = $lookup[TextNormalizer::header((string) $cell)] ?? null;
            if ($field !== null && ! isset($map[$field])) {
                $map[$field] = $index;
            }
        }

        return $map;
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
            if (! in_array($field, ProductionRecord::SPREADSHEET_FIELDS, true)) {
                throw ProductionMappingException::invalidConfiguration('champ inconnu « '.$field.' » dans production.mapping.');
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
