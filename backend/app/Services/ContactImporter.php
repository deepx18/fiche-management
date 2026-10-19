<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ImportHistory;
use App\Models\ImportedFile;
use App\Models\User;
use App\Support\PhoneNormalizer;
use App\Support\TextNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Import d'un fichier Excel "Fiche Contact".
 *
 * En-têtes attendus (variants tolérés au niveau de la casse, des espaces et
 * des accents) : NOM, PRENOM, ADRESSE, CP, VILLE, TEL, Email, Date de
 * naissance. NOM et PRENOM sont obligatoires ; les autres colonnes sont
 * optionnelles et ne sont jamais déduites de colonnes non reconnues.
 */
class ContactImporter
{
    /** Variants d'en-têtes acceptés (clé = colonne interne). */
    private const HEADER_ALIASES = [
        'nom' => ['nom'],
        'prenom' => ['prenom', "prénom"],
        'adresse' => ['adresse'],
        'cp' => ['cp', 'code postal'],
        'ville' => ['ville'],
        'tel' => ['tel', 'telephone', 'téléphone'],
        'email' => ['email', 'mail', 'e mail'],
        'date_naissance' => ['date de naissance', 'date de naissance', 'date naissance', 'date naissance'],
    ];

    private const REQUIRED_HEADERS = ['nom', 'prenom'];

    /**
     * Traite l'upload : enregistre la fiche, écrit l'historique d'import
     * (toujours, même en cas d'échec total), puis insère les lignes valides.
     *
     * @return array{file: ImportedFile, history: ImportHistory}
     */
    public function import(UploadedFile $uploaded, User $user, bool $confirmDuplicate = false): array
    {
        $original = $uploaded->getClientOriginalName() ?: 'fiche-contact.xlsx';
        $hash = hash_file('sha256', $uploaded->getRealPath());
        $startedAt = now();

        $history = ImportHistory::create([
            'imported_file_id' => null,
            'user_id' => $user->id,
            'original_filename' => $original,
            'status' => ImportHistory::STATUS_FAILED,
            'started_at' => $startedAt,
        ]);

        // Vérification du type réel (magic bytes), pas du simple nom. Les
        // uploads refusés laissent tout de même une trace d'import échoué.
        $allowed = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
            'application/vnd.ms-excel', // xls
            'application/octet-stream',
            'application/zip', // xlsx mal nommés mais valides
        ];
        if (! in_array($uploaded->getMimeType(), $allowed, true)) {
            $history->update([
                'completed_at' => now(),
                'error_summary' => 'Type de fichier non supporté (XLSX ou XLS attendus).',
            ]);
            throw new UnreadableFileException($history);
        }

        // Fichier identique déjà importé : décision explicite requise.
        $duplicateOf = ImportedFile::notArchived()->where('file_hash', $hash)->first();
        if ($duplicateOf && ! $confirmDuplicate) {
            $history->update([
                'error_summary' => 'Fichier identique déjà importé le '
                    .$duplicateOf->created_at->format('d/m/Y H:i').'. Import refusé sans confirmation explicite.',
            ]);

            throw new DuplicateFileException($duplicateOf, $history);
        }

        // Lecture de la feuille (première feuille).
        try {
            $sheets = Excel::toArray(null, $uploaded, null, \Maatwebsite\Excel\Excel::XLSX);
        } catch (\Throwable $e) {
            $sheets = null;
        }
        if ($sheets === null) {
            // Essai XLS (ancien format) si le XLSX échoue.
            try {
                $sheets = Excel::toArray(null, $uploaded, null, 'Xls');
            } catch (\Throwable $e) {
                $sheets = null;
            }
        }
        if (! is_array($sheets) || count($sheets) === 0) {
            $history->update([
                'completed_at' => now(),
                'error_summary' => 'Fichier illisible ou format non supporté (XLSX/XLS attendus).',
            ]);
            throw new UnreadableFileException($history);
        }

        $rows = $sheets[0];

        // Séparation en-tête / data : la première ligne non vide est l'en-tête.
        $headerRowIndex = null;
        foreach ($rows as $i => $row) {
            if ($this->isNonEmptyRow($row)) {
                $headerRowIndex = $i;
                break;
            }
        }

        $dataRows = [];
        $skipped = 0;
        if ($headerRowIndex === null) {
            $errors = [];
            $mapped = [];
        } else {
            [$mapped, $missing] = $this->mapHeaderRow($rows[$headerRowIndex]);
            foreach (array_slice($rows, $headerRowIndex + 1) as $rowIdx => $row) {
                $excelRow = $headerRowIndex + $rowIdx + 2; // numéro de ligne Excel (1-based + en-tête)
                if (! $this->isNonEmptyRow($row)) {
                    $skipped++;
                    continue;
                }
                $dataRows[] = [$excelRow, $row];
            }
        }

        if (isset($missing) && $missing !== []) {
            $history->update([
                'completed_at' => now(),
                'error_summary' => 'En-têtes obligatoires manquants : '.implode(', ', $missing).'. En-têtes requis : NOM, PRENOM.',
            ]);
            throw new MissingHeadersException($history, $missing);
        }

        // Validation ligne à ligne.
        $valid = [];
        $rowErrors = [];
        foreach ($dataRows as [$excelRow, $row]) {
            $rowError = $this->validateRow($excelRow, $row, $mapped);
            if ($rowError === null) {
                $valid[] = [$excelRow, $row];
            } else {
                foreach ($rowError as $err) {
                    $rowErrors[] = $err;
                }
            }
        }

        // Enregistrement de la fiche + insertion des contacts, transactionnel.
        $file = DB::transaction(function () use ($uploaded, $original, $hash, $user, $mapped, $valid, $dataRows, $rowErrors, $skipped, $history, $duplicateOf) {
            $storagePath = $uploaded->storeAs('fiches', uniqid('fc_', true).'-'.$original, 'local');

            $file = ImportedFile::create([
                'original_filename' => $original,
                'storage_path' => $storagePath,
                'file_size' => (int) $uploaded->getSize(),
                'file_hash' => $hash,
                'status' => $this->resolveFileStatus(count($dataRows), count($valid), count($rowErrors)),
                'imported_by' => $user->id,
                'total_rows' => count($dataRows) + $skipped, // lignes tentées, vides incluses
                'successful_rows' => count($valid),
                'failed_rows' => count($rowErrors) > 0 ? $this->countRowsWithErrors($rowErrors) : 0,
                'skipped_rows' => $skipped,
            ]);

            foreach (array_chunk($valid, 500) as $chunk) {
                $insert = [];
                foreach ($chunk as [$excelRow, $row]) {
                    $insert[] = $this->contactAttributes($row, $mapped, $file->id);
                }
                DB::table('contacts')->insert($insert);
            }

            $history->update([
                'imported_file_id' => $file->id,
                'status' => $this->resolveHistoryStatus(count($dataRows), count($valid), count($rowErrors)),
                'total_rows' => count($dataRows) + $skipped, // lignes tentées, vides incluses
                'successful_rows' => count($valid),
                'failed_rows' => count($rowErrors) > 0 ? $this->countRowsWithErrors($rowErrors) : 0,
                'skipped_rows' => $skipped,
                'error_summary' => $this->buildSummary($rowErrors, $skipped, $duplicateOf),
                'row_errors' => array_slice($rowErrors, 0, 250),
                'completed_at' => now(),
            ]);

            return $file;
        });

        return ['file' => $file, 'history' => $history->refresh()];
    }

    private function isNonEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (is_array($cell)) {
                $cell = $cell['value'] ?? null;
            }
            if ($cell !== null && trim((string) $cell) !== '') {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: array<string, int>, 1: string[]} mapping + en-têtes manquants */
    private function mapHeaderRow(array $headerRow): array
    {
        $mapped = [];
        $seen = [];
        foreach ($headerRow as $i => $cell) {
            if (is_array($cell)) {
                $cell = $cell['value'] ?? null;
            }
            $key = self::normalizeHeader((string) $cell);
            if ($key === '') {
                continue;
            }
            foreach (self::HEADER_ALIASES as $field => $aliases) {
                if (in_array($key, $aliases, true) && ! isset($seen[$field])) {
                    $mapped[$field] = $i;
                    $seen[$field] = true;
                    break;
                }
            }
        }

        $missing = [];
        foreach (self::REQUIRED_HEADERS as $required) {
            if (! isset($mapped[$required])) {
                $missing[] = mb_strtoupper($required);
            }
        }

        return [$mapped, $missing];
    }

    /**
     * Normalise un en-tête : minuscules, accents retirés, espaces réduits.
     */
    public static function normalizeHeader(string $header): string
    {
        return TextNormalizer::header($header);
    }

    /**
     * @return list<array{row:int, field:string, reason:string}>|null null = ligne valide
     */
    private function validateRow(int $excelRow, array $row, array $mapped): ?array
    {
        $errors = [];
        foreach (self::REQUIRED_HEADERS as $field) {
            if (! isset($mapped[$field]) || $this->cell($row, $mapped[$field]) === null) {
                $errors[] = ['row' => $excelRow, 'field' => mb_strtoupper($field), 'reason' => 'Champ obligatoire vide.'];
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        foreach (['nom', 'prenom', 'adresse', 'cp', 'ville', 'tel', 'email'] as $field) {
            $value = $this->cell($row, $mapped[$field] ?? null, 255);
            if ($value !== null && mb_strlen($value) > (
                $field === 'adresse' ? 500 :
                ($field === 'cp' ? 20 : ($field === 'tel' ? 30 : 255)))
            ) {
                $errors[] = ['row' => $excelRow, 'field' => mb_strtoupper($field), 'reason' => 'Valeur trop longue.'];
            }
        }

        if (isset($mapped['email']) && ($email = $this->cell($row, $mapped['email'])) !== null) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = ['row' => $excelRow, 'field' => 'EMAIL', 'reason' => 'Adresse email invalide.'];
            }
        }

        if (isset($mapped['date_naissance']) && ($rawDate = $this->cell($row, $mapped['date_naissance'])) !== null) {
            if (self::parseDate($rawDate) === null) {
                $errors[] = ['row' => $excelRow, 'field' => 'DATE DE NAISSANCE', 'reason' => 'Date non reconnue.'];
            }
        }

        return $errors === [] ? null : $errors;
    }

    private function contactAttributes(array $row, array $mapped, int $fileId): array
    {
        $tel = $this->cell($row, $mapped['tel'] ?? null);
        $email = $this->cell($row, $mapped['email'] ?? null);
        $rawDate = $this->cell($row, $mapped['date_naissance'] ?? null);

        return [
            'imported_file_id' => $fileId,
            'nom' => $this->cell($row, $mapped['nom'], 255) ?? '',
            'prenom' => $this->cell($row, $mapped['prenom'], 255) ?? '',
            'adresse' => $this->cell($row, $mapped['adresse'] ?? null, 500),
            'cp' => $this->cell($row, $mapped['cp'] ?? null, 20),
            'ville' => $this->cell($row, $mapped['ville'] ?? null, 255),
            'tel' => $tel,
            'telephone_normalise' => $tel === null ? null : PhoneNormalizer::normalize($tel),
            'email' => $email, // les adresses invalides ont déjà été rejetées par la validation
            'date_naissance' => $rawDate === null ? null : self::parseDate($rawDate)?->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** Valeur de cellule convertie en chaîne (null si vide). Zéros préservés. */
    private function cell(array $row, ?int $index, ?int $limit = null): ?string
    {
        if ($index === null || ! array_key_exists($index, $row)) {
            return null;
        }
        $value = $row[$index];
        if (is_array($value)) {
            $value = $value['value'] ?? null;
        }
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return null;
        }
        $string = is_string($value) ? trim($value) : trim((string) $value);
        if ($string === '') {
            return null;
        }

        return $limit === null ? $string : mb_substr($string, 0, $limit);
    }

    /**
     * Dates : accepte les séries Excel (numériques), DateTimeImmutable et des
     * chaînes dans un jeu de formats courant. Renvoie null si la date est
     * manquante ou invalide (aucune valeur trompeuse n'est produite).
     */
    public static function parseDate(mixed $raw): ?CarbonImmutable
    {
        if ($raw === null) {
            return null;
        }
        if ($raw instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($raw);
        }
        if (is_string($raw) && preg_match('/^[0-9]+\.?[0-9]*$/', $raw)) {
            // Excel peut retourner une valeur numérique sous forme de chaîne.
            $numeric = (float) $raw;
            if (is_int($numeric) || floor($numeric) == $numeric) {
                try {
                    return CarbonImmutable::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((int) $numeric));
                } catch (\Throwable) {
                    // non fatal, on continue vers la validation par formats
                }
            }
        }
        if (is_int($raw) || is_float($raw)) {
            try {
                return CarbonImmutable::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($raw));
            } catch (\Throwable) {
                return null;
            }
        }
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        foreach (['m/d/Y', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'Y/m/d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value);
            } catch (\Throwable) {
                $date = false; // 31/02/1990 déclenche InvalidFormatException : invalide
            }
            if ($date !== false && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }

    private function resolveFileStatus(int $total, int $valid, int $failedRowsCount): string
    {
        if ($total === 0 || ($valid === 0 && $failedRowsCount === 0)) {
            return ImportedFile::STATUS_FAILED;
        }

        return $failedRowsCount > 0 ? ImportedFile::STATUS_PARTIAL : ImportedFile::STATUS_IMPORTED;
    }

    private function resolveHistoryStatus(int $total, int $valid, int $failedRowsCount): string
    {
        if ($total === 0 || ($valid === 0 && $failedRowsCount === 0)) {
            return ImportHistory::STATUS_FAILED;
        }

        return $failedRowsCount > 0 ? ImportHistory::STATUS_PARTIAL : ImportHistory::STATUS_COMPLETED;
    }

    /** Nombre de lignes distinctes (une ligne peut cumuler plusieurs erreurs). */
    private function countRowsWithErrors(array $rowErrors): int
    {
        return count(array_unique(array_column($rowErrors, 'row')));
    }

    private function buildSummary(array $rowErrors, int $skipped, ?ImportedFile $duplicateOf): string
    {
        $parts = [];
        $count = $this->countRowsWithErrors($rowErrors);
        if ($count > 0) {
            $parts[] = "$count ligne(s) invalide(s), ignorée(s) à l'import (détail dans les erreurs de lignes).";
        }
        if ($skipped > 0) {
            $parts[] = "$skipped ligne(s) vide(s) ignorée(s).";
        }
        if ($rowErrors === [] && $skipped === 0) {
            $parts[] = 'Toutes les lignes ont été importées sans erreur.';
        }
        if ($duplicateOf !== null) {
            $parts[] = 'Fichier identique au fichier #'.$duplicateOf->id.
                ' ("'.$duplicateOf->original_filename.'") — import confirmé explicitement par l\'administrateur.';
        }

        return implode(' ', $parts);
    }
}


