<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\DuplicateExtraction;
use App\Services\ContactExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Historique des extractions : chaque opération est auditée (fiche source,
 * administrateur, date, nombre de contacts, statut). L'export d'une
 * extraction renvoie les contacts associés (items si complétée, snapshot
 * d'identifiants si restaurée). La restauration rend les contacts actifs de
 * nouveau dans leur fiche d'origine sans les recréer.
 */
class ExtractionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DuplicateExtraction::query()
            ->with(['sourceFile:id,original_filename,deleted_at', 'user:id,name']);

        if ($sourceFile = $request->query('source_file_id')) {
            $query->where('source_file_id', (int) $sourceFile);
        }
        if ($status = $request->query('status')) {
            if (in_array($status, [DuplicateExtraction::STATUS_COMPLETED, DuplicateExtraction::STATUS_RESTORED], true)) {
                $query->where('status', $status);
            }
        }
        if ($adminId = $request->query('user_id')) {
            $query->where('user_id', (int) $adminId);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 10)));
        $rows = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => collect($rows->items())->map(fn (DuplicateExtraction $e) => $this->present($e)),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'per_page' => $rows->perPage()],
        ]);
    }

    public function show(DuplicateExtraction $extraction): JsonResponse
    {
        $extraction->load(['sourceFile', 'user', 'restorations.user:id,name']);

        $contacts = $extraction->items->isNotEmpty()
            ? $extraction->items->map(fn ($item) => $item->contact)
            : $extraction->restorations->flatMap(fn ($r) => Contact::whereIn('id', $r->restored_contact_ids ?? [])->get()); // opération restaurée : snapshot

        return response()->json([
            'data' => array_merge($this->present($extraction), [
                'source_file' => [
                    'id' => $extraction->sourceFile?->id,
                    'original_filename' => $extraction->sourceFile?->original_filename,
                    'archived' => $extraction->sourceFile?->deleted_at !== null,
                ],
                'performed_by' => $extraction->user?->name,
                'extracted_contacts' => collect($contacts)->filter()->map(fn (Contact $c) => [
                    'id' => $c->id,
                    'nom' => $c->nom,
                    'prenom' => $c->prenom,
                    'adresse' => $c->adresse,
                    'cp' => $c->cp,
                    'ville' => $c->ville,
                    'tel' => $c->tel,
                    'email' => $c->email,
                    'date_naissance' => $c->date_naissance?->toDateString(),
                ])->all(),
                'restorations' => $extraction->restorations->map(fn ($r) => [
                    'id' => $r->id,
                    'restored_count' => $r->restored_count,
                    'performed_by' => $r->user?->name,
                    'restored_at' => $r->created_at?->toISOString(),
                ])->all(),
                'can_restore' => $extraction->status === DuplicateExtraction::STATUS_COMPLETED,
            ]),
        ]);
    }

    public function export(DuplicateExtraction $extraction): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $contacts = collect();

        if ($extraction->items->isNotEmpty()) {
            $contacts = $extraction->items->map(fn ($item) => $item->contact)->filter();
        } else {
            foreach ($extraction->restorations as $restoration) {
                if (! empty($restoration->restored_contact_ids)) {
                    $contacts = $contacts->merge(
                        Contact::whereIn('id', $restoration->restored_contact_ids)->orderBy('id')->get()
                    );
                }
            }
        }

        return app(ContactExporter::class)->download($contacts, ContactExporter::extractedContactsFilename());
    }

    public function restore(Request $request, DuplicateExtraction $extraction): JsonResponse
    {
        try {
            $restoration = app(\App\Services\DuplicateExtractor::class)->restore($extraction, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'restoration_id' => $restoration->id,
                'restored_count' => $restoration->restored_count,
                'extraction_status' => $extraction->fresh()->status,
            ],
        ]);
    }

    private function present(DuplicateExtraction $e): array
    {
        return [
            'id' => $e->id,
            'source_file_id' => $e->source_file_id,
            'source_file_name' => $e->sourceFile?->original_filename,
            'status' => $e->status,
            'extracted_count' => $e->extracted_count,
            'comment' => $e->comment,
            'performed_by' => $e->user?->name,
            'created_at' => $e->created_at?->toISOString(),
        ];
    }
}
