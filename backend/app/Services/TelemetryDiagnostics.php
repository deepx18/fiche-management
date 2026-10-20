     * Query information_schema.columns directly via a raw DB connection.
     * This works even when the MySQL user lacks SELECT permission on the table
     * but can access information_schema.
     *
     * @return array{column names} or null if unavailable
     */
    private function infoSchemaColumns(string $connection, string $table): ?array
    {
        try {
            $results = DB::connection($connection)->select(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS"
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION",
                [$table]);
            if ($results === [] || $results === null) {
                return null;
            }
            return array_map(static fn (stdClass $row): string => (string) $row->COLUMN_NAME, $results);
        } catch (Throwable $e) {
            return null;
        }
    }
