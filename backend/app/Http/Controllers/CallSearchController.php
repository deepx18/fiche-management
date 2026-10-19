<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\RetrieveCallRecordingJob;
use App\Models\CallRecord;
use App\Models\CallSearch;
use App\Models\CallSearchItem;
use App\Models\ProductionRecord;
use App\Services\Telephony\CallProviderRegistry;
use App\Services\Telephony\CallSearchService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 — recherche d'appels pour une ou plusieurs lignes de production.
 *
 * La requête ne contacte jamais un fournisseur : elle valide, crée un lot et
 * dispatche des jobs. Les états et erreurs sont ensuite consultés par
 * l'interface, sans exposer de payload fournisseur ni de secret.
 */
class CallSearchController extends Controller
{
    public function store(Request $request, CallSearchService $service, CallProviderRegistry $registry): JsonResponse
    {
        $maxRecords = (int) config('telephony.search.max_records', 50);
        $maxDays = (int) config('telephony.search.max_days', 366);

        $data = $request->validate([
            'record_ids' => ['required', 'array', 'min:1', 'max:'.$maxRecords],
            'record_ids.*' => ['integer', 'distinct', 'exists:production_records,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'providers' => ['nullable', 'array', 'min:1'],
            'providers.*' => ['string', Rule::in($registry->names())],
            'retrieve_recordings' => ['nullable', 'boolean'],
        ]);

        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'])->startOfDay()
            : CarbonImmutable::today()->subDays((int) config('telephony.search.default_days', 30))->startOfDay();

        $to = isset($data['to'])
            ? CarbonImmutable::parse($data['to'])->endOfDay()
            : CarbonImmutable::today()->endOfDay();

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages([
                'to' => 'La date de fin doit être postérieure ou égale à la date de début.',
            ]);
        }

        if ($from->diffInDays($to) > $maxDays) {
            throw ValidationException::withMessages([
                'to' => 'La plage de recherche ne peut pas dépasser '.$maxDays.' jours.',
            ]);
        }

        $providers = $data['providers'] ?? $registry->names();

        $search = $service->start(
            user: $request->user(),
            recordIds: array_map('intval', $data['record_ids']),
            from: $from,
            to: $to,
            providers: $providers,
            retrieveRecordings: (bool) ($data['retrieve_recordings'] ?? false),
        );

        return response()->json([
            'data' => ['search' => $this->presentSearch($search, true)],
        ], 201);
    }

    public function show(CallSearch $search): JsonResponse
    {
        $search->load(['items.productionRecord']);

        return response()->json([
            'data' => [
                'search' => $this->presentSearch($search, false),
                'items' => $search->items->map(fn (CallSearchItem $item) => $this->presentItem($item)),
            ],
        ]);
    }

    public function calls(Request $request, CallSearch $search): JsonResponse
    {
        $search->loadMissing('items');

        $recordIds = $search->items->pluck('production_record_id')->filter()->unique()->values();
        $providers = $search->items->pluck('provider')->unique()->values();

        $perPage = min(200, max(1, (int) $request->query('per_page', 100)));

        $calls = CallRecord::query()
            ->with('productionRecord')
            ->whereIn('production_record_id', $recordIds)
            ->whereIn('provider', $providers)
            ->where(function ($query) use ($search): void {
                $query->whereBetween('started_at', [
                    $search->from_date->toImmutable()->startOfDay(),
                    $search->to_date->toImmutable()->endOfDay(),
                ])->orWhereNull('started_at');
            })
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($calls->items())->map(fn (CallRecord $call) => $this->presentCall($call)),
            'meta' => [
                'current_page' => $calls->currentPage(),
                'last_page' => $calls->lastPage(),
                'total' => $calls->total(),
                'per_page' => $calls->perPage(),
            ],
        ]);
    }

    public function recordCalls(Request $request, ProductionRecord $record): JsonResponse
    {
        $perPage = min(200, max(1, (int) $request->query('per_page', 100)));

        $calls = $record->calls()
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($calls->items())->map(fn (CallRecord $call) => $this->presentCall($call)),
            'meta' => [
                'current_page' => $calls->currentPage(),
                'last_page' => $calls->lastPage(),
                'total' => $calls->total(),
                'per_page' => $calls->perPage(),
            ],
        ]);
    }

    public function retrieveRecording(CallRecord $call): JsonResponse
    {
        if (in_array($call->recording_state, [CallRecord::RECORDING_STORED, CallRecord::RECORDING_PENDING], true)) {
            return response()->json(['data' => ['state' => $call->recording_state]]);
        }

        if ($call->provider !== 'issabel') {
            return response()->json([
                'message' => 'Aucun téléchargement d\'enregistrement n\'est documenté pour ce fournisseur.',
                'state' => $call->recording_state,
            ], 409);
        }

        // Marquer « en attente » AVANT le dispatch : avec une file synchrone
        // (tests, développement), le job s'exécute immédiatement et son état
        // final ne doit pas être écrasé par un « pending » tardif.
        $call->update(['recording_state' => CallRecord::RECORDING_PENDING]);
        RetrieveCallRecordingJob::dispatch($call->id);
        $call->refresh();

        return response()->json([
            'data' => ['state' => $call->recording_state],
        ], 202);
    }

    private function presentSearch(CallSearch $search, bool $withItems): array
    {
        $payload = [
            'id' => $search->id,
            'status' => $search->status,
            'from_date' => $search->from_date?->toDateString(),
            'to_date' => $search->to_date?->toDateString(),
            'providers' => $search->providers,
            'retrieve_recordings' => $search->retrieve_recordings,
            'requested_count' => $search->requested_count,
            'completed_count' => $search->completed_count,
            'failed_count' => $search->failed_count,
            'skipped_count' => $search->skipped_count,
            'error_summary' => $search->error_summary,
            'started_at' => $search->started_at?->toISOString(),
            'completed_at' => $search->completed_at?->toISOString(),
            'created_at' => $search->created_at?->toISOString(),
        ];

        if ($withItems) {
            $search->loadMissing(['items.productionRecord']);
            $payload['items'] = $search->items->map(fn (CallSearchItem $item) => $this->presentItem($item));
        }

        return $payload;
    }

    private function presentItem(CallSearchItem $item): array
    {
        return [
            'id' => $item->id,
            'provider' => $item->provider,
            'status' => $item->status,
            'result_count' => $item->result_count,
            'error_code' => $item->error_code,
            'error_message' => $item->error_message,
            'record' => $item->productionRecord === null ? null : [
                'id' => $item->productionRecord->id,
                'contract_reference' => $item->productionRecord->contract_reference,
                'client_name' => $item->productionRecord->client_name,
            ],
            'completed_at' => $item->completed_at?->toISOString(),
        ];
    }

    private function presentCall(CallRecord $call): array
    {
        return [
            'id' => $call->id,
            'provider' => $call->provider,
            'record_id' => $call->production_record_id,
            'contract_reference' => $call->productionRecord?->contract_reference,
            'client_name' => $call->productionRecord?->client_name,
            'phone' => $call->matched_phone_e164 ?? $call->raw_phone,
            'direction' => $call->direction,
            'status' => $call->status,
            'started_at' => $call->started_at?->toISOString(),
            'duration_seconds' => $call->duration_seconds,
            'billable_seconds' => $call->billable_seconds,
            'agent' => $call->agent,
            'match_basis' => $call->match_basis,
            'recording' => [
                'state' => $call->recording_state,
                'available' => $call->hasStoredRecording(),
                'mime' => $call->recording_mime,
                'size' => $call->recording_size,
                'retrieved_at' => $call->recording_retrieved_at?->toISOString(),
            ],
        ];
    }
}
