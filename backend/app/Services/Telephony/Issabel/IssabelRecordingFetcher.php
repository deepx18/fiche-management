<?php

declare(strict_types=1);

namespace App\Services\Telephony\Issabel;

use App\Models\CallRecord;
use App\Services\Telephony\Recordings\RecordingStorage;
use App\Services\Telephony\TelephonyException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Récupération d'un enregistrement Issabel depuis un répertoire privé
 * configuré côté serveur (aucun chemin fourni par le navigateur).
 *
 * Le CDR fournit au mieux un chemin relatif. Le chemin réel est reconstruit
 * sous `ISSABEL_RECORDING_ROOT`, puis vérifié via `realpath` pour empêcher
 * toute sortie du répertoire (path traversal).
 */
class IssabelRecordingFetcher
{
    public function __construct(private readonly RecordingStorage $storage) {}

    public function retrieve(CallRecord $call): CallRecord
    {
        if ($call->provider !== 'issabel') {
            $call->update([
                'recording_state' => CallRecord::RECORDING_UNSUPPORTED,
                'recording_error' => 'La récupération n\'est prise en charge que pour Issabel.',
            ]);

            throw TelephonyException::unsupported('Récupération d\'enregistrement non prise en charge pour ce fournisseur.');
        }

        if ($call->hasStoredRecording() && Storage::disk($call->recording_disk)->exists($call->recording_path)) {
            return $call;
        }

        $root = trim((string) config('telephony.issabel.recording_root', ''));
        if ($root === '') {
            $call->update([
                'recording_state' => CallRecord::RECORDING_UNSUPPORTED,
                'recording_error' => 'Le répertoire des enregistrements Issabel n\'est pas configuré.',
            ]);

            throw TelephonyException::unsupported(
                'La récupération des enregistrements Issabel n\'est pas configurée (ISSABEL_RECORDING_ROOT).'
            );
        }

        $reference = str_replace('\\', '/', trim((string) $call->recording_provider_reference));
        if ($reference === ''
            || str_starts_with($reference, '/')
            || str_contains($reference, "\0")
            || preg_match('/^[A-Za-z]:/', $reference) === 1) {
            $call->update([
                'recording_state' => CallRecord::RECORDING_UNAVAILABLE,
                'recording_error' => 'Aucune référence de fichier audio exploitable dans le CDR.',
            ]);

            throw TelephonyException::recording('Aucune référence de fichier audio exploitable dans le CDR.');
        }

        if ($this->hasUnsafeSegment($reference)) {
            $call->update([
                'recording_state' => CallRecord::RECORDING_UNAVAILABLE,
                'recording_error' => 'Référence de fichier audio invalide.',
            ]);

            throw TelephonyException::recording('Référence de fichier audio invalide.');
        }

        $realRoot = realpath($root);
        if ($realRoot === false || ! is_dir($realRoot)) {
            $call->update([
                'recording_state' => CallRecord::RECORDING_UNSUPPORTED,
                'recording_error' => 'Le répertoire des enregistrements Issabel est introuvable ou illisible.',
            ]);

            throw TelephonyException::configuration(
                'Le répertoire des enregistrements Issabel est introuvable ou illisible. Vérifiez ISSABEL_RECORDING_ROOT.'
            );
        }

        [$source, $resolvedReference] = $this->resolveSource($realRoot, $reference, $call);

        if ($source === null) {
            $call->update([
                'recording_state' => CallRecord::RECORDING_MISSING,
                'recording_error' => 'Fichier audio absent du répertoire configuré.',
            ]);

            Log::warning('Enregistrement Issabel introuvable', [
                'call_id' => $call->id,
                'reference' => $reference,
            ]);

            throw TelephonyException::recording(
                'Fichier audio absent du répertoire configuré. Le CDR est conservé sans enregistrement.'
            );
        }

        try {
            $preferred = pathinfo($resolvedReference, PATHINFO_EXTENSION);
            $stored = $this->storage->storeFromPath($source, 'issabel', $preferred);

            $call->update([
                'recording_state' => CallRecord::RECORDING_STORED,
                'recording_disk' => $stored['disk'],
                'recording_path' => $stored['path'],
                'recording_mime' => $stored['mime'],
                'recording_size' => $stored['size'],
                'recording_error' => null,
                'recording_retrieved_at' => now(),
            ]);

            Log::info('Enregistrement Issabel récupéré', [
                'call_id' => $call->id,
                'reference' => $resolvedReference,
                'size' => $stored['size'],
                'mime' => $stored['mime'],
            ]);
        } catch (TelephonyException $e) {
            // Ne jamais écraser un enregistrement déjà valide.
            if (! $call->hasStoredRecording()) {
                $call->update([
                    'recording_state' => CallRecord::RECORDING_FAILED,
                    'recording_error' => mb_substr($e->getMessage(), 0, 500),
                ]);
            }

            throw $e;
        } catch (Throwable $e) {
            if (! $call->hasStoredRecording()) {
                $call->update([
                    'recording_state' => CallRecord::RECORDING_FAILED,
                    'recording_error' => 'Échec inattendu de la récupération. Consultez les journaux du backend.',
                ]);
            }

            throw TelephonyException::recording(
                'Échec inattendu de la récupération de l\'enregistrement. Consultez les journaux du backend.'
            );
        }

        return $call->refresh();
    }

    private function hasUnsafeSegment(string $reference): bool
    {
        foreach (explode('/', $reference) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * Résout le fichier réel sous la racine configurée.
     *
     * Deux emplacements bornés sont tentés, sans jamais parcourir de
     * répertoire :
     *  - le chemin relatif tel que fourni par le CDR ;
     *  - pour un simple nom de fichier, l'arborescence datée utilisée par
     *    Asterisk (`AAAA/MM/JJ/nom`), déduite de la date de l'appel.
     *
     * `realpath` vérifie ensuite que le résultat reste strictement sous la
     * racine configurée (protection contre le traversal et les liens sortants).
     *
     * @return array{0: string|null, 1: string} [chemin réel, référence résolue]
     */
    private function resolveSource(string $realRoot, string $reference, CallRecord $call): array
    {
        $prefix = rtrim($realRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $candidates = [$reference];

        if (! str_contains($reference, '/') && $call->started_at !== null) {
            $candidates[] = $call->started_at->format('Y/m/d').'/'.$reference;
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if ($this->hasUnsafeSegment($candidate)) {
                continue;
            }

            $source = realpath($realRoot.DIRECTORY_SEPARATOR.$candidate);

            if ($source !== false && str_starts_with($source, $prefix) && is_file($source) && is_readable($source)) {
                return [$source, $candidate];
            }
        }

        return [null, $reference];
    }
}
