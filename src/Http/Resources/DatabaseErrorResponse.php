<?php

namespace Martis\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Martis\Contracts\FieldContract;
use Martis\Fields\Field;
use Martis\Fields\MorphTo;
use Throwable;

/**
 * The sanitized JSON answer to a write the database refused.
 *
 * Never exposes SQL, bindings or connection details. A unique-constraint
 * violation (a race past a field's `unique()` rule, or an index the rules do
 * not know about) is a 422 that names the fields the index covers, so the
 * form marks them; every other error is a 500 with a generic message.
 *
 * The violated columns come from the driver's message:
 *   - PostgreSQL: `Key (<columns or expression>)=(<value>)`, then the
 *     constraint name;
 *   - SQLite: `UNIQUE constraint failed: <table>.<column>, ...`, or
 *     `index '<name>'` for an expression index;
 *   - MySQL / MariaDB: only the index name (`for key '<table>.<name>'`).
 *
 * An expression (`lower(custom_domain::text)`) is read for the column names
 * in it, and an index name is split on the known columns it contains
 * (Laravel's default `{table}_{columns}_unique` names them all). Only a
 * column one of the written fields stores becomes an error key: a function
 * name, a cast type, a column the form does not write, or an index name
 * that names none of them leaves the 422 without field errors.
 */
final class DatabaseErrorResponse
{
    public const UNIQUE_MESSAGE = 'A record with this value already exists. Please use a unique value.';

    public const REFERENCED_MESSAGE = 'This record cannot be modified because it is referenced by other records.';

    public const MISSING_REFERENCE_MESSAGE = 'The referenced record does not exist. Please check relationship fields.';

    public const NOT_NULL_MESSAGE = 'A required field is missing. Please check all mandatory fields.';

    public const GENERIC_MESSAGE = 'A database error occurred. Please check your input and try again.';

    /**
     * @param  list<FieldContract>  $fields  The fields the refused write filled: a
     *                                       unique violation on a column one of them stores is reported on it.
     * @param  Model|null  $model  The record written: its table prefixes a
     *                             conventional index name, and MySQL's `PRIMARY` names its key.
     */
    public static function from(QueryException $e, array $fields = [], ?Model $model = null): IlluminateJsonResponse
    {
        $vendorCode = (string) ($e->errorInfo[1] ?? '');
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $detail = (string) ($e->errorInfo[2] ?? '');

        // Laravel raises UniqueConstraintViolationException on every driver;
        // the codes cover a QueryException built without it. SQLite reports
        // every constraint as SQLSTATE 23000 / code 19, so only its message
        // tells a unique violation from a NOT NULL or foreign key one.
        $sqlite = $vendorCode === '19';

        if ($e instanceof UniqueConstraintViolationException
            || $vendorCode === '1062'
            || $sqlState === '23505'
            || ($sqlite && str_starts_with($detail, 'UNIQUE constraint failed'))) {
            return JsonErrorResponse::validation(
                self::uniqueFieldErrors($vendorCode, $sqlState, $detail, $fields, $model),
                self::UNIQUE_MESSAGE,
            )->toResponse();
        }

        $message = match (true) {
            // A delete or key change the rows pointing at the record block.
            $vendorCode === '1451',
            $sqlState === '23503' && str_contains($detail, 'is still referenced') => self::REFERENCED_MESSAGE,
            // A write naming a record that does not exist.
            $vendorCode === '1452',
            $sqlState === '23503',
            $sqlite && str_starts_with($detail, 'FOREIGN KEY constraint failed') => self::MISSING_REFERENCE_MESSAGE,
            in_array($vendorCode, ['1048', '1364'], true),
            $sqlState === '23502',
            $sqlite && str_starts_with($detail, 'NOT NULL constraint failed') => self::NOT_NULL_MESSAGE,
            default => self::GENERIC_MESSAGE,
        };

        return JsonErrorResponse::serverError($message)->toResponse();
    }

    /**
     * One error per written field whose column the violated index covers.
     *
     * @param  list<FieldContract>  $fields
     * @return array<string, list<string>>
     */
    private static function uniqueFieldErrors(string $vendorCode, string $sqlState, string $detail, array $fields, ?Model $model): array
    {
        $byColumn = self::fieldsByColumn($fields);
        if ($byColumn === []) {
            return [];
        }

        $errors = [];
        foreach (self::violatedColumns($vendorCode, $sqlState, $detail, array_keys($byColumn), $model) as $column) {
            $field = $byColumn[$column];
            $errors[$field->attribute()] ??= [self::uniqueMessage($field)];
        }

        return $errors;
    }

    /**
     * The written fields by the (lower-cased) column each one stores. A
     * `MorphTo` stores two; a JSON path attribute (`meta->email`) is left
     * out, as an index cannot tell which path of the column it covers.
     *
     * @param  list<FieldContract>  $fields
     * @return array<string, FieldContract>
     */
    private static function fieldsByColumn(array $fields): array
    {
        $byColumn = [];

        foreach ($fields as $field) {
            $columns = [$field->attribute()];
            if ($field instanceof MorphTo) {
                $columns[] = $field->getMorphTypeColumn();
                $columns[] = $field->getMorphIdColumn();
            }

            foreach ($columns as $column) {
                if ($column === '' || str_contains($column, '->')) {
                    continue;
                }
                $byColumn[strtolower($column)] ??= $field;
            }
        }

        return $byColumn;
    }

    /**
     * The known columns the violated index covers, in the order the message
     * names them. Each driver's message is read by its own parser, picked by
     * the driver's code: a MySQL message carries the submitted value, which
     * could otherwise spell another driver's shape (`a(email)=(b`).
     *
     * @param  list<string>  $known  Lower-cased column names.
     * @return list<string>
     */
    private static function violatedColumns(string $vendorCode, string $sqlState, string $detail, array $known, ?Model $model): array
    {
        if ($vendorCode === '1062') {
            // MySQL / MariaDB: `Duplicate entry '…' for key '<table>.<index>'`
            // (MySQL 8 qualifies the index with its table).
            if (preg_match("/ for key '([^']+)'\s*$/", $detail, $m) !== 1) {
                return [];
            }
            $parts = explode('.', $m[1]);
            $index = (string) end($parts);

            if (strtoupper($index) === 'PRIMARY') {
                $key = strtolower((string) $model?->getKeyName());

                return in_array($key, $known, true) ? [$key] : [];
            }

            return self::columnsInIndexName($index, $known, $model);
        }

        if ($sqlState === '23505') {
            // PostgreSQL: `Key (<expression>)=(<value>) already exists.` The
            // key is what the index covers; the constraint name is read only
            // when the message carries no key.
            $expression = self::postgresKeyExpression($detail);
            if ($expression !== null) {
                return self::columnsInExpression($expression, $known);
            }

            return preg_match('/constraint "((?:[^"]|"")+)"/', $detail, $m) === 1
                ? self::columnsInIndexName(str_replace('""', '"', $m[1]), $known, $model)
                : [];
        }

        if ($vendorCode === '19' && preg_match('/^UNIQUE constraint failed: (.+)$/', $detail, $m) === 1) {
            // SQLite: `UNIQUE constraint failed: index 'users_lower_email_unique'`
            // for an expression index, the qualified columns otherwise.
            $list = trim($m[1]);
            if (preg_match("/^index '(.+)'$/", $list, $i) === 1) {
                return self::columnsInIndexName($i[1], $known, $model);
            }

            $columns = [];
            foreach (explode(',', $list) as $qualified) {
                $parts = explode('.', trim($qualified));
                $column = strtolower(trim((string) end($parts), '"`[] '));
                if (in_array($column, $known, true) && ! in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }

            return $columns;
        }

        return [];
    }

    /**
     * The `<expression>` of PostgreSQL's `Key (<expression>)=(<value>)`
     * detail: the first parenthesised group, balanced, that `=(` follows.
     * Matching the shape rather than the word keeps a localised message
     * (`Chave (email)=(…)`) readable.
     */
    private static function postgresKeyExpression(string $detail): ?string
    {
        $offset = 0;

        while (($open = strpos($detail, '(', $offset)) !== false) {
            $close = self::closingParenthesis($detail, $open);

            if ($close !== null && substr($detail, $close + 1, 2) === '=(') {
                return substr($detail, $open + 1, $close - $open - 1);
            }

            $offset = $open + 1;
        }

        return null;
    }

    /**
     * Position of the parenthesis closing the one at `$open`, skipping
     * quoted identifiers and string literals.
     */
    private static function closingParenthesis(string $text, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($text);

        for ($i = $open; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    // A doubled quote is an escaped one.
                    if (($text[$i + 1] ?? '') === $quote) {
                        $i++;
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * The known columns an index expression names: `lower(custom_domain::text)`
     * gives `custom_domain`, `tenant_id, email` gives both. A function name
     * (followed by `(`), a cast type (after `::`) and the content of a string
     * literal are not columns.
     *
     * @param  list<string>  $known
     * @return list<string>
     */
    private static function columnsInExpression(string $expression, array $known): array
    {
        $expression = (string) preg_replace("/'(?:[^']|'')*'/", "''", $expression);

        preg_match_all('/"((?:[^"]|"")+)"|([A-Za-z_][A-Za-z0-9_$]*)/', $expression, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $columns = [];
        foreach ($matches as $match) {
            [$token, $start] = $match[0];
            if (isset($match[2])) {
                $name = $match[2][0];
            } elseif (isset($match[1])) {
                $name = str_replace('""', '"', $match[1][0]);
            } else {
                continue;
            }

            $before = rtrim(substr($expression, 0, $start));
            $after = ltrim(substr($expression, $start + strlen($token)));
            if (str_ends_with($before, '::') || str_starts_with($after, '(')) {
                continue;
            }

            $column = strtolower($name);
            if (in_array($column, $known, true) && ! in_array($column, $columns, true)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * The known columns an index name contains, matched whole on its `_`
     * separated words, longest first: `agency_sites_custom_domain_lower_unique`
     * gives `custom_domain`, `posts_user_id_slug_unique` gives `user_id` and
     * `slug` (never `id`). The record's table, leading the name, is skipped.
     * The words are split on every column of the table, so a longer column
     * the form does not write (`first_name`) is not read as a shorter one it
     * does (`name`).
     *
     * @param  list<string>  $known
     * @return list<string>
     */
    private static function columnsInIndexName(string $index, array $known, ?Model $model): array
    {
        $name = strtolower($index);
        $vocabulary = $known;

        if ($model !== null) {
            $vocabulary = array_values(array_unique([...$known, ...self::tableColumns($model)]));

            $table = strtolower($model->getTable());
            $prefixed = strtolower($model->getConnection()->getTablePrefix()).$table;

            foreach (array_unique([$prefixed, $table]) as $candidate) {
                if ($candidate !== '' && str_starts_with($name, $candidate.'_')) {
                    $name = substr($name, strlen($candidate) + 1);
                    break;
                }
            }
        }

        $words = explode('_', $name);
        $count = count($words);
        $columns = [];

        for ($i = 0; $i < $count;) {
            for ($j = $count; $j > $i; $j--) {
                $candidate = implode('_', array_slice($words, $i, $j - $i));
                if (in_array($candidate, $vocabulary, true)) {
                    if (in_array($candidate, $known, true) && ! in_array($candidate, $columns, true)) {
                        $columns[] = $candidate;
                    }
                    $i = $j;

                    continue 2;
                }
            }

            $i++;
        }

        return $columns;
    }

    /**
     * Every column of the record's table, lower-cased. A connection that
     * cannot answer (PostgreSQL inside the transaction the failed write
     * aborted) leaves the written columns alone to split the name on.
     *
     * @return list<string>
     */
    private static function tableColumns(Model $model): array
    {
        try {
            return array_map(
                strtolower(...),
                $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable()),
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * What the field's `unique()` rule would have said: its custom message,
     * else the application's `validation.unique` line on its label.
     */
    private static function uniqueMessage(FieldContract $field): string
    {
        if ($field instanceof Field) {
            $custom = $field->validationMessages()[$field->attribute().'.unique'] ?? null;
            if (is_string($custom) && $custom !== '') {
                return $custom;
            }
        }

        $line = trans('validation.unique', ['attribute' => $field->label()]);

        return is_string($line) && $line !== 'validation.unique' ? $line : self::UNIQUE_MESSAGE;
    }
}
