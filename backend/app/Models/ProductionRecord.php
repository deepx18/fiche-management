<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Ligne « Production » synchronisée depuis Google Sheets.
 *
 * Correspondance feuille → colonne physique (une colonne physique par colonne
 * métier, aucune colonne en double) :
 *
 *   DATE              → date
 *   CIAL              → cial
 *   NOM               → client_name   (nom physique conservé pour la téléphonie)
 *   PRENOM            → first_name
 *   COTISATION        → cotisation
 *   PRIME ANNUELLE    → premium_annual
 *   CIE               → cie
 *   PRODUIT           → product
 *   Date d'effet      → date_effet
 *   VALIDATION        → validation
 *   TYPE DE SIGNATURE → type_signature
 *
 * Possession des champs :
 *  - la feuille est la source de vérité des 11 champs ci-dessus ;
 *  - `phone` / `phone_normalise` sont saisis dans l'application et ne sont
 *    JAMAIS écrits par la synchronisation ;
 *  - `reference_contrat` est générée une seule fois à la création, reste stable
 *    et n'est jamais écrasée par la feuille.
 *
 * `source_key` est l'identité stable (SHA-256 de la clé métier normalisée), ce
 * qui rend les synchronisations rejouables sans doublon.
 *
 * Les colonnes historiques `city`, `status` et `contract_reference` restent en
 * place (données conservées) mais ne sont plus alimentées par la feuille.
 */
class ProductionRecord extends Model
{
    /** Champ canonique → colonne physique (seule source de vérité du mapping). */
    public const SHEET_FIELDS = [
        'date' => 'date',
        'cial' => 'cial',
        'nom' => 'client_name',
        'prenom' => 'first_name',
        'cotisation' => 'cotisation',
        'prime_annuelle' => 'premium_annual',
        'cie' => 'cie',
        'produit' => 'product',
        'date_effet' => 'date_effet',
        'validation' => 'validation',
        'type_signature' => 'type_signature',
    ];

    /** Champs générés par l'application, jamais écrits depuis la feuille. */
    public const GENERATED_FIELDS = ['reference_contrat'];

    /** Champs dont l'application est la source de vérité. */
    public const APPLICATION_FIELDS = ['phone', 'phone_normalise'];

    /** Nombre de tentatives en cas de collision de référence (aléatoire). */
    private const REFERENCE_ATTEMPTS = 5;

    protected $fillable = [
        'source_key',
        'reference_contrat',
        'date',
        'cial',
        'client_name',
        'first_name',
        'cotisation',
        'premium_annual',
        'cie',
        'product',
        'date_effet',
        'validation',
        'type_signature',
        'phone',
        'phone_normalise',
        'source_row',
        'last_synced_at',
        // Colonnes historiques conservées (jamais écrites par la feuille).
        'contract_reference',
        'city',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'date_effet' => 'date',
            'last_synced_at' => 'datetime',
            'source_row' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            if (blank($record->reference_contrat)) {
                $record->reference_contrat = self::generateReferenceContrat();
            }
        });
    }

    /**
     * Crée un enregistrement en garantissant une référence unique.
     *
     * La référence est aléatoire : l'index unique de la base tranche en cas de
     * collision (probabilité négligeable) et l'insertion est retentée un nombre
     * borné de fois. Aucune transaction réseau n'est ouverte ici.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createWithReference(array $attributes): self
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return static::query()->create($attributes);
            } catch (UniqueConstraintViolationException $exception) {
                $collision = str_contains($exception->getMessage(), 'reference_contrat');
                if (! $collision || $attempt >= self::REFERENCE_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Référence lisible, ex. « CTR-2026-K7M2QX9P ».
     *
     * Elle ne dépend ni du numéro de ligne, ni du nom, ni de la date : elle
     * reste donc stable même si la feuille est réordonnée ou modifiée.
     */
    public static function generateReferenceContrat(): string
    {
        return 'CTR-'.now()->format('Y').'-'.strtoupper(Str::random(8));
    }

    /**
     * Attribut historique lu par la téléphonie : il renvoie la référence
     * générée lorsque la colonne historique n'est pas renseignée.
     */
    public function getContractReferenceAttribute(): ?string
    {
        $generated = $this->attributes['reference_contrat'] ?? null;

        return $generated !== null && $generated !== ''
            ? (string) $generated
            : ($this->attributes['contract_reference'] ?? null);
    }

    /** Appels fournisseurs associés (Phase 2). */
    public function calls(): HasMany
    {
        return $this->hasMany(CallRecord::class);
    }
}
