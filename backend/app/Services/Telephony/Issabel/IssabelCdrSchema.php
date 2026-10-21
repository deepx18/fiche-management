<?php

declare(strict_types=1);

namespace App\Services\Telephony\Issabel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inspection du schéma CDR Issabel, partagée par la lecture des appels et le
 * diagnostic de connexion.
 *
 * Aucun cache statique n'est conservé : une même requête peut interroger une
 * connexion remplacée (tests) et les colonnes doivent toujours refléter
 * l'installation réellement configurée.
 */
final class IssabelCdrSchema
{
    /** @return list<string> */
    public static function columns(string $connection, string $table): array
    {
        $driver = DB::connection($connection)->getDriverName();

        // `information_schema` est utilisé pour MySQL : il ne dépend pas de la
        // version du serveur ni des colonnes générées par le schéma builder.
        if ($driver === 'mysql') {
            $rows = DB::connection($connection)
                ->table('information_schema.columns')
                ->select('column_name')
                ->where('table_schema', '=', DB::connection($connection)->getDatabaseName())
                ->where('table_name', '=', $table)
                ->orderBy('ordinal_position')
                ->get()
                ->map(static fn (object $row): string => (string) $row->column_name)
                ->all();

            return array_values($rows);
        }

        return array_values(Schema::connection($connection)->getColumnListing($table));
    }

    public static function hasTable(string $connection, string $table): bool
    {
        return Schema::connection($connection)->hasTable($table);
    }
}
