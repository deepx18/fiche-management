<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ImportedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export Excel (vrai XLSX) des contacts, avec :
 *  - les en-têtes originaux dans l'ordre : NOM, PRENOM, ADRESSE, CP, VILLE,
 *    TEL, Email, Date de naissance ;
 *  - les chaînes écrites textuellement (zéros initiaux préservés) ;
 *  - les dates au format JJ/MM/AAAA ;
 *  - une protection anti-injection de formule (préfixe "'"), sans modifier les
 *    données stockées en base.
 */
class ContactExporter
{
    public const HEADERS = [
        'NOM', 'PRENOM', 'ADRESSE', 'CP', 'VILLE', 'TEL', 'Email', 'Date de naissance',
    ];

    /**
     * @param  iterable<Contact>  $contacts
     */
    public function export(iterable $contacts, string $filename): array
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([self::HEADERS], null, 'A1');
        $sheet->freezePane('A2');

        $row = 2;
        foreach ($contacts as $contact) {
            $values = [
                $contact->nom,
                $contact->prenom,
                $contact->adresse,
                $contact->cp,
                $contact->ville,
                $contact->tel,
                $contact->email,
                $contact->date_naissance ? $contact->date_naissance->translatedFormat('d/m/Y') : null,
            ];
            foreach ($values as $col => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $cell = $sheet->getCell([$col + 1, $row]);
                $cell->setValueExplicit($this->guardFormula($value), DataType::TYPE_STRING);
            }
            $row++;
        }

        $writer = new XlsxWriter($spreadsheet);
        $path = tempnam(sys_get_temp_dir(), 'xlsx_');
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return [$path, $filename];
    }

    /** Réponse de téléchargement streaming, puis fichier temporaire supprimé. */
    public function download(iterable $contacts, string $filename): StreamedResponse
    {
        [$path] = $this->export($contacts, $filename);

        return response()->streamDownload(function () use ($path) {
            $stream = fopen($path, 'rb');
            fpassthru($stream);
            fclose($stream);
            @unlink($path);
            @unlink($path);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Neutralise les valeurs interprétées comme formule par Excel/LibreOffice
     * en préfixant d'une apostrophe simple (affichée par nos soins, sans
     * altérer la donnée en base).
     */
    private function guardFormula(string $value): string
    {
        $first = mb_substr($value, 0, 1);

        return in_array($first, ['=', '+', '@'], true) ? "'".$value : $value;
    }

    /** Nom de fichier nettoyé, sans extension d'origine. */
    public static function cleanedFilename(ImportedFile $file): string
    {
        $base = pathinfo($file->original_filename, PATHINFO_FILENAME);
        $safe = preg_replace('/[^\p{L}\p{N}\-_ ]/u', '', $base) ?: 'fiche-contact';

        return $safe.'-cleaned-'.now()->format('Y-m-d-Hi').'.xlsx';
    }

    public static function extractedContactsFilename(): string
    {
        return 'contacts-extraits-'.now()->format('Y-m-d').'.xlsx';
    }

    /** Export "complet" d'une fiche : tous les contacts, extraits inclus. */
    public static function fileContactsFilename(ImportedFile $file, bool $cleaned): string
    {
        $base = pathinfo($file->original_filename, PATHINFO_FILENAME);
        $safe = preg_replace('/[^\p{L}\p{N}\-_ ]/u', '', $base) ?: 'fiche-contact';

        return $safe.($cleaned ? '-cleaned' : '-all').'-'.now()->format('Y-m-d-Hi').'.xlsx';
    }
}
