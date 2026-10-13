<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\DuplicateExtraction;
use App\Models\ImportedFile;
use App\Models\ImportHistory;
use Illuminate\Http\JsonResponse;

/**
 * Statistiques agrégées du module contact (requêtes d'agrégation simples,
 * pas de scans lourds).
 */
class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $historyQuery = ImportHistory::query();
        $successImports = (clone $historyQuery)->whereIn('status', [ImportHistory::STATUS_COMPLETED, ImportHistory::STATUS_PARTIAL])->count();
        $failedImports = (clone $historyQuery)->where('status', ImportHistory::STATUS_FAILED)->count();

        $extracted = Contact::extracted()->count();
        $active = Contact::activeGlobally()->count();
        $totalContacts = Contact::count();

        $filesWithDuplicates = ImportedFile::notArchived()
            ->whereExists(function ($q) {
                $q->selectRaw(1)
                    ->from('contacts as c')
                    ->whereColumn('c.imported_file_id', 'imported_files.id')
                    ->whereExists(function ($qq) {
                        $qq->selectRaw(1)
                            ->from('contacts as c2')
                            ->whereColumn('c2.imported_file_id', 'c.imported_file_id')
                            ->whereColumn('c2.telephone_normalise', 'c.telephone_normalise')
                            ->whereColumn('c2.id', '!=', 'c.id')
                            ->whereNotNull('c.telephone_normalise');
                    });
            })
            ->count();

        $recentImports = ImportHistory::query()->with('user:id,name')->orderByDesc('id')->limit(5)->get()
            ->map(fn (ImportHistory $h) => [
                'id' => $h->id,
                'original_filename' => $h->original_filename,
                'status' => $h->status,
                'successful_rows' => $h->successful_rows,
                'total_rows' => $h->total_rows,
                'performed_by' => $h->user?->name,
                'created_at' => $h->created_at?->toISOString(),
            ]);

        $recentExtractions = DuplicateExtraction::query()->with(['sourceFile:id,original_filename', 'user:id,name'])
            ->orderByDesc('id')->limit(5)->get()
            ->map(fn (DuplicateExtraction $e) => [
                'id' => $e->id,
                'source_file' => $e->sourceFile?->original_filename,
                'status' => $e->status,
                'extracted_count' => $e->extracted_count,
                'performed_by' => $e->user?->name,
                'created_at' => $e->created_at?->toISOString(),
            ]);

        return response()->json([
            'data' => [
                'files_total' => ImportedFile::notArchived()->count(),
                'files_archived' => ImportedFile::onlyTrashed()->count(),
                'contacts_total' => $totalContacts,
                'contacts_active' => $active,
                'contacts_extracted' => $extracted,
                'import_attempts' => ImportHistory::count(),
                'imports_successful' => $successImports,
                'imports_failed' => $failedImports,
                'files_with_duplicates' => $filesWithDuplicates,
                'extractions_completed' => DuplicateExtraction::where('status', DuplicateExtraction::STATUS_COMPLETED)->count(),
                'extractions_restored' => DuplicateExtraction::where('status', DuplicateExtraction::STATUS_RESTORED)->count(),
                'recent_imports' => $recentImports,
                'recent_extractions' => $recentExtractions,
            ],
        ]);
    }
}
