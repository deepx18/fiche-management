<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Jobs\RetrieveCallRecordingJob;
use App\Models\CallRecord;
use Illuminate\Support\Facades\Log;

/**
 * Point d'entrée unique pour planifier la récupération d'un enregistrement.
 *
 * La recherche d'appels et l'action « Récupérer » de l'interface passent par
 * ce service : un appel déjà stocké, sans référence de fichier, ou dont la
 * récupération est en cours (état `pending` récent) n'est jamais ré-enqueué.
 * L'état est posé AVANT le dispatch afin que l'interface affiche l'attente,
 * même avec une file synchrone.
 */
class RecordingRetrieval
{
    public function canQueue(CallRecord $call): bool
    {
        if ($call->provider !== 'issabel') {
            return false;
        }

        if ($call->hasStoredRecording()) {
            return false;
        }

        if (trim((string) $call->recording_provider_reference) === '') {
            return false;
        }

        if ($call->recording_state === CallRecord::RECORDING_PENDING && ! $this->pendingHasExpired($call)) {
            return false;
        }

        return true;
    }

    /**
     * @return bool vrai si un job vient d'être dispatché.
     */
    public function queue(CallRecord $call): bool
    {
        if (! $this->canQueue($call)) {
            return false;
        }

        $call->update([
            'recording_state' => CallRecord::RECORDING_PENDING,
            'recording_error' => null,
        ]);

        RetrieveCallRecordingJob::dispatch($call->id)->afterCommit();

        Log::info('Récupération d\'enregistrement planifiée', [
            'call_id' => $call->id,
            'provider' => $call->provider,
            'reference' => $call->recording_provider_reference,
        ]);

        return true;
    }

    /**
     * Un état « pending » plus ancien que le délai configuré est considéré
     * comme abandonné (worker arrêté, redéploiement) : il peut être relancé.
     */
    private function pendingHasExpired(CallRecord $call): bool
    {
        $minutes = max(1, (int) config('telephony.recordings.pending_timeout_minutes', 15));
        $updatedAt = $call->updated_at;

        return $updatedAt === null || $updatedAt->copy()->addMinutes($minutes)->isPast();
    }
}
