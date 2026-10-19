<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * État d'une recherche pour une ligne de production et un fournisseur.
 *
 * Les états sont volontairement distincts : un échec fournisseur n'est jamais
 * un « aucun appel », et une capacité non prise en charge n'est jamais un
 * échec de recherche.
 */
class CallSearchItem extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NO_RESULTS = 'no_results';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNSUPPORTED = 'unsupported';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_SKIPPED_NO_PHONE = 'skipped_no_phone';

    public const STATUS_INVALID_PHONE = 'invalid_phone';

    /** États qui ne doivent plus être repris par un job. */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_NO_RESULTS,
        self::STATUS_FAILED,
        self::STATUS_UNSUPPORTED,
        self::STATUS_AMBIGUOUS,
        self::STATUS_SKIPPED_NO_PHONE,
        self::STATUS_INVALID_PHONE,
    ];

    protected $fillable = [
        'call_search_id',
        'production_record_id',
        'provider',
        'status',
        'phone_e164',
        'from_date',
        'to_date',
        'result_count',
        'error_code',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'result_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function callSearch(): BelongsTo
    {
        return $this->belongsTo(CallSearch::class);
    }

    public function productionRecord(): BelongsTo
    {
        return $this->belongsTo(ProductionRecord::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isSuccessful(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_NO_RESULTS], true);
    }
}
