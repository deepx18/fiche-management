<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Table « Production ».
 *
 * Modèle de possession des champs (voir config/production.php) :
 *  - champs possédés par la feuille : contract_reference, client_name, city,
 *    product, status — mis à jour à chaque synchronisation ;
 *  - champ possédé par l'application : phone (+ phone_normalise, dérivé) —
 *    JAMAIS écrit par la synchronisation, la saisie manuelle est préservée.
 *
 * `source_key` est l'identité stable d'une ligne : SHA-256 de la clé métier
 * normalisée (concaténation des champs d'identité). Le numéro de ligne de la
 * feuille (`source_row`) est purement informatif et n'est jamais une identité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_records', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 64)->unique();
            $table->string('contract_reference', 120)->nullable();
            $table->string('client_name', 190)->nullable();
            $table->string('phone', 30)->nullable()->comment('saisi dans l\'application, jamais écrasé par la synchro');
            $table->string('phone_normalise', 30)->nullable()->comment('dérivé de phone, pour la recherche uniquement');
            $table->string('city', 120)->nullable();
            $table->string('product', 190)->nullable();
            $table->string('status', 60)->nullable();
            $table->unsignedInteger('source_row')->nullable()->comment('dernière ligne vue dans la feuille (informatif)');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('contract_reference');
            $table->index('client_name');
            $table->index('phone_normalise');
            $table->index('city');
            $table->index('product');
            $table->index('status');
            $table->index('last_synced_at');
        });

        Schema::create('production_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('failed'); // completed|partial|failed
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('error_summary')->nullable();
            $table->json('row_errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_syncs');
        Schema::dropIfExists('production_records');
    }
};
