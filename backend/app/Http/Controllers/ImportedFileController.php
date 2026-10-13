<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ImportedFile;
use App\Models\ImportHistory;
use App\Services\ContactExporter;
use App\Services\ContactImporter;
use App\Services\DuplicateFileException;
use App\Services\MissingHeadersException;
use App\Services\UnreadableFileException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Cycle de vie d'une fiche importée : liste, upload/import (multipart),
 * détail avec statistiques cohérentes (contacts actifs via le scope
 * centralisé), export complet, export nettoyé, téléchargement du fichier
 * original (stockage privé, autorisé) et archivage (soft delete : référentiel
 * de la suppression — contacts, historique d'import et d'extraction conservés).
 */
class ImportedFileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ImportedFile::query()->with('importer')->withTrashed();

        if ($search = trim((string) $request->query('search'))) {
            $query->where('original_filename', 'like', "%{$search}%");
        }

        $status = $request->query('status');
        if ($status === 'archived') {
            $query->whereNotNull('deleted_at');
        } elseif ($status === 'all') {
            // pas de filtre
        } elseif (in_array($status, [ImportedFile::STATUS_IMPORTED, ImportedFile::STATUS_PARTIAL, ImportedFile::STATUS_FAILED], true)) {
            $query->where('status', $status)->whereNull('deleted_at');
        } else {
            $query->whereNull('deleted_at');
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 10)));

        $files = $query->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($files->items())->map(fn (ImportedFile $f) => $this->present($f));

        return response()->json([
            'data' => $rows,
            'meta' => ['current_page' => $files->currentPage(), 'last_page' => $files->lastPage(), 'total' => $files->total(), 'per_page' => $files->perPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'], // 10 Mo
            'confirm_duplicate' => ['sometimes', 'boolean'],
        ]);

        $uploaded = $data['file'];

        try {
            ['file' => $file, 'history' => $history] = app(ContactImporter::class)
                ->import($uploaded, $request->user(), (bool) ($data['confirm_duplicate'] ?? false));
        } catch (DuplicateFileException $e) {
            return response()->json([
                'message' => 'Un fichier identique a déjà été importé. Confirmez-vous l\'import en dupliqué ?',
                'duplicate_of' => [
                    'id' => $e->existing->id,
                    'original_filename' => $e->existing->original_filename,
                    'imported_at' => $e->existing->created_at?->toISOString(),
                ],
                'history_id' => $e->history->id,
            ], 409);
        } catch (MissingHeadersException $e) {
            return response()->json([
                'message' => 'En-têtes obligatoires manquants : '.implode(', ', $e->missing).'.',
                'missing_headers' => $e->missing,
                'history_id' => $e->history->id,
            ], 422);
        } catch (UnreadableFileException $e) {
            return response()->json([
                'message' => 'Fichier illisible ou format non supporté (XLSX/XLS attendus).',
                'history_id' => $e->history->id,
            ], 422);
        }

        return response()->json([
            'data' => [
                'file' => $this->present($file->load('importer')),
                'history_id' => $history->id,
                'stats' => [
                    'total_rows' => $history->total_rows,
                    'successful_rows' => $history->successful_rows,
                    'failed_rows' => $history->failed_rows,
                    'skipped_rows' => $history->skipped_rows,
                    'status' => $history->status,
                ],
            ],
        ], 201);
    }

    public function show(ImportedFile $file): JsonResponse
    {
        $activeContacts = $file->contacts()->active();
        $counts = [
            'contacts_total' => (clone $activeContacts)->count(),
            'contacts_extracted' => Contact::where('imported_file_id', $file->id)->extracted()->count(),
            'last_import' => $file->importHistories()->latest('id')->first(),
        ];

        return response()->json([
            'data' => array_merge($this->present($file->load(['importer', 'importHistories'])), [
                'counts' => $counts,
            ]),
        ]);
    }

    public function contacts(Request $request, ImportedFile $file): JsonResponse
    {
        $query = $file->contacts()->active()->orderBy('id');

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                    ->orWhere('prenom', 'like', "%{$search}%")
                    ->orWhere('tel', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('ville', 'like', "%{$search}%");
            });
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 10)));
        $contacts = $query->paginate($perPage);

        return response()->json([
            'data' => collect($contacts->items())->map(fn (Contact $c) => $this->presentContact($c)),
            'meta' => ['current_page' => $contacts->currentPage(), 'last_page' => $contacts->lastPage(), 'total' => $contacts->total(), 'per_page' => $contacts->perPage()],
        ]);
    }

    /** Export de TOUS les contacts de la fiche (extraits inclus). */
    public function exportAll(ImportedFile $file): StreamedResponse
    {
        $contacts = $file->contacts()->orderBy('id')->get();

        return app(ContactExporter::class)->download(
            $contacts,
            ContactExporter::fileContactsFilename($file, cleaned: false),
        );
    }

    /** Export nettoyé : uniquement les contacts actifs (extraits exclus). */
    public function exportCleaned(ImportedFile $file): StreamedResponse
    {
        $contacts = $file->contacts()->active()->orderBy('id')->get();

        return app(ContactExporter::class)->download(
            $contacts,
            ContactExporter::fileContactsFilename($file, cleaned: true),
        );
    }

    /** Téléchargement de l'original conservé en stockage privé. */
    public function download(ImportedFile $file): BinaryFileResponse|\Illuminate\Http\JsonResponse
    {
        if (! $file->storage_path || ! Storage::disk('local')->exists($file->storage_path)) {
            return response()->json(['message' => 'Le fichier original n\'est plus disponible.'], 404);
        }

        return Storage::disk('local')->download($file->storage_path, $file->original_filename);
    }

    /** Archivage : le fichier disparaît des listes actives, l'audit reste. */
    public function destroy(ImportedFile $file): JsonResponse
    {
        $file->delete();

        return response()->json(['message' => 'Fiche archivée. Historique et extraction conservés.']);
    }

    private function present(ImportedFile $f): array
    {
        return [
            'id' => $f->id,
            'original_filename' => $f->original_filename,
            'file_size' => $f->file_size,
            'file_hash' => $f->file_hash,
            'status' => $f->status,
            'archived' => $f->deleted_at !== null,
            'imported_by' => ['id' => $f->importer?->id, 'name' => $f->importer?->name],
            'imported_at' => $f->created_at?->toISOString(),
            'total_rows' => $f->total_rows,
            'successful_rows' => $f->successful_rows,
            'failed_rows' => $f->failed_rows,
            'skipped_rows' => $f->skipped_rows,
            'original_available' => (bool) $f->storage_path && Storage::disk('local')->exists((string) $f->storage_path),
        ];
    }

    private function presentContact(Contact $c): array
    {
        return [
            'id' => $c->id,
            'imported_file_id' => $c->imported_file_id,
            'nom' => $c->nom,
            'prenom' => $c->prenom,
            'adresse' => $c->adresse,
            'cp' => $c->cp,
            'ville' => $c->ville,
            'tel' => $c->tel,
            'email' => $c->email,
            'date_naissance' => $c->date_naissance?->toDateString(),
        ];
    }
}
