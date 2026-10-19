<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ligne « Production » synchronisée depuis Google Sheets.
 *
 * Possession des champs (voir config/production.php) :
 *  - champs mis à jour par la synchronisation : contract_reference,
 *    client_name, city, product, status, source_row, last_synced_at ;
 *  - champ saisi dans l'application : phone (+ phone_normalise dérivé).
 *    La synchronisation ne touche jamais ces deux colonnes.
 *
 * `source_key` est l'identité stable (SHA-256 de la clé métier normalisée) :
 * elle garantit qu'une ré-importation met à jour la même ligne au lieu de
 * créer un doublon.
 */
class ProductionRecord extends Model
{
    /** Champs dont la feuille est la source de vérité. */
    public const SPREADSHEET_FIELDS = [
        'contract_reference',
        'client_name',
        'city',
        'product',
        'status',
    ];

    /** Champs dont l'application est la source de vérité. */
    public const APPLICATION_FIELDS = ['phone'];

    protected $fillable = [
        'source_key',
        'contract_reference',
        'client_name',
        'phone',
        'phone_normalise',
        'city',
        'product',
        'status',
        'source_row',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'source_row' => 'integer',
        ];
    }

    /** Appels fournisseurs associés (Phase 2). */
    public function calls(): HasMany
    {
        return $this->hasMany(CallRecord::class);
    }
}
