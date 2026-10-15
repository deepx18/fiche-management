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
    /** Colonnes triables autorisées pour l'historique d'import. */
    private const SORTABLE = [
        'created_at', 'original_filename', 'status', 'successful_rows', 'failed_rows', 'total_rows', 'id',
    ];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'imported_from' => ['nullable', 'date'],
            'imported_to' => ['nullable', 'date'],
            'failed_min' => ['nullable', 'integer', 'min:0'],
            'sort_by' => ['nullable', 'in:'.implode(',', self::SORTABLE)],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ]);

        $query = ImportHistory::query()->with(['importedFile:id,original_filename', 'user:id,name']);

        if ($search = trim((string) $request->query('search'))) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where('original_filename', 'like', $like);
        }
        if ($status = $request->query('status')) {
            if (in_array($status, [ImportHistory::STATUS_COMPLETED, ImportHistory::STATUS_PARTIAL, ImportHistory::STATUS_FAILED], true)) {
                $query->where('status', $status);
            }
        }
        if ($userId = $request->query('user_id')) {
            $query->where('user_id', (int) $userId);
        }
        if ($from = $request->query('imported_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('imported_to')) {
            $query->whereDate('created_at', '<=', $to);
        }
        if ($request->filled('failed_min')) {
            $query->where('failed_rows', '>=', (int) $request->query('failed_min'));
        }

        $sortBy = (string) $request->query('sort_by', 'created_at');
        $direction = (string) $request->query('sort_direction', 'desc');
        if (! in_array($sortBy, self::SORTABLE, true)) {
            $sortBy = 'created_at';
        }
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }
        if ($sortBy === 'created_at') {
            // L'historique est chronologique : tri secondaire par id.
            $query->orderBy('id', $direction);
        } else {
            $query->orderBy($sortBy, $direction)->orderByDesc('id');
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 10)));
        $rows = $query->paginate($perPage);

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
            'started_at' =>  $h->started_at,
            'completed_at' =>  $h->completed_at,
            'created_at' =>  $h->created_at,
        ];
    }
}
