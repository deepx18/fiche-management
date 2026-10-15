<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index complémentaires pour les filtres/tris ajoutés à la recherche globale
 * et aux tables de l'application :
 *  - `contacts.ville` / `contacts.cp` : filtres de ville et code postal ;
 *  - `imported_files.imported_by` : filtre « importé par » ;
 *  - `import_histories.user_id` : filtre « importé par » de l'historique ;
 *  - `duplicate_extractions.user_id` / `created_at` : filtre administrateur et
 *    tri par date d'extraction.
 * Aucun index superfétatoire n'est ajouté (les recherches « LIKE %x% » ne
 * peuvent de toute façon pas exploiter un index de préfixe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->index('ville');
            $table->index('cp');
        });

        Schema::table('imported_files', function (Blueprint $table) {
            $table->index('imported_by');
        });

        Schema::table('import_histories', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('duplicate_extractions', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('duplicate_extractions', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('import_histories', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('imported_files', function (Blueprint $table) {
            $table->dropIndex(['imported_by']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['ville']);
            $table->dropIndex(['cp']);
        });
    }
};
