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

/**
 * Phase 1 — Table « Production ».
 *
 *  - `index`  : liste filtrée/triée/paginée des lignes synchronisées, avec la
 *    dernière synchronisation et les valeurs de filtre réellement présentes ;
 *  - `sync`   : lance une synchronisation Google Sheets (idempotente) ;
 *  - `syncs`  : historique court des synchronisations (succès et échecs) ;
 *  - `updatePhone` : ajout/modification du numéro client (champ possédé par
 *    l'application, jamais écrasé par la synchronisation).
 *
 * Le classeur et la plage ne sont jamais fournis par le client : ils viennent
 * exclusivement de la configuration serveur (config/production.php).
 */
class ProductionController extends Controller
{
    /** Colonnes triables autorisées pour la table de production. */
    private const SORTABLE = [
        'id', 'contract_reference', 'client_name', 'phone', 'city', 'product', 'status', 'last_synced_at',
    ];

    private const FILTER_OPTIONS_LIMIT = 200;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'has_phone' => ['nullable', 'in:all,yes,no'],
            'sort_by' => ['nullable', 'in:'.implode(',', self::SORTABLE)],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ]);

        $query = ProductionRecord::query();

        if ($search = trim((string) $request->query('search'))) {
            $like = '%'.$this->escapeLike($search).'%';
            $normalizedPhone = PhoneNormalizer::normalize($search);
            $query->where(function ($q) use ($like, $normalizedPhone) {
                $q->where('contract_reference', 'like', $like)
                    ->orWhere('client_name', 'like', $like)
                    ->orWhere('phone', 'like', $like);
                if ($normalizedPhone !== null) {
                    $q->orWhere('phone_normalise', 'like', '%'.$normalizedPhone.'%');
                }
            });
        }

        foreach (['city', 'product', 'status'] as $field) {
            $value = trim((string) $request->query($field));
            if ($value !== '') {
                $query->where($field, $value);
            }
        }

        match ((string) $request->query('has_phone', 'all')) {
            'yes' => $query->whereNotNull('phone'),
            'no' => $query->whereNull('phone'),
            default => null,
        };

        $sortBy = (string) $request->query('sort_by', 'id');
        if (! in_array($sortBy, self::SORTABLE, true)) {
            $sortBy = 'id';
        }
        $direction = (string) $request->query('sort_direction', 'asc');
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }
        $query->orderBy($sortBy, $direction);
        if ($sortBy !== 'id') {
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
                'cities' => $this->distinctValues('city'),
                'products' => $this->distinctValues('product'),
                'statuses' => $this->distinctValues('status'),
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
            'contract_reference' => $record->contract_reference,
            'client_name' => $record->client_name,
            'phone' => $record->phone,
            'city' => $record->city,
            'product' => $record->product,
            'status' => $record->status,
            'source_row' => $record->source_row,
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
            'row_errors' => $sync->row_errors,
            'performed_by' => $sync->relationLoaded('user') ? $sync->user?->name : null,
            'started_at' => $sync->started_at?->toISOString(),
            'completed_at' => $sync->completed_at?->toISOString(),
            'created_at' => $sync->created_at?->toISOString(),
        ];
    }
}
