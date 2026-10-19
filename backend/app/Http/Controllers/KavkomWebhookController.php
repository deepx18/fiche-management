<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Telephony\CallPersister;
use App\Services\Telephony\Kavkom\KavkomCdrParser;
use App\Services\Telephony\ProductionPhoneMatcher;
use App\Services\Telephony\TelephonyException;
use App\Services\Telephony\TelephonyNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Ingestion des CDR Kavkom poussés par webhook.
 *
 * Endpoint public au sens Sanctum, mais protégé par un secret partagé envoyé
 * dans un en-tête personnalisé (configuré côté Kavkom). Le payload n'est
 * jamais journalisé ni renvoyé. Un événement temps réel sans identifiant CDR
 * est ignoré ; un CDR est écrit de façon idempotente.
 */
class KavkomWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        KavkomCdrParser $parser,
        ProductionPhoneMatcher $matcher,
        CallPersister $persister,
    ): JsonResponse {
        if (! (bool) config('telephony.kavkom.webhook_enabled', false)) {
            return response()->json(['message' => 'Webhook Kavkom désactivé.'], 404);
        }

        $secret = (string) config('telephony.kavkom.webhook_secret', '');
        if ($secret === '') {
            return response()->json([
                'message' => 'Webhook Kavkom non configuré. Renseignez KAVKOM_WEBHOOK_SECRET.',
            ], 503);
        }

        $header = (string) config('telephony.kavkom.webhook_header', 'X-Kavkom-Webhook-Token');
        $provided = (string) $request->header($header, '');

        if ($provided === '' || ! hash_equals($secret, $provided)) {
            return response()->json(['message' => 'En-tête d\'authentification du webhook invalide.'], 401);
        }

        $content = trim($request->getContent());

        if ($content === '') {
            // Kavkom envoie une requête vide lors du test de l'endpoint.
            return response()->json(['status' => 'ignored'], 202);
        }

        try {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return response()->json(['message' => 'Corps JSON invalide.'], 422);
        }

        if (! is_array($payload) || $payload === []) {
            // Kavkom envoie une requête vide lors du test de l'endpoint.
            return response()->json(['status' => 'ignored'], 202);
        }

        try {
            $call = $parser->parse($payload);
        } catch (TelephonyException $e) {
            Log::warning('Webhook Kavkom rejeté', [
                'code' => $e->kind(),
                'payload_hash' => substr(hash('sha256', (string) $request->getContent()), 0, 16),
            ]);

            return response()->json(['message' => 'CDR Kavkom inexploitable.'], 422);
        }

        if ($call === null) {
            return response()->json(['status' => 'ignored'], 202);
        }

        $direction = strtolower(trim((string) $call->direction));
        $directionIsKnown = in_array($direction, ['in', 'out', 'inbound', 'outbound'], true);
        $e164 = $directionIsKnown ? TelephonyNumber::french($call->rawPhone) : null;
        $record = null;
        $basis = $directionIsKnown ? 'webhook' : 'ambiguous';

        if ($e164 !== null) {
            $match = $matcher->find($e164);
            $record = $match['record'];
            if ($match['ambiguous']) {
                $basis = 'ambiguous';
            }
        }

        $persister->persistOne($call, $record, 'kavkom', $basis);

        Log::info('CDR Kavkom ingéré', [
            'provider_call_id' => substr(hash('sha256', $call->providerCallId), 0, 16),
            'matched' => $record !== null,
            'ambiguous' => $basis === 'ambiguous',
        ]);

        return response()->json(['status' => 'accepted'], 202);
    }
}
