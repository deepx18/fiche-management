<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables du gestionnaire de "Fiches Contact".
 *
 * Conventions choisies :
 * - Les contacts sont conservés (jamais supprimés physiquement pour nettoyer
 *   une fiche) : une extraction associe le contact à une opération
 *   (duplicate_extraction_items). Une opération n'affecte l'ensemble actif de
 *   la fiche que si son statut est "completed". Une restauration supprime les
 *   associations (le contact redevient actif grâce à la contrainte d'unicité
 *   qui garantit qu'un contact n'est lié qu'à une seule extraction en cours),
 *   repositionne l'opération en "restored" et archive les identifiants des
 *   contacts dans le record de restauration (snapshot non structuré limité aux
 *   identifiants, jamais les données de contact).
 * - L'historique d'import et d'extraction est conservé indépendamment des
 *   fichiers physiques.
 * - Les fichiers importés sont archivés par soft delete ; leurs contacts ne
 *   remontent plus dans les listes/export globaux mais l'historique est intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('admin')->after('password');
            $table->boolean('active')->default(true)->after('role');
        });

        Schema::create('imported_files', function (Blueprint $table) {
            $table->id();
            $table->string('original_filename');
            $table->string('storage_path')->nullable()->comment('chemin du fichier original conservé, privé');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_hash', 64)->index();
            $table->string('status', 30)->default('imported'); // imported|partial|failed
            $table->foreignId('imported_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('successful_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'created_at']);
        });

        Schema::create('import_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('imported_file_id')->nullable()->constrained('imported_files')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('status', 30); // completed|partial|failed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('successful_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->text('error_summary')->nullable();
            $table->json('row_errors')->nullable(); // [{row, field, reason}, ...]
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['created_at', 'status']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('imported_file_id')->constrained('imported_files')->cascadeOnDelete();
            $table->string('nom');
            $table->string('prenom');
            $table->string('adresse')->nullable();
            $table->string('cp', 20)->nullable();
            $table->string('ville')->nullable();
            $table->string('tel', 30)->nullable();
            $table->string('telephone_normalise', 30)->nullable()->index();
            $table->string('email')->nullable();
            $table->date('date_naissance')->nullable();
            $table->timestamps();
            $table->index(['imported_file_id', 'nom', 'prenom']);
            $table->index(['imported_file_id', 'email']);
        });

        Schema::create('duplicate_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_file_id')->constrained('imported_files')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('extracted_count')->default(0);
            $table->string('status', 20)->default('completed'); // completed|restored
            $table->text('comment')->nullable(); // groupes téléphoniques impliqués (résumé)
            $table->timestamps();
            $table->index(['source_file_id', 'status', 'created_at']);
        });

        Schema::create('duplicate_extraction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duplicate_extraction_id')->constrained('duplicate_extractions')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('original_file_id')->constrained('imported_files')->cascadeOnDelete();
            $table->timestamps();
            $table->unique('contact_id', 'uq_active_extraction_by_contact'); // un contact actif ne peut appartenir qu'à une seule opération
            $table->index(['duplicate_extraction_id']);
        });

        Schema::create('extraction_restorations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duplicate_extraction_id')->constrained('duplicate_extractions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('restored_count')->default(0);
            $table->json('restored_contact_ids'); // snapshot des contacts restitués
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extraction_restorations');
        Schema::dropIfExists('duplicate_extraction_items');
        Schema::dropIfExists('duplicate_extractions');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('import_histories');
        Schema::dropIfExists('imported_files');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'active']);
        });
    }
};
