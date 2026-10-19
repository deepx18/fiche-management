<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CallRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Téléchargement d'un enregistrement privé.
 *
 * Route réservée aux administrateurs actifs. Aucun chemin fourni par le
 * navigateur n'est utilisé : seuls le disque et le chemin validés en base
 * servent à lire le fichier.
 */
class CallRecordingController extends Controller
{
    public function show(CallRecord $call): StreamedResponse|JsonResponse
    {
        if (! $call->hasStoredRecording()) {
            return response()->json([
                'message' => 'Aucun enregistrement privé n\'est disponible pour cet appel.',
                'state' => $call->recording_state,
            ], 404);
        }

        $path = (string) $call->recording_path;
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            return response()->json(['message' => 'Chemin d\'enregistrement invalide.'], 404);
        }

        $disk = Storage::disk((string) $call->recording_disk);
        if (! $disk->exists($path)) {
            $call->update([
                'recording_state' => CallRecord::RECORDING_MISSING,
                'recording_error' => 'Fichier stocké introuvable.',
            ]);

            return response()->json([
                'message' => 'Le fichier audio n\'est plus présent dans le stockage privé.',
                'state' => CallRecord::RECORDING_MISSING,
            ], 410);
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $name = 'enregistrement-'.$call->id.($extension !== '' ? '.'.$extension : '');

        return $disk->download($path, $name, [
            'Content-Type' => $call->recording_mime ?: 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
