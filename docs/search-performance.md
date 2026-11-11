# Search performance on PostgreSQL

Keep the database search of a large PostgreSQL table fast with trigram indexes, `martis:search-index` and `searchMode()`.

The search of a resource (the index, the global search and the relation pickers) matches a term against every `searchable()` field and every `searchableRelations()` path:

```sql
select * from "orders"
where ("name" ilike '%acme%' or "code" ilike '%acme%'
       or exists (select * from "customers" where "customers"."id" = "orders"."customer_id" and "email" ilike '%acme%'))
```

Two things make that slow on a large PostgreSQL table, and both are about the planner, not about Martis.

## The OR rule: one unindexed column forces a sequential scan

An index serves a predicate only when every branch of the `OR` can use an index (PostgreSQL then combines them with a BitmapOr). If a single OR-ed column has no index, the planner has no choice but to read the whole table. Indexing three of four columns changes nothing.

A leading wildcard (`'%term%'`) also rules out a btree index. The index that does serve `ILIKE '%term%'` is a GIN index over trigrams (the `pg_trgm` extension):

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_name_trgm_idx
    ON orders USING gin ((name::text) gin_trgm_ops);
```

The search emits `"name" ilike ?` with no cast. For a `text` or `varchar` column the index above (stored by PostgreSQL as `((name)::text)`) and the plain `(name gin_trgm_ops)` form are the same expression to the planner, so both are used. A column of another type has no `ILIKE` at all in PostgreSQL (the query errors), so it should not be `searchable()`.

On a 50k-row table, a sequential scan of tens of milliseconds became sub-millisecond once every OR-ed column had its trigram index. The plan changes from:

```
Seq Scan on orders  (Filter: name ~~* '%acme%' OR code ~~* '%acme%' OR (SubPlan 1))
```

to:

```
Bitmap Heap Scan on orders
  ->  BitmapOr
        ->  Bitmap Index Scan on orders_name_trgm_idx
        ->  Bitmap Index Scan on orders_code_trgm_idx
```

(A relation path becomes an `exists` subquery on the related table, which needs its own trigram index on the related column.)

## `php artisan martis:search-index`

Checks the indexes behind the search and, on request, generates the missing ones.

```bash
php artisan martis:search-index                  # every registered resource
php artisan martis:search-index orders           # one resource, by uriKey or class
php artisan martis:search-index --connection=pgsql_reporting
php artisan martis:search-index --write          # also generate a migration
```

It reads the same columns the search uses (the `searchable()` fields the user can see, plus `searchableRelations()`), looks them up in `pg_indexes` and prints, per resource, a table of column, indexed yes/no, and a warning when at least one OR-ed column has no index. A trigram index counts when it is a `gin_trgm_ops` index on the column or its text cast; a partial index (with a `WHERE`) does not count, because it cannot serve an unrestricted search.

It is read-only unless `--write` is passed. `--write` creates one timestamped migration in `database/migrations` with `public $withinTransaction = false;` (`CREATE INDEX CONCURRENTLY` cannot run in a transaction), `CREATE EXTENSION IF NOT EXISTS pg_trgm`, one `CREATE INDEX CONCURRENTLY IF NOT EXISTS <table>_<column>_trgm_idx` per missing column and a `down()` that drops them with `DROP INDEX CONCURRENTLY IF EXISTS`. Index names longer than PostgreSQL's 63 bytes are shortened with a hash. Resources that use Scout are skipped. On a connection that is not PostgreSQL the command prints a notice and exits 0, writing nothing.

Creating the extension needs a role allowed to (on managed services `pg_trgm` is usually a trusted extension).

## Search modes

`Resource::searchMode()` chooses how the term is matched. Set it per resource, or for all resources with the `martis.search.match_mode` config key (`MARTIS_SEARCH_MATCH_MODE`):

```php
class OrderResource extends Resource
{
    public static ?string $searchMode = 'prefix';
}
```

| Mode | SQL | Notes |
|---|---|---|
| `contains` (default) | `col ILIKE '%term%'` | Identical to every earlier release. Needs a trigram index per column. |
| `prefix` | `col ILIKE 'term%'` | Applied to the free text, the relation paths and `col:term` tokens. The same trigram index serves it (the generated SQL is `ILIKE` on PostgreSQL, which a plain btree index cannot serve). |
| `columns` | only the columns named by `col:term` tokens | Free text next to a token is ignored, so no OR across the other columns defeats the index. A bare term with no applied token falls back to `contains` (there is nothing else to search), and so does one whose `col:` names no searchable field. |

An unknown mode (for example `fulltext`, which is not a Martis mode: use `searchQuery()` for full-text search) throws an `InvalidArgumentException` naming the resource and the value, instead of silently searching with another mode.

The term is not escaped for `%` and `_` in any mode, as before. A resource that overrides `searchQuery()` or uses Scout owns its matching, and `searchMode()` does not apply to it.
