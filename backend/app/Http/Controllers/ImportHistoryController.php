<?php

namespace App\Http\Controllers;

use App\Models\ImportHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Historique d'import : chaque tentative (réussie, partielle ou échouée) est
 * conservée indépendamment de l'existence du fichier importé.
 */
class ImportHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ImportHistory::query()->with(['importedFile:id,original_filename', 'user:id,name']);

        if ($search = trim((string) $request->query('search'))) {
            $query->where('original_filename', 'like', "%{$search}%");
        }
        if ($status = $request->query('status')) {
            if (in_array($status, [ImportHistory::STATUS_COMPLETED, ImportHistory::STATUS_PARTIAL, ImportHistory::STATUS_FAILED], true)) {
                $query->where('status', $status);
            }
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 10)));
        $rows = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => collect($rows->items())->map(fn (ImportHistory $h) => $this->present($h)),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'per_page' => $rows->perPage()],
        ]);
    }

    public function show(ImportHistory $history): JsonResponse
    {
        return response()->json(['data' => $this->present($history->load(['importedFile', 'user']))]);
    }

    private function present(ImportHistory $h): array
    {
        return [
            'id' => $h->id,
            'imported_file_id' => $h->imported_file_id,
            'imported_file_name' => $h->importedFile?->original_filename,
            'original_filename' => $h->original_filename,
            'user_name' => $h->user?->name,
            'status' => $h->status,
            'total_rows' => $h->total_rows,
            'successful_rows' => $h->successful_rows,
            'failed_rows' => $h->failed_rows,
            'skipped_rows' => $h->skipped_rows,
            'error_summary' => $h->error_summary,
            'row_errors' => $h->row_errors,
            // 'started_at' => $h->started_at?->toISOString(),
            // 'completed_at' => $h->completed_at?->toISOString(),
            // 'created_at' => $h->created_at?->toISOString(),
        ];
    }
}
