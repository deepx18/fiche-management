<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aligne `production_records` sur les 11 colonnes réelles de la feuille
 * « Production » et ajoute la référence de contrat générée par l'application.
 *
 * Migration **additive uniquement** :
 *  - aucune colonne existante n'est supprimée ni renommée ;
 *  - les colonnes historiques (city, status, contract_reference, …) restent en
 *    place, avec leurs données, même si la synchronisation ne les écrit plus ;
 *  - chaque colonne est ajoutée seulement si elle est absente, car certaines
 *    installations (dont la base de développement) possèdent déjà une partie de
 *    ces colonnes sans migration correspondante.
 *
 * Correspondance feuille → colonnes physiques (documentée dans
 * config/production.php et App\Models\ProductionRecord::SHEET_FIELDS) :
 *
 *   DATE              → date
 *   CIAL              → cial
 *   NOM               → client_name   (nom physique conservé : téléphonie)
 *   PRENOM            → first_name
 *   COTISATION        → cotisation
 *   PRIME ANNUELLE    → premium_annual
 *   CIE               → cie
 *   PRODUIT           → product
 *   Date d'effet      → date_effet
 *   VALIDATION        → validation
 *   TYPE DE SIGNATURE → type_signature
 *   (généré)          → reference_contrat
 *
 * Retour arrière : `down()` ne supprime volontairement rien. Une colonne
 * nouvellement ajoutée peut être retirée manuellement après sauvegarde, mais
 * une suppression automatique détruirait des données métier (et les références
 * de contrat déjà communiquées).
 */
return new class extends Migration
{
    private const TABLE = 'production_records';

    public function up(): void
    {
        $this->addMissingColumns();
        $this->addMissingIndexes();
        $this->addSyncWarningsColumn();
        $this->backfillContractReferences();
    }

    public function down(): void
    {
        // Volontairement vide : voir l'en-tête de la classe (aucune perte de
        // données, rollback documenté dans le rapport de réparation).
    }

    private function addMissingColumns(): void
    {
        $columns = [
            'date' => fn (Blueprint $table) => $table->date('date')->nullable(),
            'cial' => fn (Blueprint $table) => $table->string('cial', 60)->nullable(),
            'first_name' => fn (Blueprint $table) => $table->string('first_name', 60)->nullable(),
            'cotisation' => fn (Blueprint $table) => $table->string('cotisation', 40)->nullable(),
            'premium_annual' => fn (Blueprint $table) => $table->string('premium_annual', 40)->nullable(),
            'cie' => fn (Blueprint $table) => $table->string('cie', 190)->nullable(),
            'date_effet' => fn (Blueprint $table) => $table->date('date_effet')->nullable(),
            'validation' => fn (Blueprint $table) => $table->string('validation', 190)->nullable(),
            'type_signature' => fn (Blueprint $table) => $table->string('type_signature', 300)->nullable(),
            'reference_contrat' => fn (Blueprint $table) => $table->string('reference_contrat', 32)->nullable(),
        ];

        foreach ($columns as $name => $definition) {
            if (! Schema::hasColumn(self::TABLE, $name)) {
                Schema::table(self::TABLE, $definition);
            }
        }
    }

    private function addMissingIndexes(): void
    {
        if (! Schema::hasIndex(self::TABLE, ['reference_contrat'], 'unique')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('reference_contrat');
            });
        }

        foreach (['date', 'cial', 'cie', 'date_effet', 'validation'] as $column) {
            if (! Schema::hasIndex(self::TABLE, [$column])) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->index($column));
            }
        }
    }

    private function addSyncWarningsColumn(): void
    {
        if (! Schema::hasColumn('production_syncs', 'warnings')) {
            Schema::table('production_syncs', function (Blueprint $table): void {
                $table->json('warnings')->nullable();
            });
        }
    }

    /**
     * Attribue une référence stable aux enregistrements existants.
     *
     * Format : CTR-<année de création>-<identifiant sur 6 chiffres>. L'id est
     * déjà unique et immuable : la génération est donc sûre en concurrence et
     * ne peut pas entrer en collision. Les enregistrements déjà référencés sont
     * ignorés, ce qui rend le remplissage rejouable sans jamais changer une
     * référence existante.
     */
    private function backfillContractReferences(): void
    {
        DB::table(self::TABLE)
            ->whereNull('reference_contrat')
            ->orderBy('id')
            ->chunkById(200, function ($records): void {
                foreach ($records as $record) {
                    $year = (string) substr((string) ($record->created_at ?? ''), 0, 4);
                    if (preg_match('/^\d{4}$/', $year) !== 1) {
                        $year = now()->format('Y');
                    }

                    DB::table(self::TABLE)
                        ->where('id', $record->id)
                        ->whereNull('reference_contrat')
                        ->update([
                            'reference_contrat' => 'CTR-'.$year.'-'.str_pad((string) $record->id, 6, '0', STR_PAD_LEFT),
                        ]);
                }
            }, 'id');
    }
};
