<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Association contact ↔ opération d'extraction. La contrainte SQL d'unicité
 * sur contact_id garantit qu'un contact actif ne peut appartenir qu'à une
 * seule extraction simultanée. À la restauration, les associations sont
 * supprimées (le contact redevient actif) et un snapshot des identifiants est
 * archivé dans extraction_restorations.
 */
class DuplicateExtractionItem extends Model
{
    protected $fillable = [
        'duplicate_extraction_id',
        'contact_id',
        'original_file_id',
    ];

    public function extraction()
    {
        return $this->belongsTo(DuplicateExtraction::class, 'duplicate_extraction_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function originalFile()
    {
        return $this->belongsTo(ImportedFile::class, 'original_file_id');
    }
}
