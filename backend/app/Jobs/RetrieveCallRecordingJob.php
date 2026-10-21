<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CallRecord;
use App\Services\Telephony\Issabel\IssabelRecordingFetcher;
use App\Services\Telephony\TelephonyException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Récupère un enregistrement dans le stockage privé.
 *
 * Le job ne remplace jamais un fichier déjà stocké et conserve les métadonnées
 * de l'appel si le fichier est absent, expiré ou inaccessible. L'état
 * `pending` est posé par l'appelant (voir RecordingRetrieval) avant le
 * dispatch : une redélivrance du job ne crée pas de doublon et ne repart pas
 * d'un état terminal exploitable.
 */
class RetrieveCallRecordingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /**
     * Une copie de fichier audio ne doit jamais bloquer un worker sans fin.
     * Au-delà, le job est marqué en échec par le worker et `failed()` conserve
     * l'état précis.
     */
    public int $timeout = 120;

    public bool $failOnTimeout = true;

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

        Log::info('Récupération d\'enregistrement démarrée', [
            'call_id' => $call->id,
            'attempt' => $this->attempts(),
            'reference' => $call->recording_provider_reference,
        ]);

        try {
            $fetcher->retrieve($call);
        } catch (TelephonyException $e) {
            // Un fichier définitivement absent du répertoire ne sera pas
            // présent quelques secondes plus tard : inutile de réessayer.
            // Les erreurs de copie/validation, elles, restent réessayables.
            $retryable = in_array($e->kind(), [
                TelephonyException::KIND_TIMEOUT,
                TelephonyException::KIND_PROVIDER_ERROR,
            ], true) || ($e->kind() === TelephonyException::KIND_RECORDING
                && $call->refresh()->recording_state !== CallRecord::RECORDING_MISSING);

            if ($retryable && $this->attempts() < $this->tries) {
                Log::warning('Récupération d\'enregistrement retentée', [
                    'call_id' => $call->id,
                    'attempt' => $this->attempts(),
                    'code' => $e->kind(),
                ]);

                throw $e;
            }

            // CONSERVER l'état précis posé par le fetcher (missing,
            // unsupported, failed) : ne pas le remplacer par un échec
            // générique.
            Log::warning('Récupération d\'enregistrement échouée', [
                'call_id' => $call->id,
                'code' => $e->kind(),
                'state' => $call->refresh()->recording_state,
            ]);

            report($e);
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

    /**
     * Appelé par le worker quand les tentatives sont épuisées ou que le délai
     * est dépassé : un échec ne doit jamais rester « en cours » à l'écran.
     */
    public function failed(?Throwable $exception): void
    {
        $call = CallRecord::find($this->callId);

        if ($call === null || $call->refresh()->hasStoredRecording()) {
            return;
        }

        $call->update([
            'recording_state' => CallRecord::RECORDING_FAILED,
            'recording_error' => 'La récupération de l\'enregistrement a échoué après plusieurs tentatives. '
                .'Consultez les journaux du backend.',
        ]);

        Log::error('Récupération d\'enregistrement abandonnée', [
            'call_id' => $this->callId,
            'code' => $exception === null ? null : $exception::class,
        ]);
    }
}
