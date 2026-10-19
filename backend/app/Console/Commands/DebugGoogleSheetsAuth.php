<?php

namespace App\Console\Commands;

use App\Services\Google\GoogleSheetsClient;
use App\Services\Google\GoogleSheetsException;
use Illuminate\Console\Command;

/**
 * Vérifie, sans synchroniser aucune donnée, que le compte de service Google
 * est correctement configuré et capable d'obtenir un jeton d'accès OAuth2.
 *
 * Utile pour dépanner les échecs 401/403/404 avant le premier vrai sync.
 *
 * Ce command ne lit jamais les lignes du classeur : elle s'arrête après
 * l'échange de tokens.
 */
final class DebugGoogleSheetsAuth extends Command
{
    protected $signature = 'google-sheets:auth:debug
        {--dump : affiche la configuration lue (sans secret)}
        {--range= : plage A1 à lire en lecture seule pour vérifier l\'accès au classeur (ex. Production!A1:F5)}';

    protected $description = 'Vérifie la configuration Google Sheets, le refresh de token et, optionnellement, la lecture d\'une plage réelle du classeur (sans écrire aucune ligne)';

    public function handle(GoogleSheetsClient $client): int
    {
        if ($this->option('dump')) {
            $this->line('[config]');
            foreach ([
                'spreadsheet_id' => config('production.spreadsheet_id'),
                'range' => config('production.range'),
                'credentials_path' => config('production.credentials_path'),
                'header_row' => config('production.header_row'),
                'timeout' => config('production.timeout'),
                'scopes' => config('production.scopes'),
            ] as $key => $value) {
                $display = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
                $display = $display === '' ? '(vide)' : $display;
                $this->line(sprintf('  %-22s %s', $key, $display));
            }
            $this->newLine();
        }

        $credentialsPath = (string) config('production.credentials_path');
        $spreadsheetId = (string) config('production.spreadsheet_id');
        $diagnosticRange = (string) $this->option('range');

        if ($credentialsPath === '') {
            $this->error('GOOGLE_APPLICATION_CREDENTIALS est vide dans .env.');
            $this->newLine();
            $this->info('Définissez le chemin du JSON de compte de service, relatif à backend/ ou absolu.');

            return self::FAILURE;
        }

        if ($spreadsheetId === '') {
            $this->warn('GOOGLE_SHEETS_SPREADSHEET_ID est vide : le token sera vérifié mais aucun classeur n’est configuré.');
            $this->newLine();
        } else {
            $this->info('Classeur configuré : '.$spreadsheetId);
        }

        // Vérification du fichier (même logique que GoogleSheetsClient, sans
        // déduire le résolu ici pour ne pas dupliquer la logique de chemin).
        $this->checkCredentialsFile($credentialsPath);

        $this->newLine();
        $this->info('Échange de token OAuth2 en cours…');

        try {
            // On n’appelle pas $client->rows() (plage configurée, lecture
            // complète). On vérifie l’authentification via l’accès au classeur
            // sur une plage explicite (option --range) ou, à défaut, sur la
            // plage par défaut du classeur (lecture minimale).
            $testedRange = $diagnosticRange !== '' ? $diagnosticRange : 'A1:A1';

            if ($diagnosticRange !== '' && $spreadsheetId !== '') {
                $this->info('Lecture de la plage spécifiée : '.$diagnosticRange);
            } elseif ($spreadsheetId !== '') {
                $this->info('Lecture de la plage de contrôle : A1:A1 (présence du classeur)');
            }

            $rows = $client->readValues($testedRange);
        } catch (GoogleSheetsException $e) {
            $this->error('Accès au classeur échoué : '.$e->getMessage());
            $this->newLine();
            $this->info('Points de contrôle :');
            $this->info('  - API Google Sheets activée sur le projet GCP ?');
            $this->info('  - private_key valide dans le JSON (pem, pas de ligne vide) ?');
            $this->info('  - client_email du compte de service partagé sur le classeur (lecture) ?');
            $this->info('  - GOOGLE_SHEETS_SPREADSHEET_ID / GOOGLE_SHEETS_RANGE corrects ?');
            $this->info('  - Délai système du serveur cohérent (iat/exp) ?');

            // Si l’échec est post-token (403/404/…), l’authentification elle-même
            // est probablement OK : on le précise pour accélérer le debug.
            if ($spreadsheetId !== '' && $this->isPostTokenError($e)) {
                $this->newLine();
                $this->warn('Authentification OK — l’échec est post-token (partage / plage / projet).');
            }

            return self::FAILURE;
        } catch (\Throwable $e) {
            report($e);
            $this->error('Erreur inattendue lors de l\'accès au classeur.');
            $this->newLine();
            $this->info('Détail dans storage/logs/laravel.log (report($e)).');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Accès au classeur vérifié avec succès.');
        $this->line('  Plage testée : '.$testedRange);
        $this->line('  Lignes reçues : '.count($rows));

        if (count($rows) > 0) {
            $this->newLine();
            $this->info('Aperçu des 5 premières lignes (valeurs brutes) :');
            $display = array_slice($rows, 0, 5);
            foreach ($display as $i => $row) {
                $this->line(sprintf('  %d : [%s]', $i + 1, implode(', ', array_map(static fn (mixed $c): string => $c === null ? '' : (string) $c, $row))));
            }
        }

        $this->newLine();
        $this->warn('Ce command n\'a lu qu\'en lecture seule : aucune ligne n\'a été écrite dans le classeur ni dans la base.');
        $this->newLine();
        $this->info('Pour importer les lignes, lancez ensuite :');
        $this->info('  POST /api/production/sync  (ou bouton « Synchroniser Google Sheets »)');

        return self::SUCCESS;
    }

    private function checkCredentialsFile(string $path): void
    {
        $resolved = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\\\\\/]/', $path) === 1
            ? $path
            : base_path($path);

        if (! is_file($resolved)) {
            $this->error('Fichier d’identifiants introuvable : '.$resolved);
            $this->newLine();
            $this->info('Déposez le JSON de compte de service à ce chemin (hors versionnement).');
            throw new \RuntimeException('unreadable_credentials');
        }

        if (! is_readable($resolved)) {
            $this->error('Fichier d’identifiants non lisible : '.$resolved);
            throw new \RuntimeException('unreadable_credentials');
        }

        $raw = file_get_contents($resolved);
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            $this->error('Le fichier JSON n’est pas un JSON valide.');
            throw new \RuntimeException('invalid_credentials');
        }

        $clientEmail = $decoded['client_email'] ?? null;
        $privateKey = $decoded['private_key'] ?? null;
        $type = $decoded['type'] ?? null;
        $projectId = $decoded['project_id'] ?? null;

        if (! is_string($clientEmail) || ! is_string($privateKey)) {
            $this->error('Le JSON ne contient pas client_email et/ou private_key.');
            throw new \RuntimeException('invalid_credentials');
        }

        $this->line('[compte de service]');
        $this->line('  type        : '.($type ?? '(absent)'));
        $this->line('  project_id  : '.($projectId ?? '(absent)'));
        $this->line('  client_email: '.$clientEmail);
        $this->line('  private_key : '.($this->redactedKey($privateKey) ?: '(vide)'));

        if (trim($privateKey) === '') {
            $this->error('private_key vide dans le JSON.');
            throw new \RuntimeException('invalid_credentials');
        }

        $this->newLine();
        $this->info('Lecture du fichier : OK.');
    }

    /**
     * Une erreur post-token (partage / plage / projet) plutôt qu'une erreur
     * d'authentification (JWT / compte de service).
     */
    private function isPostTokenError(GoogleSheetsException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Accès refusé au classeur')
            || str_contains($message, 'Classeur Google Sheets introuvable')
            || str_contains($message, 'Erreur de l\'API Google Sheets')
            || str_contains($message, 'Google Sheets est injoignable')
            || str_contains($message, 'Réponse inattendue de l\'API Google Sheets');
    }

    private function redactedKey(string $key): string
    {
        if ($key === '') {
            return '';
        }

        $lines = explode("\n", $key);
        $head = (string) ($lines[0] ?? '');
        $tail = (string) ($lines[1] ?? '');

        if (str_starts_with($head, '-----BEGIN')) {
            return $head.'…'.($tail !== '' ? "\n… (key blocked)" : '');
        }

        return '**** (redacted)';
    }
}
