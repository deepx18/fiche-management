<?php

namespace App\Http\Controllers;

use App\Models\ImportedFile;
use App\Services\DuplicateDetector;
use App\Services\DuplicateExtractor;
use App\Services\ExtractionValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Détection de doublons téléphoniques intra-fiche (lecture seule : un scan
 * ne modifie jamais rien) et extraction transactionnelle des contacts
 * sélectionnés après vérification complète côté serveur.
 */
class DuplicateController extends Controller
{
    public function index(Request $request, ImportedFile $file): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $result = app(DuplicateDetector::class)->detect($file, $perPage);

        $groups = $result['groups'];

        return response()->json([
            'data' => collect($groups->items())->map(fn (array $group) => $group),
            'stats' => $result['stats'],
            'meta' => ['current_page' => $groups->currentPage(), 'last_page' => $groups->lastPage(), 'total' => $groups->total(), 'per_page' => $groups->perPage()],
        ]);
    }

    public function extract(Request $request, ImportedFile $file): JsonResponse
    {
        $data = $request->validate([
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        try {
            ['extraction' => $extraction, 'remaining_active' => $remaining] = app(DuplicateExtractor::class)
                ->extract($file, $request->user(), $data['contact_ids']);
        } catch (ExtractionValidationException $e) {
            return response()->json([
                'message' => 'Extraction refusée.',
                'errors' => $e->errors,
            ], 422);
        }

        return response()->json([
            'data' => [
                'extraction' => [
                    'id' => $extraction->id,
                    'source_file_id' => $extraction->source_file_id,
                    'status' => $extraction->status,
                    'extracted_count' => $extraction->extracted_count,
                    'performed_by' => $extraction->user?->name,
                    'created_at' => $extraction->created_at?->toISOString(),
                    'contacts' => $extraction->items->map(fn ($item) => [
                        'id' => $item->contact->id,
                        'nom' => $item->contact->nom,
                        'prenom' => $item->contact->prenom,
                        'tel' => $item->contact->tel,
                    ]),
                ],
                'remaining_active_contacts' => $remaining,
            ],
        ], 201);
    }
}
