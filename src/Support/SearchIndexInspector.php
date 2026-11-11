<?php

declare(strict_types=1);

namespace Martis\Support;

use Illuminate\Database\Connection;

/**
 * Looks up which columns of a PostgreSQL table carry a trigram GIN index the
 * database search can use. Kept behind a small class so `martis:search-index`
 * can be tested without a PostgreSQL server.
 *
 * The search emits `"col" ilike ?`. That is served by a GIN index with the
 * `gin_trgm_ops` operator class on the column itself (`col gin_trgm_ops`) or
 * on its text cast (`(col::text) gin_trgm_ops`, which PostgreSQL stores as
 * `(col)::text`): for a text or varchar column the two are the same
 * expression to the planner. Partial indexes (with a WHERE) are not counted,
 * since they cannot serve an unrestricted search.
 */
class SearchIndexInspector
{
    /**
     * The columns of `$table` covered by a trigram GIN index.
     *
     * @return list<string>
     */
    public function trigramColumns(Connection $connection, string $table): array
    {
        [$schema, $name] = static::splitTable($table);

        $rows = $schema === null
            ? $connection->select('select indexdef from pg_indexes where schemaname = current_schema() and tablename = ?', [$name])
            : $connection->select('select indexdef from pg_indexes where schemaname = ? and tablename = ?', [$schema, $name]);

        $columns = [];
        foreach ($rows as $row) {
            $definition = (string) (((array) $row)['indexdef'] ?? '');
            foreach (static::parseColumns($definition) as $column) {
                $columns[$column] = true;
            }
        }

        return array_keys($columns);
    }

    /**
     * A model's table name split into its schema and table (`schema.table`);
     * the schema is null for a bare name (the connection's current schema).
     *
     * @return array{0: string|null, 1: string}
     */
    public static function splitTable(string $table): array
    {
        $dot = strpos($table, '.');

        return $dot === false || $dot === 0 || $dot === strlen($table) - 1
            ? [null, $table]
            : [substr($table, 0, $dot), substr($table, $dot + 1)];
    }

    /**
     * The columns a `CREATE INDEX ... USING gin (...)` definition indexes with
     * `gin_trgm_ops`. Public so the parsing is testable on its own.
     *
     * @return list<string>
     */
    public static function parseColumns(string $definition): array
    {
        $marker = stripos($definition, ' using gin ');
        if ($marker === false
            || stripos($definition, 'gin_trgm_ops') === false
            || preg_match('/\)\s+where\s/i', $definition) === 1) {
            return [];
        }

        $start = strpos($definition, '(', $marker);
        if ($start === false) {
            return [];
        }

        $columns = [];
        foreach (explode('gin_trgm_ops', substr($definition, $start + 1)) as $segment) {
            // The last piece is only the closing parenthesis (and any WITH clause).
            $segment = preg_replace('/::[a-z ]+/i', '', $segment) ?? $segment;
            if (preg_match('/^[\s,(]*"?([A-Za-z0-9_$]+)"?[\s)]*$/', $segment, $match) === 1) {
                $columns[] = $match[1];
            }
        }

        return $columns;
    }
}
