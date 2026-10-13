<?php

namespace Tests;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Fabrique de fichiers Excel de test (vrais XLSX/XLS générés avec
 * PhpSpreadsheet). Les cellules marquées avec un préfixe "s:" sont écrites
 * explicitement comme texte — indispensable pour préserver les zéros
 * initiaux ("06801", "06 12 34 56 78") dans la fixture.
 */
trait CreatesXlsx
{
    private function writeXlsx(array $rows, string $filename = 'fiche-contact.xlsx'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                if (is_string($value) && str_starts_with($value, 's:')) {
                    $sheet->getCell([$c + 1, $r + 1])->setValueExplicit(substr($value, 2), DataType::TYPE_STRING);
                } else {
                    $sheet->getCell([$c + 1, $r + 1])->setValue($value);
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx_');
        ($writer = new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        // Renommage pour exposer un nom d'origine crédible dans les tests.
        copy($path, $renamed = sys_get_temp_dir().DIRECTORY_SEPARATOR.$filename);
        unlink($path);

        return $renamed;
    }

    private function writeXls(array $rows, string $filename = 'fiche-contact.xls'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                if (is_string($value) && str_starts_with($value, 's:')) {
                    $sheet->getCell([$c + 1, $r + 1])->setValueExplicit(substr($value, 2), DataType::TYPE_STRING);
                } else {
                    $sheet->getCell([$c + 1, $r + 1])->setValue($value);
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'xls_');
        (new XlsWriter($spreadsheet))->save($path);
        copy($path, $renamed = sys_get_temp_dir().DIRECTORY_SEPARATOR.$filename);
        unlink($path);
        $spreadsheet->disconnectWorksheets();

        return $renamed;
    }

    private function uploadedFrom(string $path, string $originalName = 'fiche-contact.xlsx', string $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
    {
        return new \Illuminate\Http\UploadedFile($path, $originalName, $mime, null, true);
    }

    private function headersRow(): array
    {
        return ['NOM', 'PRENOM', 'ADRESSE', 'CP', 'VILLE', 'TEL', 'Email', 'Date de naissance'];
    }
}
