<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Trace d'une synchronisation Google Sheets → production_records.
 *
 * Une tentative est enregistrée même en cas d'échec (identifiants manquants,
 * en-têtes incomplets, erreur API Google) afin que l'échec soit visible et
 * actionnable dans l'interface et les journaux — sans journaliser de données
 * personnelles ni de secrets.
 */
class ProductionSync extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'status',
        'rows_read',
        'created_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'error_summary',
        'warnings',
        'row_errors',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'row_errors' => 'array',
            'warnings' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'rows_read' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'skipped_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
