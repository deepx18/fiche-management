<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un lot de recherche d'appels pour une ou plusieurs lignes de production.
 *
 * Le lot ne contacte jamais les fournisseurs dans la requête HTTP : il crée
 * des éléments (un par ligne × fournisseur) puis dispatche des jobs.
 */
class CallSearch extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'status',
        'from_date',
        'to_date',
        'providers',
        'retrieve_recordings',
        'requested_count',
        'completed_count',
        'failed_count',
        'skipped_count',
        'error_summary',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'providers' => 'array',
            'retrieve_recordings' => 'boolean',
            'requested_count' => 'integer',
            'completed_count' => 'integer',
            'failed_count' => 'integer',
            'skipped_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CallSearchItem::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_PARTIAL,
            self::STATUS_FAILED,
        ], true);
    }
}
