<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historique de restauration : chaque restauration est enregistrée sans
 * effacer l'opération d'extraction d'origine. Les identifiants des contacts
 * restitués sont archivés ici (les associations actives sont supprimées pour
 * rendre les contacts à nouveau actifs — contrainte d'unicité).
 */
class ExtractionRestoration extends Model
{
    protected $fillable = [
        'duplicate_extraction_id',
        'user_id',
        'restored_count',
        'restored_contact_ids',
    ];

    protected function casts(): array
    {
        return [
            'restored_contact_ids' => 'array',
        ];
    }

    public function extraction()
    {
        return $this->belongsTo(DuplicateExtraction::class, 'duplicate_extraction_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
