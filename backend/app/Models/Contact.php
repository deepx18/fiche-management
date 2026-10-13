<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contact importé. Téléphone et code postal sont des chaînes (zéros initiaux
 * préservés). Un contact n'est jamais supprimé physiquement pour nettoyer une
 * fiche : son statut "actif" dépend des opérations d'extraction complétées
 * (voir scope active()).
 */
class Contact extends Model
{
    protected function casts(): array
    {
        return [
            'date_naissance' => 'date',
        ];
    }

    protected $fillable = [
        'imported_file_id',
        'nom',
        'prenom',
        'adresse',
        'cp',
        'ville',
        'tel',
        'telephone_normalise',
        'email',
        'date_naissance',
    ];

    /**
     * Stratégie centralisée du statut actif : un contact est actif tant qu'il
     * n'est associé à aucune opération d'extraction complétée. Toutes les vues
     * (comptages, listes, exports, recherches de doublons) doivent passer par ce
     * scope pour éviter des règles divergentes.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotExists(function ($q) {
            $q->selectRaw(1)
                ->from('duplicate_extraction_items as dei')
                ->join('duplicate_extractions as de', 'de.id', '=', 'dei.duplicate_extraction_id')
                ->whereColumn('dei.contact_id', 'contacts.id')
                ->where('de.status', 'completed');
        });
    }

    /** Contacts actuellement extraits (associés à une extraction complétée). */
    public function scopeExtracted(Builder $query): Builder
    {
        return $query->whereExists(function ($q) {
            $q->selectRaw(1)
                ->from('duplicate_extraction_items as dei')
                ->join('duplicate_extractions as de', 'de.id', '=', 'dei.duplicate_extraction_id')
                ->whereColumn('dei.contact_id', 'contacts.id')
                ->where('de.status', 'completed');
        });
    }

    /** Contacts actifs appartenant à des fiches non archivées (listes globales). */
    public function scopeActiveGlobally(Builder $query): Builder
    {
        return $query->active()
            ->whereHas('importedFile', fn (Builder $q) => $q->whereNull($q->qualifyColumn('deleted_at')));
    }

    public function importedFile()
    {
        return $this->belongsTo(ImportedFile::class, 'imported_file_id');
    }

    /** Associations d'extraction actives du contact (0..1 en pratique). */
    public function extractionItems(): HasMany
    {
        return $this->hasMany(DuplicateExtractionItem::class, 'contact_id');
    }
}
