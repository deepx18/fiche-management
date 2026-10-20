<?php

namespace App\Http\Controllers;

use App\Models\ProductionRecord;
use App\Models\ProductionSync;
use App\Rules\ProductionPhone;
use App\Services\Google\GoogleSheetsException;
use App\Services\Production\ProductionMappingException;
use App\Services\Production\ProductionSynchronizer;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Table « Production ».
 *
 *  - `index`  : liste filtrée/triée/paginée des lignes synchronisées, avec la
 *    dernière synchronisation et les valeurs de filtre réellement présentes ;
 *  - `sync`   : lance une synchronisation Google Sheets (idempotente) ;
 *  - `syncs`  : historique court des synchronisations (succès et échecs) ;
 *  - `updatePhone` : ajout/modification du numéro client (champ possédé par
 *    l'application, jamais écrasé par la synchronisation).
 *
 * L'API expose les noms canoniques de la feuille (`nom`, `prenom`,
 * `prime_annuelle`, `produit`) tandis que les colonnes physiques historiques
 * (`client_name`, `first_name`, `premium_annual`, `product`) restent la
 * compatibilité de la base et de la téléphonie. Le mapping unique se trouve
 * dans App\Models\ProductionRecord::SHEET_FIELDS.
 *
 * Le classeur et la plage ne sont jamais fournis par le client : ils viennent
 * exclusivement de la configuration serveur (config/production.php).
 */
class ProductionController extends Controller
{
    /** Champ d'API triable → colonne physique. */
    private const SORTABLE = [
        'id' => 'id',
        'reference_contrat' => 'reference_contrat',
        'date' => 'date',
        'cial' => 'cial',
        'nom' => 'client_name',
        'prenom' => 'first_name',
        'cie' => 'cie',
        'produit' => 'product',
        'date_effet' => 'date_effet',
        'validation' => 'validation',
        'type_signature' => 'type_signature',
        'last_synced_at' => 'last_synced_at',
        'phone' => 'phone',
    ];

    /** Filtre d'API → colonne physique. */
    private const FILTERABLE = [
        'cie' => 'cie',
        'produit' => 'product',
        'validation' => 'validation',
        'type_signature' => 'type_signature',
    ];

    private const FILTER_OPTIONS_LIMIT = 200;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'has_phone' => ['nullable', 'in:all,yes,no'],
            'sort_by' => ['nullable', Rule::in(array_keys(self::SORTABLE))],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'cie' => ['nullable', 'string', 'max:190'],
            'produit' => ['nullable', 'string', 'max:190'],
            'validation' => ['nullable', 'string', 'max:190'],
            'type_signature' => ['nullable', 'string', 'max:300'],
        ]);

        $query = ProductionRecord::query();

        if ($search = trim((string) $request->query('search'))) {
            $like = '%'.$this->escapeLike($search).'%';
            $normalizedPhone = PhoneNormalizer::normalize($search);
            $query->where(function ($q) use ($like, $normalizedPhone) {
                $q->where('reference_contrat', 'like', $like)
                    ->orWhere('client_name', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('cial', 'like', $like)
                    ->orWhere('cie', 'like', $like)
                    ->orWhere('product', 'like', $like)
                    ->orWhere('validation', 'like', $like)
                    ->orWhere('phone', 'like', $like);
                if ($normalizedPhone !== null) {
                    $q->orWhere('phone_normalise', 'like', '%'.$normalizedPhone.'%');
                }
            });
        }

        foreach (self::FILTERABLE as $parameter => $column) {
            $value = trim((string) $request->query($parameter));
            if ($value !== '') {
                $query->where($column, $value);
            }
        }

        match ((string) $request->query('has_phone', 'all')) {
            'yes' => $query->whereNotNull('phone'),
            'no' => $query->whereNull('phone'),
            default => null,
        };

        $sortBy = (string) $request->query('sort_by', 'id');
        $column = self::SORTABLE[$sortBy] ?? 'id';
        $direction = (string) $request->query('sort_direction', 'asc');
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }
        $query->orderBy($column, $direction);
        if ($column !== 'id') {
            $query->orderBy('id');
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $records = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => collect($records->items())->map(fn (ProductionRecord $r) => $this->present($r)),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'total' => $records->total(),
                'per_page' => $records->perPage(),
            ],
            'last_sync' => $this->presentSync(
                ProductionSync::query()->with('user')->latest('id')->first()
            ),
            'filters' => [
                'cies' => $this->distinctValues('cie'),
                'produits' => $this->distinctValues('product'),
                'validations' => $this->distinctValues('validation'),
                'type_signatures' => $this->distinctValues('type_signature'),
            ],
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        try {
            $sync = app(ProductionSynchronizer::class)->sync($request->user());
        } catch (GoogleSheetsException|ProductionMappingException $e) {
            // Message actionnable et sans secret (voir les exceptions).
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => ['sync' => $this->presentSync($sync->loadMissing('user'))],
        ], 201);
    }

    public function syncs(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 10)));

        $syncs = ProductionSync::query()->with('user')->latest('id')->limit($limit)->get();

        return response()->json([
            'data' => $syncs->map(fn (ProductionSync $s) => $this->presentSync($s)),
        ]);
    }

    public function updatePhone(Request $request, ProductionRecord $record): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['present', 'nullable', 'string', 'max:30', new ProductionPhone],
        ]);

        $raw = isset($data['phone']) ? trim((string) $data['phone']) : null;
        $value = $raw === '' ? null : $raw;

        $record->update([
            'phone' => $value,
            // Valeur normalisée uniquement pour la recherche : la valeur saisie
            // (formatage, « + », indicatif) est conservée telle quelle.
            'phone_normalise' => $value === null ? null : PhoneNormalizer::normalize($value),
        ]);

        return response()->json(['data' => $this->present($record->refresh())]);
    }

    /** @return list<string> */
    private function distinctValues(string $column): array
    {
        return ProductionRecord::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->limit(self::FILTER_OPTIONS_LIMIT)
            ->pluck($column)
            ->map(fn (mixed $value) => (string) $value)
            ->all();
    }

    /** Échappe les caractères jokers d'un LIKE (avec la clause ESCAPE par défaut de MySQL). */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function present(ProductionRecord $record): array
    {
        return [
            'id' => $record->id,
            'reference_contrat' => $record->reference_contrat,
            'date' => $record->date?->toDateString(),
            'cial' => $record->cial,
            'nom' => $record->client_name,
            'prenom' => $record->first_name,
            'cotisation' => $record->cotisation,
            'prime_annuelle' => $record->premium_annual,
            'cie' => $record->cie,
            'produit' => $record->product,
            'date_effet' => $record->date_effet?->toDateString(),
            'validation' => $record->validation,
            'type_signature' => $record->type_signature,
            'phone' => $record->phone,
            'last_synced_at' => $record->last_synced_at?->toISOString(),
            'updated_at' => $record->updated_at?->toISOString(),
        ];
    }

    private function presentSync(?ProductionSync $sync): ?array
    {
        if ($sync === null) {
            return null;
        }

        return [
            'id' => $sync->id,
            'status' => $sync->status,
            'rows_read' => $sync->rows_read,
            'created_count' => $sync->created_count,
            'updated_count' => $sync->updated_count,
            'skipped_count' => $sync->skipped_count,
            'failed_count' => $sync->failed_count,
            'error_summary' => $sync->error_summary,
            'warnings' => $sync->warnings,
            'row_errors' => $sync->row_errors,
            'performed_by' => $sync->relationLoaded('user') ? $sync->user?->name : null,
            'started_at' => $sync->started_at?->toISOString(),
            'completed_at' => $sync->completed_at?->toISOString(),
            'created_at' => $sync->created_at?->toISOString(),
        ];
    }
}
