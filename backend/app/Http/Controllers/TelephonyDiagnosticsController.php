<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Telephony\TelephonyDiagnostics;
use Illuminate\Http\JsonResponse;

/**
 * Diagnostic de connexion téléphonie, fournisseur par fournisseur.
 *
 * Permet de tester Issabel et Kavkom indépendamment, sans lancer de recherche
 * et sans commande artisan. Lecture seule : aucun CDR n'est créé, aucun secret
 * n'est renvoyé. Réservé aux administrateurs actifs (middleware `admin`).
 */
final class TelephonyDiagnosticsController extends Controller
{
    public function show(string $provider, TelephonyDiagnostics $diagnostics): JsonResponse
    {
        if (! in_array($provider, TelephonyDiagnostics::PROVIDERS, true)) {
            return response()->json([
                'message' => 'Fournisseur téléphonie inconnu. Fournisseurs disponibles : '
                    .implode(', ', TelephonyDiagnostics::PROVIDERS).'.',
            ], 404);
        }

        return response()->json(['data' => $diagnostics->run($provider)]);
    }
}
