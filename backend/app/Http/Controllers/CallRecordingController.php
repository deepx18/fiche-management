<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CallRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lecture d'un enregistrement privé.
 *
 * Route réservée aux administrateurs actifs (middleware `admin`). Aucun
 * chemin fourni par le navigateur n'est utilisé : seuls le disque et le
 * chemin validés en base servent à lire le fichier. Le disque `public` n'est
 * jamais accepté.
 */
class CallRecordingController extends Controller
{
    private const CHUNK_SIZE = 64 * 1024;

    public function show(CallRecord $call): StreamedResponse|JsonResponse
    {
        $path = $this->storedPath($call);

        if ($path instanceof JsonResponse) {
            return $path;
        }

        $disk = Storage::disk((string) $call->recording_disk);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $name = 'enregistrement-'.$call->id.($extension !== '' ? '.'.$extension : '');

        return $disk->download($path, $name, [
            'Content-Type' => $call->recording_mime ?: 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Lecture intégrée (lecteur audio) avec prise en charge des requêtes
     * `Range`, sans charger tout le fichier en mémoire.
     */
    public function stream(Request $request, CallRecord $call): StreamedResponse|JsonResponse
    {
        $path = $this->storedPath($call);

        if ($path instanceof JsonResponse) {
            return $path;
        }

        $diskName = (string) $call->recording_disk;
        $disk = Storage::disk($diskName);
        $mime = $call->recording_mime ?: 'audio/wav';

        $size = (int) $disk->size($path);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $name = 'enregistrement-'.$call->id.($extension !== '' ? '.'.$extension : '');

        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
        ];

        // Un disque local permet un accès par plage efficace ; les autres
        // disques sont servis en flux complet plutôt que chargés en mémoire.
        $localPath = $diskName === 'local' ? $disk->path($path) : null;
        $range = $this->parseRange($request->header('Range'), $size);

        if ($range === null || $localPath === null) {
            $headers['Content-Length'] = (string) $size;

            return response()->stream(function () use ($disk, $path): void {
                $stream = $disk->readStream($path);

                if ($stream === false) {
                    return;
                }

                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            }, 200, $headers);
        }

        [$start, $end] = $range;
        $length = $end - $start + 1;

        $headers['Content-Length'] = (string) $length;
        $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$size;

        return response()->stream(function () use ($localPath, $start, $length): void {
            $stream = fopen($localPath, 'rb');

            if ($stream === false) {
                return;
            }

            try {
                fseek($stream, $start);
                $remaining = $length;

                while ($remaining > 0 && ! feof($stream)) {
                    $chunk = fread($stream, (int) min(self::CHUNK_SIZE, $remaining));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    echo $chunk;
                    $remaining -= strlen($chunk);
                }
            } finally {
                fclose($stream);
            }
        }, 206, $headers);
    }

    /**
     * @return string|JsonResponse chemin relatif validé, ou réponse d'erreur
     */
    private function storedPath(CallRecord $call): string|JsonResponse
    {
        if (! $call->hasStoredRecording()) {
            return response()->json([
                'message' => 'Aucun enregistrement privé n\'est disponible pour cet appel.',
                'state' => $call->recording_state,
            ], 404);
        }

        $diskName = (string) $call->recording_disk;
        if ($diskName === '' || $diskName === 'public') {
            return response()->json(['message' => 'Disque d\'enregistrement non privé.'], 404);
        }

        $path = (string) $call->recording_path;
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            return response()->json(['message' => 'Chemin d\'enregistrement invalide.'], 404);
        }

        if (! Storage::disk($diskName)->exists($path)) {
            $call->update([
                'recording_state' => CallRecord::RECORDING_MISSING,
                'recording_error' => 'Fichier stocké introuvable.',
            ]);

            return response()->json([
                'message' => 'Le fichier audio n\'est plus présent dans le stockage privé.',
                'state' => CallRecord::RECORDING_MISSING,
            ], 410);
        }

        return $path;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function parseRange(?string $header, int $size): ?array
    {
        if ($size <= 0 || $header === null) {
            return null;
        }

        if (preg_match('/^bytes=(\d*)-(\d*)$/i', trim($header), $matches) !== 1) {
            return null;
        }

        $startRaw = $matches[1];
        $endRaw = $matches[2];

        if ($startRaw === '' && $endRaw === '') {
            return null;
        }

        if ($startRaw === '') {
            // Suffixe : les N derniers octets.
            $suffix = (int) $endRaw;
            if ($suffix <= 0) {
                return null;
            }

            $start = max(0, $size - $suffix);
            $end = $size - 1;
        } else {
            $start = (int) $startRaw;
            $end = $endRaw === '' ? $size - 1 : (int) $endRaw;
        }

        if ($start > $end || $start >= $size) {
            return null;
        }

        return [$start, min($end, $size - 1)];
    }
}
