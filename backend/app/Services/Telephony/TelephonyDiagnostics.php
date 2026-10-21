<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Services\Telephony\Issabel\IssabelCdrSchema;
use App\Services\Telephony\Issabel\IssabelConnectionErrors;
use Illuminate\Support\Facades\DB;
use Throwable;

final class TelephonyDiagnostics
{
    public const PROVIDERS = ['kavkom', 'issabel'];
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_ERROR = 'error';

    public function run(string $provider): array
    {
        $started = microtime(true);
        $checks = match ($provider) {
            'kavkom' => $this->kavkom(),
            'issabel' => $this->issabel(),
        };

        return [
            'provider' => $provider,
            'status' => $this->aggregate($checks),
            'checked_at' => now()->toISOString(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'checks' => $checks,
        ];
    }

    private function kavkom(): array
    {
        $enabled = (bool) config('telephony.kavkom.webhook_enabled', false);
        $secret = trim((string) config('telephony.kavkom.webhook_secret', ''));
        $header = trim((string) config('telephony.kavkom.webhook_header', ''));
        $effectiveHeader = $header !== '' ? $header : 'X-Kavkom-Webhook-Token';

        return [
            $this->check('webhook_enabled', 'Webhook activé',
                $enabled ? self::STATUS_OK : self::STATUS_WARNING,
                $enabled ? 'KAVKOM_WEBHOOK_ENABLED=true : les CDR poussés par Kavkom sont acceptés.'
                    : 'KAVKOM_WEBHOOK_ENABLED=false : le webhook répond 404 et aucun CDR Kavkom n\'est ingéré.'),
            $this->check('webhook_secret', 'Secret partagé',
                $secret !== '' ? self::STATUS_OK : self::STATUS_ERROR,
                $secret !== '' ? 'KAVKOM_WEBHOOK_SECRET est renseigné (valeur jamais affichée).'
                    : 'KAVKOM_WEBHOOK_SECRET est vide : le webhook répond 503 et tous les CDR sont refusés.'),
            $this->check('webhook_header', 'En-tête attendu',
                $header !== '' ? self::STATUS_OK : self::STATUS_WARNING,
                $header !== '' ? 'En-tête attendu : '.$header.'.'
                    : 'KAVKOM_WEBHOOK_HEADER est vide : l\'en-tête par défaut '.$effectiveHeader.' est utilisé.'),
            $this->check('endpoint', 'Endpoint d\'ingestion',
                self::STATUS_OK,
                'POST /api/webhooks/kavkom/cdr (authentification par le secret ci-dessus).'),
            $this->check('capabilities', 'Capacités',
                self::STATUS_OK,
                'Ingestion : webhook CDR. Recherche d\'historique : non documentée par Kavkom '
                .'(état « unsupported » attendu, jamais « aucun appel »).'),
        ];
    }

    private function issabel(): array
    {
        if (! (bool) config('telephony.issabel.enabled', false)) {
            return [$this->check('enabled', 'Activation', self::STATUS_WARNING,
                'ISSABEL_ENABLED=false : la recherche et la récupération Issabel sont désactivées. '
                .'Aucune connexion n\'a été tentée.')];
        }

        $checks = [$this->check('enabled', 'Activation', self::STATUS_OK, 'ISSABEL_ENABLED=true.')];
        $connection = (string) config('telephony.issabel.cdr_connection', 'issabel');
        $table = (string) config('telephony.issabel.cdr_table', 'cdr');

        $host = trim((string) config("database.connections.{$connection}.host", ''));
        $database = trim((string) config("database.connections.{$connection}.database", ''));
        $username = trim((string) config("database.connections.{$connection}.username", ''));

        $missing = [];
        foreach (['hôte' => $host, 'base' => $database, 'utilisateur' => $username] as $label => $value) {
            if ($value === '') { $missing[] = $label; }
        }

        if ($missing !== []) {
            $checks[] = $this->check('connection_config', 'Configuration CDR', self::STATUS_ERROR,
                'Connexion CDR incomplète : renseignez '.implode(', ', $missing)
                .' (ISSABEL_DB_HOST, ISSABEL_DB_DATABASE, ISSABEL_DB_USERNAME).',
                TelephonyException::KIND_CONFIGURATION);
            return $checks;
        }

        $checks[] = $this->check('connection_config', 'Configuration CDR', self::STATUS_OK,
            'Connexion « '.$connection.' » vers '.$database.'@'.$host.' (identifiants jamais affichés).');

        $started = microtime(true);
        try { DB::connection($connection)->getPdo(); }
        catch (Throwable $e) { return [...$checks, $this->failure('connection', 'Connexion MySQL', $e)]; }

        $checks[] = $this->check('connection', 'Connexion MySQL', self::STATUS_OK,
            'Connexion établie en '.(int) round((microtime(true) - $started) * 1000).' ms.');

        try { $hasTable = IssabelCdrSchema::hasTable($connection, $table); }
        catch (Throwable $e) { return [...$checks, $this->failure('table', 'Table CDR', $e)]; }

        if (! $hasTable) {
            $checks[] = $this->check('table', 'Table CDR', self::STATUS_ERROR,
                'Table « '.$table.' » introuvable dans la base configurée. Vérifiez ISSABEL_CDR_TABLE.',
                TelephonyException::KIND_CONFIGURATION);
            return $checks;
        }

        $checks[] = $this->check('table', 'Table CDR', self::STATUS_OK,
            'Table « '.$table.' » présente dans la base configurée.');

        $requiredColumns = ['calldate', 'src', 'dst'];

        try {
            $actualColumns = IssabelCdrSchema::columns($connection, $table);
        } catch (Throwable $e) {
            return [...$checks, $this->failure('columns', 'Colonnes CDR', $e)];
        }

        $missingRequired = array_values(array_diff($requiredColumns, $actualColumns));

        if ($missingRequired !== []) {
            $checks[] = $this->check('columns', 'Colonnes CDR', self::STATUS_ERROR,
                'La table « '.$table.' » ne contient pas les colonnes requises : '
                .implode(', ', $missingRequired).'.',
                TelephonyException::KIND_CONFIGURATION);
            return $checks;
        }

        $checks[] = $this->check('columns', 'Colonnes CDR', self::STATUS_OK,
            'Colonnes requises présentes (calldate, src, dst).');

        $checks[] = $this->optionalColumns($actualColumns);

        try { $latest = DB::connection($connection)->table($table)->max('calldate'); }
        catch (Throwable $e) { return [...$checks, $this->failure('latest_cdr', 'Dernier CDR', $e)]; }

        $checks[] = $this->check('latest_cdr', 'Dernier CDR',
            $latest === null ? self::STATUS_WARNING : self::STATUS_OK,
            $latest === null ? 'Aucun CDR dans la table : la connexion fonctionne, mais aucune donnée n\'est encore disponible.'
                : 'Dernier CDR enregistré : '.$this->dateValue($latest).'.');

        $root = trim((string) config('telephony.issabel.recording_root', ''));
        if ($root === '') {
            $checks[] = $this->check('recording_root', 'Répertoire des enregistrements', self::STATUS_WARNING,
                'ISSABEL_RECORDING_ROOT n\'est pas configuré : la récupération audio reste indisponible.');
        } elseif (! is_dir($root)) {
            $checks[] = $this->check('recording_root', 'Répertoire des enregistrements', self::STATUS_ERROR,
                'Répertoire configuré introuvable ou montage indisponible. Vérifiez ISSABEL_RECORDING_ROOT '
                .'(montage en lecture seule ; un partage déconnecté répond « Input/output error »).',
                TelephonyException::KIND_CONFIGURATION);
        } elseif (! is_readable($root)) {
            $checks[] = $this->check('recording_root', 'Répertoire des enregistrements', self::STATUS_ERROR,
                'Répertoire configuré non lisible par le processus Laravel.',
                TelephonyException::KIND_PERMISSION);
        } else {
            $checks[] = $this->check('recording_root', 'Répertoire des enregistrements', self::STATUS_OK,
                'Répertoire lisible (lecture seule).');
        }

        return $checks;
    }

    /**
     * @param  list<string>  $actualColumns  colonnes réellement présentes
     */
    private function optionalColumns(array $actualColumns): array
    {
        $standard = ['uniqueid', 'linkedid', 'duration', 'billsec', 'disposition', 'recordingfile'];
        $present = array_values(array_intersect($standard, $actualColumns));

        $configured = [
            'agent' => strtolower(trim((string) config('telephony.issabel.agent_column', ''))),
            'direction' => strtolower(trim((string) config('telephony.issabel.direction_column', ''))),
        ];

        $declaredMissing = [];

        foreach ($configured as $label => $column) {
            if ($column === '') {
                continue;
            }

            if (! in_array($column, $actualColumns, true)) {
                $declaredMissing[] = $label.' ('.$column.')';
            }
        }

        if ($declaredMissing !== []) {
            return $this->check('optional_columns', 'Colonnes optionnelles', self::STATUS_WARNING,
                'Colonne(s) configurée(s) mais absente(s) de la table CDR : '
                .implode(', ', $declaredMissing).'. Laissez la variable concernée vide.');
        }

        if (! in_array('recordingfile', $actualColumns, true)) {
            return $this->check('optional_columns', 'Colonnes optionnelles', self::STATUS_WARNING,
                'Aucune colonne « recordingfile » dans la table CDR : les appels seront trouvés, '
                .'mais aucun enregistrement ne pourra être récupéré.'
                .($present === [] ? '' : ' Colonnes détectées : '.implode(', ', $present).'.'));
        }

        return $this->check('optional_columns', 'Colonnes optionnelles', self::STATUS_OK,
            $present === [] ? 'Aucune colonne optionnelle détectée : des appels seront lus avec les seules colonnes requises.'
                : 'Détectées : '.implode(', ', $present).'.');
    }

    private function failure(string $key, string $label, Throwable $e): array
    {
        $mapped = IssabelConnectionErrors::map($e);
        return $this->check($key, $label, self::STATUS_ERROR, $mapped->getMessage(), $mapped->kind());
    }

    private function aggregate(array $checks): string
    {
        $statuses = array_column($checks, 'status');
        if (in_array(self::STATUS_ERROR, $statuses, true)) { return self::STATUS_ERROR; }
        if (in_array(self::STATUS_WARNING, $statuses, true)) { return self::STATUS_WARNING; }
        return self::STATUS_OK;
    }

    private function check(string $key, string $label, string $status, string $message, ?string $code = null): array
    {
        $check = ['key' => $key, 'label' => $label, 'status' => $status, 'message' => $message];
        if ($code !== null) { $check['code'] = $code; }
        return $check;
    }

    private function dateValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) { return $value->format('Y-m-d H:i:s'); }
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
