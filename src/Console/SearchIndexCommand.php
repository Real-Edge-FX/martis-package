<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\SearchResolver;
use Martis\Support\SearchIndexInspector;

/**
 * `martis:search-index`: report which of the columns a resource's database
 * search ORs together carry a trigram index, and with `--write` generate a
 * migration that adds the missing ones. PostgreSQL only; read-only by default.
 */
class SearchIndexCommand extends Command
{
    protected $signature = 'martis:search-index
        {resource? : A resource uriKey or class. Every registered resource when omitted}
        {--write : Generate a migration with the missing trigram indexes}
        {--connection= : The database connection to inspect (default: each model\'s own)}';

    protected $description = 'Report the trigram indexes behind each resource\'s search and generate the missing ones (PostgreSQL).';

    public function handle(ResourceRegistry $registry, SearchIndexInspector $inspector): int
    {
        $resources = $this->resolveResources($registry);
        if ($resources === null) {
            return self::FAILURE;
        }

        $request = Request::create('/');
        /** @var list<array{connection: ?string, table: string, column: string}> $missing */
        $missing = [];
        $reported = false;

        foreach ($resources as $resourceClass) {
            if ($resourceClass::usesScout()) {
                continue;
            }

            // The inventory covers every searchable column whoever may see it: the
            // index matters for whoever can search it, and a `canSee()` may need a
            // signed-in user, which this command has none of.
            $targets = SearchResolver::searchTargets($request, $resourceClass, ignoreVisibility: true);
            if ($targets['own'] === [] && $targets['relations'] === []) {
                continue;
            }

            /** @var Model $model */
            $model = new ($resourceClass::model());
            $rows = [];
            foreach ($targets['own'] as $column) {
                $rows[] = [$model, $column, null];
            }
            foreach ($targets['relations'] as $relation) {
                $rows[] = [$this->relatedModel($model, $relation['relation']), $relation['attribute'], $relation['relation']];
            }

            $lines = [];
            $unindexed = 0;
            foreach ($rows as [$target, $column, $via]) {
                if ($target === null) {
                    $lines[] = [$via.'.'.$column, '?', 'relation not resolvable'];
                    $unindexed++;

                    continue;
                }

                $connection = $this->connectionFor($target);
                $driver = $connection->getDriverName();
                if ($driver !== 'pgsql') {
                    $this->components->info(sprintf('The "%s" connection is %s: trigram indexes are a PostgreSQL feature, nothing to inspect.', $connection->getName(), $driver));

                    return self::SUCCESS;
                }

                $table = $target->getTable();
                $indexed = in_array($column, $inspector->trigramColumns($connection, $table), true);
                $label = $via === null ? sprintf('%s.%s', $table, $column) : sprintf('%s.%s (via %s)', $table, $column, $via);
                $lines[] = [$label, $indexed ? 'yes' : 'no', $indexed ? '' : 'no gin_trgm_ops index'];

                if (! $indexed) {
                    $unindexed++;
                    $missing[] = ['connection' => $this->connectionName($target), 'table' => $table, 'column' => $column];
                }
            }

            $reported = true;
            $this->newLine();
            $this->components->twoColumnDetail('<fg=white;options=bold>'.$resourceClass::uriKey().'</>', '<fg=gray>search mode: '.$resourceClass::searchMode().'</>');
            $this->table(['Column', 'Indexed', 'Note'], $lines);

            if ($unindexed > 0) {
                $this->components->warn('At least one OR-ed column has no index: one missing column forces a sequential scan of the table.');
            }
        }

        if (! $reported) {
            $this->components->info('No resource uses the database search on searchable columns: nothing to inspect.');

            return self::SUCCESS;
        }

        $missing = $this->unique($missing);

        if (! $this->option('write')) {
            if ($missing !== []) {
                $this->newLine();
                $this->line(sprintf('%d index%s missing. Run with --write to generate a migration.', count($missing), count($missing) === 1 ? '' : 'es'));
            }

            return self::SUCCESS;
        }

        if ($missing === []) {
            $this->components->info('Every searched column is indexed: no migration written.');

            return self::SUCCESS;
        }

        $this->components->info('Migration written: '.$this->writeMigration($missing));

        return self::SUCCESS;
    }

    /** @return list<class-string<\Martis\Resource>>|null */
    private function resolveResources(ResourceRegistry $registry): ?array
    {
        $argument = $this->argument('resource');
        if (! is_string($argument) || $argument === '') {
            return $registry->list();
        }

        if ($registry->has($argument)) {
            return [$registry->get($argument)];
        }

        foreach ($registry->list() as $class) {
            if (ltrim($argument, '\\') === $class) {
                return [$class];
            }
        }

        $this->components->error(sprintf('No registered resource matches "%s".', $argument));

        return null;
    }

    private function connectionName(Model $model): ?string
    {
        $option = $this->option('connection');

        return is_string($option) && $option !== '' ? $option : $model->getConnectionName();
    }

    private function connectionFor(Model $model): Connection
    {
        $name = $this->connectionName($model);

        /** @var Connection $connection */
        $connection = DB::connection($name);

        return $connection;
    }

    /** Walk a (possibly nested) relation path to the related model, or null. */
    private function relatedModel(Model $model, string $path): ?Model
    {
        $current = $model;
        foreach (explode('.', $path) as $name) {
            try {
                $relation = $current->{$name}();
            } catch (\Throwable) {
                return null;
            }
            if (! $relation instanceof Relation) {
                return null;
            }
            $current = $relation->getRelated();
        }

        return $current;
    }

    /**
     * @param  list<array{connection: ?string, table: string, column: string}>  $missing
     * @return list<array{connection: ?string, table: string, column: string}>
     */
    private function unique(array $missing): array
    {
        $seen = [];
        foreach ($missing as $item) {
            $seen[($item['connection'] ?? '').'|'.$item['table'].'|'.$item['column']] = $item;
        }

        return array_values($seen);
    }

    /** @param list<array{connection: ?string, table: string, column: string}> $missing */
    private function writeMigration(array $missing): string
    {
        $extension = [];
        $up = [];
        $down = [];
        foreach ($missing as $item) {
            $connection = var_export($item['connection'], true);
            $index = self::indexName($item['table'], $item['column']);
            // An index is created in its table's schema, so its name is bare there
            // and qualified where it is dropped.
            $create = sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s USING gin ((%s::text) gin_trgm_ops)',
                self::quote($index),
                self::quoteTable($item['table']),
                self::quote($item['column']),
            );
            $schema = SearchIndexInspector::splitTable($item['table'])[0];
            $drop = 'DROP INDEX CONCURRENTLY IF EXISTS '.($schema === null ? '' : self::quote($schema).'.').self::quote($index);
            $extension[$connection] = sprintf("        DB::connection(%s)->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');", $connection);
            $up[] = sprintf('        DB::connection(%s)->statement(%s);', $connection, var_export($create, true));
            $down[] = sprintf('        DB::connection(%s)->statement(%s);', $connection, var_export($drop, true));
        }

        $contents = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\DB;\n\n"
            ."/*\n * Trigram indexes behind the Martis database search (generated by martis:search-index).\n"
            ." * CREATE INDEX CONCURRENTLY cannot run inside a transaction.\n */\n"
            ."return new class extends Migration\n{\n    public \$withinTransaction = false;\n\n    public function up(): void\n    {\n"
            .implode("\n", array_values($extension))."\n".implode("\n", $up)."\n    }\n\n    public function down(): void\n    {\n"
            .implode("\n", $down)."\n    }\n};\n";

        $directory = $this->laravel->databasePath('migrations');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.'/'.date('Y_m_d_His').'_add_martis_search_trigram_indexes.php';
        file_put_contents($path, $contents);

        return $path;
    }

    /** `<table>_<col>_trgm_idx` (the table without its schema), shortened with a hash to PostgreSQL's 63-byte limit. */
    public static function indexName(string $table, string $column): string
    {
        $name = SearchIndexInspector::splitTable($table)[1].'_'.$column.'_trgm_idx';

        return strlen($name) <= 63 ? $name : substr($name, 0, 50).'_'.substr(md5($name), 0, 12);
    }

    /** Quote a table name that may carry a schema (`schema.table`): each part on its own. */
    public static function quoteTable(string $table): string
    {
        [$schema, $name] = SearchIndexInspector::splitTable($table);

        return ($schema === null ? '' : self::quote($schema).'.').self::quote($name);
    }

    /** Quote a PostgreSQL identifier. */
    public static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
