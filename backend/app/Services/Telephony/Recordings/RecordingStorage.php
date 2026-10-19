<?php

declare(strict_types=1);

namespace App\Services\Telephony\Recordings;

use App\Services\Telephony\TelephonyException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stockage privé des enregistrements.
 *
 * Le fichier source est copié dans un fichier temporaire, vérifié (taille,
 * type MIME), puis écrit sous un nom opaque dans un répertoire privé. Un
 * échec ne remplace jamais un fichier déjà valide : le nom final est unique et
 * un fichier partiel est supprimé.
 */
class RecordingStorage
{
    private const MIME_EXTENSIONS = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/wave' => 'wav',
        'audio/ogg' => 'ogg',
        'audio/oga' => 'ogg',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/aac' => 'aac',
        'audio/webm' => 'webm',
        'application/octet-stream' => 'bin',
    ];

    /**
     * @return array{disk: string, path: string, mime: string, size: int, extension: string}
     */
    public function storeFromPath(string $sourcePath, string $provider, ?string $preferredExtension = null): array
    {
        $disk = (string) config('telephony.recordings.disk', 'local');
        $basePath = trim((string) config('telephony.recordings.path', 'recordings'), '/');

        if ($disk === 'public') {
            throw TelephonyException::configuration(
                'Le disque d\'enregistrement configuré est public. Utilisez un disque privé (local ou objet privé).'
            );
        }

        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw TelephonyException::recording(
                'Le fichier audio source est absent ou illisible. Vérifiez le répertoire d\'enregistrements Issabel.'
            );
        }

        $size = filesize($sourcePath);
        $maxBytes = (int) config('telephony.recordings.max_bytes', 50 * 1024 * 1024);

        if ($size === false || $size <= 0) {
            throw TelephonyException::recording('Le fichier audio source est vide.');
        }

        if ($size > $maxBytes) {
            throw TelephonyException::recording(
                'Le fichier audio dépasse la taille maximale autorisée ('.$maxBytes.' octets).'
            );
        }

        $mime = $this->mime($sourcePath);
        $allowed = array_map('strtolower', (array) config('telephony.recordings.mime_types', []));
        if ($allowed !== [] && ! in_array(strtolower($mime), $allowed, true)) {
            throw TelephonyException::recording(
                'Le type de fichier audio reçu n\'est pas autorisé ('.$mime.').'
            );
        }

        $extension = $this->extension($mime, $preferredExtension);
        $provider = preg_replace('/[^a-z0-9_-]/i', '', $provider) ?: 'provider';
        $relativePath = implode('/', array_filter([
            $basePath,
            $provider,
            now()->format('Y/m'),
            Str::uuid()->toString().'.'.$extension,
        ]));

        $temp = tempnam(sys_get_temp_dir(), 'telephony_');
        if ($temp === false) {
            throw TelephonyException::recording('Impossible de préparer un fichier temporaire pour l\'enregistrement.');
        }

        try {
            if (! copy($sourcePath, $temp)) {
                throw TelephonyException::recording('La copie du fichier audio a échoué.');
            }

            clearstatcache(true, $temp);
            $copiedSize = filesize($temp);
            if ($copiedSize === false || $copiedSize <= 0 || $copiedSize !== $size) {
                throw TelephonyException::recording('La copie du fichier audio est incomplète.');
            }

            $stream = fopen($temp, 'rb');
            if ($stream === false) {
                throw TelephonyException::recording('Le fichier audio temporaire ne peut pas être relu.');
            }

            try {
                $written = Storage::disk($disk)->put($relativePath, $stream);
            } finally {
                fclose($stream);
            }

            if (! $written || ! Storage::disk($disk)->exists($relativePath)) {
                Storage::disk($disk)->delete($relativePath);

                throw TelephonyException::recording('L\'écriture du fichier audio dans le stockage privé a échoué.');
            }

            $storedSize = Storage::disk($disk)->size($relativePath);
            if ($storedSize !== $size) {
                Storage::disk($disk)->delete($relativePath);

                throw TelephonyException::recording('Le fichier audio stocké est incomplet.');
            }

            return [
                'disk' => $disk,
                'path' => $relativePath,
                'mime' => $mime,
                'size' => $storedSize,
                'extension' => $extension,
            ];
        } catch (TelephonyException $e) {
            throw $e;
        } catch (Throwable $e) {
            if (isset($relativePath) && Storage::disk($disk)->exists($relativePath)) {
                Storage::disk($disk)->delete($relativePath);
            }

            throw TelephonyException::recording(
                'Échec inattendu lors du stockage de l\'enregistrement. Consultez les journaux du backend.'
            );
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    public function delete(string $disk, string $path): void
    {
        if ($disk === 'public') {
            return;
        }

        Storage::disk($disk)->delete($path);
    }

    private function mime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }

        try {
            $mime = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }

        return is_string($mime) && $mime !== '' ? strtolower($mime) : 'application/octet-stream';
    }

    private function extension(string $mime, ?string $preferred): string
    {
        $mime = strtolower($mime);
        if ($mime !== 'application/octet-stream' && isset(self::MIME_EXTENSIONS[$mime])) {
            return self::MIME_EXTENSIONS[$mime];
        }

        $preferred = strtolower(trim((string) $preferred, ". \t\n\r\0\x0B"));

        return in_array($preferred, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'webm'], true)
            ? $preferred
            : (self::MIME_EXTENSIONS[$mime] ?? 'bin');
    }
}
