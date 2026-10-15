<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Services\ContactExporter;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Recherche globale de contacts à travers toutes les fiches importées.
 *
 * Le filtrage est intégralement réalisé en base (jamais en chargeant tout le
 * jeu de contacts côté serveur puis en filtrant en PHP) :
 *   1. requête de base : contacts des fiches non archivées ;
 *   2. portée de statut (actifs par défaut, extraits sur demande explicite,
 *      ou les deux) ;
 *   3. recherche texte groupée (OR) sur les colonnes utiles ;
 *   4. filtres combinés en AND (fiche, ville, CP, plages de dates) ;
 *   5. tri validé par liste blanche ;
 *   6. pagination.
 *
 * L'export applique exactement les mêmes règles afin que le fichier corresponde
 * à l'intégralité des résultats filtrés (pas seulement la page courante).
 */
class ContactSearchController extends Controller
{
    /** Colonnes triables autorisées (jamais de colonne fournie librement en SQL). */
    private const SORTABLE = [
        'nom', 'prenom', 'adresse', 'cp', 'ville', 'tel', 'email', 'date_naissance', 'created_at', 'id',
    ];

    public function index(Request $request): JsonResponse
    {
        $params = $this->validated($request);

        $query = $this->buildQuery($params)
            ->with(['importedFile:id,original_filename,deleted_at', 'extractionItems.extraction:id,status,created_at']);

        $sortBy = $params['sort_by'] ?? 'id';
        $direction = $params['sort_direction'] ?? 'asc';
        $this->applySort($query, $sortBy, $direction);

        $perPage = $this->perPage($request);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (Contact $c) => $this->present($c)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    /** Export Excel de TOUS les contacts correspondant aux filtres (toutes pages). */
    public function export(Request $request): StreamedResponse
    {
        $params = $this->validated($request);

        $contacts = $this->buildQuery($params)
            ->orderBy('imported_file_id')
            ->orderBy('id')
            ->get();

        return app(ContactExporter::class)->download(
            $contacts,
            'recherche-contacts-'.now()->format('Y-m-d-Hi').'.xlsx',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'file_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:active,extracted,all'],
            'ville' => ['nullable', 'string', 'max:255'],
            'cp' => ['nullable', 'string', 'max:20'],
            'imported_from' => ['nullable', 'date'],
            'imported_to' => ['nullable', 'date'],
            'extracted_from' => ['nullable', 'date'],
            'extracted_to' => ['nullable', 'date'],
            'sort_by' => ['nullable', 'in:'.implode(',', self::SORTABLE)],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ]);
    }

    /**
     * Construit la requête filtrée, partagée entre la liste et l'export.
     *
     * @param  array<string, mixed>  $p
     */
    private function buildQuery(array $p): Builder
    {
        $status = $p['status'] ?? 'active';

        $query = Contact::query()
            ->whereHas('importedFile', fn (Builder $q) => $q->whereNull($q->qualifyColumn('deleted_at')));

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'extracted') {
            $query->extracted();
        }

        $this->applyTextSearch($query, (string) ($p['q'] ?? ''));

        if (! empty($p['file_id'])) {
            $query->where('imported_file_id', (int) $p['file_id']);
        }
        if (! empty($p['ville'])) {
            $query->where('ville', 'like', $this->like($p['ville']));
        }
        if (! empty($p['cp'])) {
            $query->where('cp', 'like', $this->like($p['cp']));
        }

        // Plage sur la date d'import de la fiche source.
        if (! empty($p['imported_from']) || ! empty($p['imported_to'])) {
            $from = $p['imported_from'] ?? null;
            $to = $p['imported_to'] ?? null;
            $query->whereHas('importedFile', function (Builder $q) use ($from, $to) {
                if ($from) {
                    $q->whereDate('created_at', '>=', $from);
                }
                if ($to) {
                    $q->whereDate('created_at', '<=', $to);
                }
            });
        }

        // Plage sur la date d'extraction (contacts extraits uniquement).
        if (! empty($p['extracted_from']) || ! empty($p['extracted_to'])) {
            $from = $p['extracted_from'] ?? null;
            $to = $p['extracted_to'] ?? null;
            $query->whereExists(function ($sub) use ($from, $to) {
                $sub->selectRaw(1)
                    ->from('duplicate_extraction_items as dei')
                    ->join('duplicate_extractions as de', 'de.id', '=', 'dei.duplicate_extraction_id')
                    ->whereColumn('dei.contact_id', 'contacts.id')
                    ->where('de.status', 'completed');
                if ($from) {
                    $sub->whereDate('de.created_at', '>=', $from);
                }
                if ($to) {
                    $sub->whereDate('de.created_at', '<=', $to);
                }
            });
        }

        return $query;
    }

    /** Recherche texte multicolonne groupée (OR), insensible à la casse côté SQL. */
    private function applyTextSearch(Builder $query, string $q): void
    {
        $q = trim($q);
        if ($q === '') {
            return;
        }

        $like = $this->like($q);
        $normalized = PhoneNormalizer::normalize($q);

        $query->where(function (Builder $sub) use ($like, $normalized) {
            $sub->where('nom', 'like', $like)
                ->orWhere('prenom', 'like', $like)
                ->orWhere('adresse', 'like', $like)
                ->orWhere('cp', 'like', $like)
                ->orWhere('ville', 'like', $like)
                ->orWhere('tel', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('date_naissance', 'like', $like)
                ->orWhereHas('importedFile', fn (Builder $file) => $file->where('original_filename', 'like', $like));

            // Téléphone saisi avec un formatage différent : la valeur d'origine
            // n'est jamais modifiée, on compare la forme normalisée.
            if ($normalized !== null) {
                $sub->orWhere('telephone_normalise', 'like', '%'.$normalized.'%');
            }
        });
    }

    private function applySort(Builder $query, string $sortBy, string $direction): void
    {
        $query->orderBy($sortBy, $direction);

        // Tri secondaire déterministe (jamais de résultat instable entre pages).
        if ($sortBy !== 'id') {
            $query->orderBy('id');
        }
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->query('per_page', 25)));
    }

    /** Échappe les jokers LIKE pour une recherche partielle littérale. */
    private function like(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($value)).'%';
    }

    private function present(Contact $c): array
    {
        $extracted = $c->extractionItems
            ->contains(fn ($item) => $item->extraction?->status === 'completed');

        $completedAt = $c->extractionItems
            ->map(fn ($item) => $item->extraction)
            ->filter(fn ($e) => $e && $e->status === 'completed')
            ->max('created_at');

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
            'status' => $extracted ? 'extracted' : 'active',
            'extracted_at' => $completedAt?->toISOString(),
            'source_file' => [
                'id' => $c->importedFile?->id,
                'original_filename' => $c->importedFile?->original_filename,
            ],
        ];
    }
}
