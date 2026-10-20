<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Échec de lecture Google Sheets.
 *
 * Les messages sont destinés à l'utilisateur final : ils décrivent l'action à
 * mener (configurer une variable, partager le classeur, réessayer) sans jamais
 * exposer de secret, de chemin de fichier d'identifiants ni de réponse brute
 * de l'API.
 */
class GoogleSheetsException extends RuntimeException
{
    /** @param list<string> $variables */
    public static function missingConfiguration(array $variables, string $detail = ''): self
    {
        $message = 'Configuration Google Sheets incomplète : renseignez '
            .implode(', ', $variables)
            .' dans le fichier .env du backend.';

        if ($detail !== '') {
            $message .= ' '.$detail;
        }

        return new self($message);
    }

    public static function unreadableCredentials(): self
    {
        return new self(
            'Le fichier d\'identifiants Google (GOOGLE_APPLICATION_CREDENTIALS) est introuvable '
            .'ou illisible. Vérifiez le chemin et les droits de lecture.'
        );
    }

    public static function invalidCredentials(): self
    {
        return new self(
            'Les identifiants Google sont invalides : compte de service refusé. '
            .'Vérifiez le JSON (client_email, private_key) et que l\'API Google Sheets est activée.'
        );
    }

    public static function forbidden(): self
    {
        return new self(
            'Accès refusé au classeur Google Sheets (403). Partagez la feuille avec l\'adresse '
            .'e-mail du compte de service (client_email) en lecture.'
        );
    }

    public static function unauthorized(): self
    {
        return new self(
            'Authentification Google refusée (401). Vérifiez que le compte de service est autorisé '
            .'et que l\'API Google Sheets est activée sur le projet.'
        );
    }

    public static function spreadsheetNotFound(): self
    {
        return new self(
            'Classeur Google Sheets introuvable (404) ou plage de lecture invalide. '
            .'Vérifiez GOOGLE_SHEETS_SPREADSHEET_ID et GOOGLE_SHEETS_RANGE.'
        );
    }

    public static function rateLimited(): self
    {
        return new self(
            'Quota Google Sheets temporairement dépassé (429). La synchronisation a été interrompue '
            .'sans modifier les données. Réessayez dans quelques minutes.'
        );
    }

    public static function apiError(int $status, ?string $detail = null): self
    {
        $message = 'Erreur de l\'API Google Sheets (HTTP '.$status.'). Réessayez plus tard '
            .'ou consultez les journaux du backend pour le détail.';

        if ($detail !== null && $detail !== '') {
            $message .= ' Détail (raw) : '.substr(preg_replace('/\s+/', ' ', $detail), 0, 2000).'.';
            self::reportRawBodyIfVerbose($status, $detail);
        }

        return new self($message);
    }

    /**
     * Logue le corps brut de la réponse dans le journal de debug quand le
     * niveau de log le permet, sans jamais l'exposer dans le message utilisateur.
     */
    private static function reportRawBodyIfVerbose(int $status, ?string $detail): void
    {
        if ($detail === null || $detail === '') {
            return;
        }

        try {
            $decoded = json_decode($detail, true);
            $pretty = is_array($decoded)
                ? json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                : $detail;

            $channel = (string) config('logging.default', 'stack');
            $level = $channel === 'none' || $channel === 'single'
                ? 'warning'
                : 'debug';

            /** @var array<string, mixed> $context */
            $context = [
                'http_status' => $status,
                'raw_body_preview' => substr($pretty, 0, 2000),
            ];

            if (str_contains($detail, 'error')) {
                $context['google_error'] = $decoded['error'] ?? null;
            }

            Log::channel('debug_with_body')->$level('Erreur API Google Sheets (corps brut)', $context);
        } catch (\Throwable $e) {
            $channel = (string) config('logging.default', 'stack');
            $level = $channel === 'none' || $channel === 'single'
                ? 'warning'
                : 'debug';

            Log::channel('debug_with_body')->$level('Erreur API Google Sheets (corps non lisible)', [
                'http_status' => $status,
                'raw_body_preview' => substr($detail, 0, 2000),
            ]);
        }
    }

    public static function unreachable(): self
    {
        return new self(
            'Google Sheets est injoignable (réseau ou délai dépassé). Vérifiez la connectivité '
            .'du serveur puis relancez la synchronisation.'
        );
    }

    public static function malformedResponse(): self
    {
        return new self(
            'Réponse inattendue de l\'API Google Sheets : aucune ligne exploitable n\'a été reçue.'
        );
    }
}
