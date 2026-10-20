<?php

declare(strict_types=1);

namespace App\Services\Telephony\Issabel;

use App\Services\Telephony\CallSearchQuery;
use App\Services\Telephony\TelephonyException;
use App\Services\Telephony\TelephonyNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        $selected = array_values(array_intersect([
            'calldate', 'uniqueid', 'linkedid', 'src', 'dst',
            'duration', 'billsec', 'disposition', 'recordingfile',
            (string) config('telephony.issabel.agent_column'),
            (string) config('telephony.issabel.direction_column'),
        ], $columns));

        $variants = TelephonyNumber::candidates($query->e164);

        try {
            $rows = DB::connection($connection)
                ->table($table)
                ->select($selected)
                ->where('calldate', '>=', $query->from->toDateTimeString())
                ->where('calldate', '<=', $query->to->toDateTimeString())
                ->where(function ($where) use ($variants): void {
                    $where->whereIn('src', $variants)->orWhereIn('dst', $variants);
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

        return $matches;
    }

    private function assertEnabled(): void
    {
        if (! (bool) config('telephony.issabel.enabled', false)) {
            throw TelephonyException::configuration(
                'Issabel n\'est pas activé. Renseignez ISSABEL_ENABLED=true et la connexion CDR.'
            );
        }

        $username = (string) config('database.connections.issabel.username', '');
        $host = (string) config('database.connections.issabel.host', '');

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

        if (! Schema::connection($connection)->hasTable($table)) {
            throw TelephonyException::configuration(
                'Table CDR Issabel introuvable. Vérifiez ISSABEL_CDR_TABLE et la base asteriskcdrdb.'
            );
        }

        $columns = Schema::connection($connection)->getColumnListing($table);

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
