<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Services\ContactExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contacts, tous fichiers confondus (actifs uniquement, fiches non archivées).
 * La recherche filtre par nom, téléphone, email ou ville.
 */
class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Contact::activeGlobally()->with('importedFile:id,original_filename')->orderBy('id');

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                    ->orWhere('prenom', 'like', "%{$search}%")
                    ->orWhere('tel', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('ville', 'like', "%{$search}%");
            });
        }
        if ($fileId = $request->query('imported_file_id')) {
            $query->where('imported_file_id', (int) $fileId);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 10)));
        $contacts = $query->paginate($perPage);

        return response()->json([
            'data' => collect($contacts->items())->map(fn (Contact $c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'prenom' => $c->prenom,
                'adresse' => $c->adresse,
                'cp' => $c->cp,
                'ville' => $c->ville,
                'tel' => $c->tel,
                'email' => $c->email,
                'date_naissance' => $c->date_naissance?->toDateString(),
                'source_file' => ['id' => $c->importedFile?->id, 'original_filename' => $c->importedFile?->original_filename],
            ]),
            'meta' => ['current_page' => $contacts->currentPage(), 'last_page' => $contacts->lastPage(), 'total' => $contacts->total(), 'per_page' => $contacts->perPage()],
        ]);
    }

    /** Export Excel de tous les contacts actifs (fiches non archivées). */
    public function export(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $contacts = Contact::activeGlobally()->orderBy('id')->get();

        return app(ContactExporter::class)->download(
            $contacts,
            'contacts-'.now()->format('Y-m-d-Hi').'.xlsx',
        );
    }
}
