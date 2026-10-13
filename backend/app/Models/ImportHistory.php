<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historique d'import : chaque tentative, même échouée, laisse une trace
 * persistante indépendante de la fiche associée.
 */
class ImportHistory extends Model
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'imported_file_id',
        'user_id',
        'original_filename',
        'status',
        'total_rows',
        'successful_rows',
        'failed_rows',
        'skipped_rows',
        'error_summary',
        'row_errors',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'row_errors' => 'array',
        ];
    }

    public function importedFile()
    {
        return $this->belongsTo(ImportedFile::class, 'imported_file_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
