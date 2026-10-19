<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CallRecord;
use App\Services\Telephony\Recordings\RecordingStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Purge les fichiers audio privés au-delà de la durée de conservation.
 *
 * Les métadonnées d'appel sont conservées : seul l'enregistrement devient
 * « expiré ». `TELEPHONY_RECORDING_RETENTION_DAYS=0` désactive la purge.
 */
class PruneTelephonyRecordingsCommand extends Command
{
    protected $signature = 'telephony:prune-recordings {--dry-run : Simuler sans supprimer}';

    protected $description = 'Supprime les enregistrements privés expirés (métadonnées conservées)';

    public function handle(RecordingStorage $storage): int
    {
        $days = (int) config('telephony.recordings.retention_days', 180);
        if ($days <= 0) {
            $this->info('Conservation illimitée configurée : aucune purge effectuée.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $query = CallRecord::query()
            ->where('recording_state', CallRecord::RECORDING_STORED)
            ->whereNotNull('recording_retrieved_at')
            ->where('recording_retrieved_at', '<', $cutoff);

        $total = (clone $query)->count();
        if ($this->option('dry-run')) {
            $this->info($total.' enregistrement(s) seraient purgés.');

            return self::SUCCESS;
        }

        $pruned = 0;
        $missing = 0;

        $query->chunkById(100, function ($calls) use ($storage, &$pruned, &$missing): void {
            foreach ($calls as $call) {
                if (! $call instanceof CallRecord || ! $call->hasStoredRecording()) {
                    continue;
                }

                if (Storage::disk((string) $call->recording_disk)->exists((string) $call->recording_path)) {
                    $storage->delete((string) $call->recording_disk, (string) $call->recording_path);
                } else {
                    $missing++;
                }

                $call->update([
                    'recording_state' => CallRecord::RECORDING_EXPIRED,
                    'recording_disk' => null,
                    'recording_path' => null,
                    'recording_mime' => null,
                    'recording_size' => null,
                    'recording_error' => null,
                ]);

                $pruned++;
            }
        });

        $this->info($pruned.' enregistrement(s) purgé(s)'.($missing > 0 ? ', dont '.$missing.' déjà absent(s)' : '').'.');

        return self::SUCCESS;
    }
}
