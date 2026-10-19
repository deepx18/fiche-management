<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Métadonnées d'appels et suivi des recherches.
 *
 * Aucun fichier audio n'est stocké en base : seuls le disque privé, le chemin
 * relatif et les métadonnées vérifiées du fichier sont conservés.
 *
 * L'identité d'un appel est (provider, provider_call_id). Un identifiant
 * fournisseur absent est remplacé par une empreinte documentée côté adaptateur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued'); // queued|running|completed|partial|failed
            $table->date('from_date');
            $table->date('to_date');
            $table->json('providers');
            $table->boolean('retrieve_recordings')->default(false);
            $table->unsignedInteger('requested_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->text('error_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('call_search_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_search_id')->constrained('call_searches')->cascadeOnDelete();
            $table->foreignId('production_record_id')->nullable()->constrained('production_records')->nullOnDelete();
            $table->string('provider', 20);
            $table->string('status', 20)->default('queued');
            $table->string('phone_e164', 20)->nullable();
            $table->date('from_date');
            $table->date('to_date');
            $table->unsignedInteger('result_count')->default(0);
            $table->string('error_code', 40)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['call_search_id', 'production_record_id', 'provider'], 'call_search_item_unique');
            $table->index(['status', 'provider']);
        });

        Schema::create('call_records', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('provider_call_id', 190);
            $table->foreignId('production_record_id')->nullable()->constrained('production_records')->nullOnDelete();
            $table->string('matched_phone_e164', 20)->nullable()->comment('E.164 du numéro client rapproché');
            $table->string('raw_phone', 120)->nullable()->comment('valeur fournisseur brute, pour audit');
            $table->string('raw_destination', 120)->nullable();
            $table->string('direction', 20)->nullable();
            $table->string('status', 40)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('billable_seconds')->nullable();
            $table->string('agent', 60)->nullable();
            $table->string('match_basis', 40)->nullable()->comment('provider_search|webhook|ambiguous');
            $table->string('recording_state', 30)->default('unavailable');
            $table->string('recording_provider_reference', 190)->nullable()->comment('chemin relatif fournisseur validé, jamais absolu');
            $table->string('recording_disk', 30)->nullable();
            $table->string('recording_path', 255)->nullable();
            $table->string('recording_mime', 80)->nullable();
            $table->unsignedBigInteger('recording_size')->nullable();
            $table->string('recording_error', 500)->nullable();
            $table->timestamp('recording_retrieved_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_call_id'], 'call_record_provider_unique');
            $table->index(['production_record_id', 'started_at']);
            $table->index(['matched_phone_e164', 'started_at']);
            $table->index(['provider', 'last_seen_at']);
            $table->index(['recording_state', 'recording_retrieved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_records');
        Schema::dropIfExists('call_search_items');
        Schema::dropIfExists('call_searches');
    }
};
