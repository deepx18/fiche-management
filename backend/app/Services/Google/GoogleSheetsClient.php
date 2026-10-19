<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Http;
use Throwable;

use function is_array;
use function is_bool;
use function is_string;
use function json_encode;
use function rawurlencode;
use function rtrim;
use function str_starts_with;
use function trim;

/**
 * Client Google Sheets minimal, en lecture seule.
 *
 * Il s'appuie sur l'**API officielle Google Sheets v4** (endpoint REST
 * `sheets.googleapis.com/v4`) via le client HTTP de Laravel, sans ajouter de
 * dépendance supplémentaire au projet : l'authentification serveur-à-serveur
 * par compte de service est réalisée avec un JWT RS256 signé localement
 * (openssl), échangé contre un jeton d'accès OAuth2.
 *
 * Portée demandée : `spreadsheets.readonly` uniquement (aucune écriture).
 * Les identifiants ne sont jamais journalisés ; seuls les codes d'erreur
 * HTTP le sont, à des fins de diagnostic.
 */
class GoogleSheetsClient implements SheetsReader
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SHEETS_API = 'https://sheets.googleapis.com/v4/spreadsheets';

    /** @var array<string, mixed>|null Cache du jeton pour la durée du process. */
    private ?array $tokenCache = null;

    public function rows(): array
    {
        return $this->readValues(config('production.range'));
    }

    /**
     * Lit une plage spécifique du classeur configuré, en lecture seule.
     *
     * Partie visible de l'API, extraite pour le debug (lecture de plage sans
     * mémoization du token ni écriture), tout en restant utilisée par
     * $this->rows().
     *
     * @return list<list<string|null>>
     */
    public function readValues(?string $range): array
    {
        $token = $this->accessToken();

        $spreadsheetId = (string) config('production.spreadsheet_id');
        if ($spreadsheetId === '') {
            throw GoogleSheetsException::missingConfiguration(['GOOGLE_SHEETS_SPREADSHEET_ID']);
        }

        $resolvedRange = $range ?? (string) config('production.range');
        $url = self::SHEETS_API.'/'.rawurlencode($spreadsheetId).'/values/'.rawurlencode($resolvedRange);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('production.timeout', 30))
                ->get($url, ['majorDimension' => 'ROWS']);
        } catch (Throwable $e) {
            report($e);

            throw GoogleSheetsException::unreachable();
        }

        $status = $response->status();

        if ($status === 401) {
            throw GoogleSheetsException::unauthorized();
        }
        if ($status === 403) {
            throw GoogleSheetsException::forbidden();
        }
        if ($status === 404) {
            throw GoogleSheetsException::spreadsheetNotFound();
        }
        if (! $response->successful()) {
            $detail = $response->successful() && ($body = $response->body()) ? trim($body) : null;
            throw GoogleSheetsException::apiError($status, $detail);
        }

        $values = $response->json('values');
        if ($values === null) {
            return [];
        }
        $rows = [];
        foreach ($values as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = array_map(static function (mixed $cell): ?string {
                if ($cell === null || is_bool($cell) || is_array($cell)) {
                    return null;
                }
                $value = trim((string) $cell);

                return $value === '' ? null : $value;
            }, $row);
        }

        return $rows;
    }

    /** Échange le JWT du compte de service contre un jeton d'accès OAuth2. */
    private function accessToken(): string
    {
        if ($this->tokenCache !== null && ($this->tokenCache['expires_at'] ?? 0) > time() + 30) {
            return (string) $this->tokenCache['token'];
        }

        $credentials = $this->credentials();
        $assertion = $this->signedAssertion($credentials);

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('production.timeout', 30))
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);
        } catch (Throwable $e) {
            report($e);

            throw GoogleSheetsException::unreachable();
        }

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw GoogleSheetsException::invalidCredentials();
        }

        $token = (string) $response->json('access_token');
        $this->tokenCache = [
            'token' => $token,
            'expires_at' => time() + max(60, (int) $response->json('expires_in', 3600) - 60),
        ];

        return $token;
    }

    /**
     * Charge et valide le JSON de compte de service.
     *
     * @return array{client_email: string, private_key: string}
     */
    private function credentials(): array
    {
        $path = (string) config('production.credentials_path');

        if ($path === '') {
            throw GoogleSheetsException::missingConfiguration(
                ['GOOGLE_SHEETS_SPREADSHEET_ID', 'GOOGLE_APPLICATION_CREDENTIALS']
            );
        }

        if ((string) config('production.spreadsheet_id') === '') {
            throw GoogleSheetsException::missingConfiguration(['GOOGLE_SHEETS_SPREADSHEET_ID']);
        }

        $resolved = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            ? $path
            : base_path($path);

        if (! is_file($resolved) || ! is_readable($resolved)) {
            throw GoogleSheetsException::unreadableCredentials();
        }

        $raw = file_get_contents($resolved);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($decoded)
            || ! is_string($decoded['client_email'] ?? null)
            || ! is_string($decoded['private_key'] ?? null)
        ) {
            throw GoogleSheetsException::invalidCredentials();
        }

        return [
            'client_email' => $decoded['client_email'],
            'private_key' => $decoded['private_key'],
        ];
    }

    /** @param array{client_email: string, private_key: string} $credentials */
    private function signedAssertion(array $credentials): string
    {
        $issuedAt = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $credentials['client_email'],
            'scope' => implode(' ', (array) config('production.scopes', ['https://www.googleapis.com/auth/spreadsheets.readonly'])),
            'aud' => self::TOKEN_URL,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ];

        $segments = $this->base64Url((string) json_encode($header))
            .'.'.$this->base64Url((string) json_encode($claims));

        $signature = '';
        $key = openssl_pkey_get_private($credentials['private_key']);
        if ($key === false || ! openssl_sign($segments, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw GoogleSheetsException::invalidCredentials();
        }

        return $segments.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
