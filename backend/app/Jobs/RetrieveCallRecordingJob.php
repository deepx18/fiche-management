<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CallRecord;
use App\Services\Telephony\Issabel\IssabelRecordingFetcher;
use App\Services\Telephony\TelephonyException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Récupère un enregistrement dans le stockage privé.
 *
 * Le job ne remplace jamais un fichier déjà stocké et conserve les métadonnées
 * de l'appel si le fichier est absent, expiré ou inaccessible.
 */
class RetrieveCallRecordingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $callId) {}

    public function handle(IssabelRecordingFetcher $fetcher): void
    {
        $call = CallRecord::find($this->callId);
        if ($call === null || $call->hasStoredRecording()) {
            return;
        }

        if ($call->provider !== 'issabel') {
            $call->update([
                'recording_state' => CallRecord::RECORDING_UNSUPPORTED,
                'recording_error' => 'Aucun téléchargement d\'enregistrement n\'est documenté pour ce fournisseur.',
            ]);

            return;
        }

        try {
            $fetcher->retrieve($call);
        } catch (TelephonyException $e) {
            $retryable = in_array($e->kind(), [
                TelephonyException::KIND_TIMEOUT,
                TelephonyException::KIND_PROVIDER_ERROR,
                TelephonyException::KIND_RECORDING,
            ], true);

            if ($retryable && $this->attempts() < $this->tries) {
                throw $e;
            }

            if ($call->refresh()->recording_state !== CallRecord::RECORDING_STORED) {
                // CONSERVER l'état précis posé par le fetcher (missing,
                // unsupported, failed) : ne pas le remplacer par un échec
                // générique.
                report($e);
            }
        } catch (Throwable $e) {
            report($e);

            if (! $call->refresh()->hasStoredRecording()) {
                $call->update([
                    'recording_state' => CallRecord::RECORDING_FAILED,
                    'recording_error' => 'Échec inattendu de la récupération. Consultez les journaux du backend.',
                ]);
            }
        }
    }
}
