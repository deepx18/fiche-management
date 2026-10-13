<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Opération d'extraction de contacts en doublon. Seules les opérations
 * "completed" excluent les contacts du jeu actif ; une restauration archive la
 * trace historique (contacts dans les items restaurés) et repositionne
 * l'opération en "restored" sans effacer l'opération d'origine.
 */
class DuplicateExtraction extends Model
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_RESTORED = 'restored';

    protected $fillable = [
        'source_file_id',
        'user_id',
        'extracted_count',
        'status',
        'comment',
    ];

    public function sourceFile()
    {
        return $this->belongsTo(ImportedFile::class, 'source_file_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function items()
    {
        return $this->hasMany(DuplicateExtractionItem::class, 'duplicate_extraction_id');
    }

    public function restorations()
    {
        return $this->hasMany(ExtractionRestoration::class, 'duplicate_extraction_id');
    }

    /** Vérité sur les contacts extraits : items (complétée) ou snapshot (restaurée). */
    public function scopeWithContacts(Builder $query): Builder
    {
        return $query->with(['items.contact', 'restorations']);
    }
}
