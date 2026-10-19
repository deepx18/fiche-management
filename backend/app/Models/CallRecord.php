<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Métadonnée d'un appel fournisseur.
 *
 * L'identité stable est (provider, provider_call_id). Le fichier audio n'est
 * jamais stocké en base : `recording_disk` + `recording_path` pointent vers le
 * stockage privé, et `recording_provider_reference` ne contient qu'un nom de
 * fichier fournisseur, jamais un chemin absolu ni une URL signée.
 */
class CallRecord extends Model
{
    public const RECORDING_UNAVAILABLE = 'unavailable';

    public const RECORDING_REFERENCE_ONLY = 'reference_only';

    public const RECORDING_PENDING = 'pending';

    public const RECORDING_STORED = 'stored';

    public const RECORDING_MISSING = 'missing';

    public const RECORDING_FAILED = 'failed';

    public const RECORDING_UNSUPPORTED = 'unsupported';

    public const RECORDING_EXPIRED = 'expired';

    protected $fillable = [
        'provider',
        'provider_call_id',
        'production_record_id',
        'matched_phone_e164',
        'raw_phone',
        'raw_destination',
        'direction',
        'status',
        'started_at',
        'ended_at',
        'duration_seconds',
        'billable_seconds',
        'agent',
        'match_basis',
        'recording_state',
        'recording_provider_reference',
        'recording_disk',
        'recording_path',
        'recording_mime',
        'recording_size',
        'recording_error',
        'recording_retrieved_at',
        'last_seen_at',
        'provider_metadata',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'billable_seconds' => 'integer',
            'recording_size' => 'integer',
            'recording_retrieved_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'provider_metadata' => 'array',
        ];
    }

    public function productionRecord(): BelongsTo
    {
        return $this->belongsTo(ProductionRecord::class);
    }

    public function hasStoredRecording(): bool
    {
        return $this->recording_state === self::RECORDING_STORED
            && $this->recording_disk !== null
            && $this->recording_path !== null;
    }
}
