<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fiche importée (métadonnées + fichier original conservé en stockage privé).
 * Soft delete = archivage ; l'historique d'import et d'extraction est conservé.
 */
class ImportedFile extends Model
{
    use SoftDeletes;

    public const STATUS_IMPORTED = 'imported';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'original_filename',
        'storage_path',
        'file_size',
        'file_hash',
        'status',
        'imported_by',
        'total_rows',
        'successful_rows',
        'failed_rows',
        'skipped_rows',
    ];

    /** Fiches actives (non archivées). */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('deleted_at'));
    }

    public function importer()
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /**
     * Contacts actifs de la fiche : exclut ceux associés à une extraction
     * complétée. Strategie centralisée utilisée aussi bien par les détails,
     * les comptages et l'export nettoyé.
     */
    public function activeContacts()
    {
        return $this->contacts()->active();
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class, 'imported_file_id');
    }

    public function importHistories()
    {
        return $this->hasMany(ImportHistory::class, 'imported_file_id');
    }

    public function extractions()
    {
        return $this->hasMany(DuplicateExtraction::class, 'source_file_id');
    }
}
