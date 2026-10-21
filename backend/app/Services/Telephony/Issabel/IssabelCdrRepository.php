<?php

declare(strict_types=1);

namespace App\Services\Telephony\Issabel;

use App\Services\Telephony\CallSearchQuery;
use App\Services\Telephony\TelephonyException;
use App\Services\Telephony\TelephonyNumber;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lecture des CDR Issabel via une connexion MySQL dédiée, en lecture seule.
 *
 * Rien n'est supposé sur le schéma au-delà des colonnes standard Asterisk
 * (`calldate`, `src`, `dst`, `uniqueid`). Les colonnes optionnelles sont
 * détectées avant la requête ; une installation sans `recordingfile` renvoie
 * simplement des appels sans référence d'enregistrement. Aucune écriture,
 * aucun `UPDATE`/`DELETE` n'est effectué.
 */
class IssabelCdrRepository implements IssabelCdrReader
{
    /** @var array<int, list<string>> */
    private array $columnCache = [];

    /** @return list<array<string, mixed>> */
    public function search(CallSearchQuery $query): array
    {
        $this->assertEnabled();

        $connection = (string) config('telephony.issabel.cdr_connection', 'issabel');
        $table = (string) config('telephony.issabel.cdr_table', 'cdr');

        Log::info('Recherche CDR Issabel demandée', [
            'connection' => $connection,
            'table' => $table,
            'phone' => TelephonyNumber::mask($query->e164),
            'from' => $query->from->toDateTimeString(),
            'to' => $query->to->toDateTimeString(),
            'limit' => $query->limit,
        ]);

        try {
            $columns = $this->columns($connection, $table);
        } catch (TelephonyException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->mapConnectionError($e);
        }

        foreach (['calldate', 'src', 'dst'] as $required) {
            if (! in_array($required, $columns, true)) {
                throw TelephonyException::configuration(
                    'La table CDR Issabel configurée ne contient pas la colonne « '.$required
                    .' ». Vérifiez ISSABEL_CDR_TABLE et le schéma asteriskcdrdb.'
                );
            }
        }

        // Seules les colonnes réellement présentes sont sélectionnées : une
        // colonne optionnelle absente ne doit jamais faire échouer la requête.
        $selected = array_values(array_intersect([
            'calldate', 'uniqueid', 'linkedid', 'src', 'dst',
            'duration', 'billsec', 'disposition', 'recordingfile',
            (string) config('telephony.issabel.agent_column'),
            (string) config('telephony.issabel.direction_column'),
        ], $columns));

        // Les CDR stockent souvent le numéro avec des séparateurs (« 06 12 34
        // 56 78 », « +33 6 12 34 56 78 », « 06.12.34.56.78 »). Une égalité SQL
        // brute raterait ces appels : on compare la forme « chiffres seuls »
        // des colonnes fournisseur aux variantes E.164/nationales connues.
        // Les valeurs restent des paramètres liés, jamais concaténées.
        $variants = $this->digitVariants($query->e164);

        try {
            $rows = DB::connection($connection)
                ->table($table)
                ->select($selected)
                ->where('calldate', '>=', $query->from->toDateTimeString())
                ->where('calldate', '<=', $query->to->toDateTimeString())
                ->where(function ($where) use ($variants): void {
                    $where->whereIn($this->digitsOnly('src'), $variants)
                        ->orWhereIn($this->digitsOnly('dst'), $variants);
                })
                ->orderByDesc('calldate')
                ->limit($query->limit)
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        } catch (QueryException $e) {
            throw $this->mapConnectionError($e);
        } catch (Throwable $e) {
            throw $this->mapConnectionError($e);
        }

        $matches = [];
        foreach ($rows as $row) {
            if ($this->rowMatches($row, $query->e164)) {
                $matches[] = $row;
            }
        }

        Log::info('Recherche CDR Issabel terminée', [
            'phone' => TelephonyNumber::mask($query->e164),
            'rows' => count($rows),
            'matches' => count($matches),
        ]);

        return $matches;
    }

    /**
     * Variantes « chiffres seuls » utilisées dans la clause SQL.
     *
     * @return list<string>
     */
    private function digitVariants(string $e164): array
    {
        $variants = [];

        foreach (TelephonyNumber::candidates($e164) as $candidate) {
            $digits = preg_replace('/\D+/', '', $candidate) ?? '';
            if ($digits !== '') {
                $variants[] = $digits;
            }
        }

        return array_values(array_unique($variants));
    }

    /**
     * Expression SQL qui supprime les séparateurs usuels d'un numéro. Aucune
     * valeur utilisateur n'y est interpolée : seuls les noms de colonnes, tous
     * issus de la configuration applicative, sont utilisés.
     */
    private function digitsOnly(string $column): Expression
    {
        $expression = $column;

        foreach ([' ', '-', '.', '(', ')', '+', '/'] as $separator) {
            $expression = "REPLACE({$expression}, '{$separator}', '')";
        }

        return DB::raw($expression);
    }

    private function assertEnabled(): void
    {
        if (! (bool) config('telephony.issabel.enabled', false)) {
            throw TelephonyException::configuration(
                'Issabel n\'est pas activé. Renseignez ISSABEL_ENABLED=true et la connexion CDR.'
            );
        }

        $connection = (string) config('telephony.issabel.cdr_connection', 'issabel');
        $username = (string) config("database.connections.{$connection}.username", '');
        $host = (string) config("database.connections.{$connection}.host", '');

        if ($username === '' || $host === '') {
            throw TelephonyException::configuration(
                'Connexion CDR Issabel incomplète : renseignez ISSABEL_DB_HOST, '
                .'ISSABEL_DB_DATABASE et un compte MySQL en lecture seule.'
            );
        }
    }

    /** @return list<string> */
    private function columns(string $connection, string $table): array
    {
        $key = crc32($connection.'|'.$table);

        if (isset($this->columnCache[$key])) {
            return $this->columnCache[$key];
        }

        if (! IssabelCdrSchema::hasTable($connection, $table)) {
            throw TelephonyException::configuration(
                'Table CDR Issabel introuvable. Vérifiez ISSABEL_CDR_TABLE et la base asteriskcdrdb.'
            );
        }

        $columns = IssabelCdrSchema::columns($connection, $table);

        return $this->columnCache[$key] = array_values($columns);
    }

    /** @param array<string, mixed> $row */
    private function rowMatches(array $row, string $targetE164): bool
    {
        return TelephonyNumber::matches($this->string($row['src'] ?? null), $targetE164)
            || TelephonyNumber::matches($this->string($row['dst'] ?? null), $targetE164);
    }

    private function mapConnectionError(Throwable $e): TelephonyException
    {
        return IssabelConnectionErrors::map($e);
    }

    private function string(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
