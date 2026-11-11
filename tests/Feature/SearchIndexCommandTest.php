<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Martis\Console\SearchIndexCommand;
use Martis\Fields\Field;
use Martis\Fields\Text;
use Martis\Layout\Section;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\SearchResolver;
use Martis\Support\SearchIndexInspector;

/*
 * `martis:search-index`: a read-only report of the trigram indexes behind the
 * database search, and with --write a migration for the missing ones. The
 * index lookup is faked and the driver check is bypassed through a fake
 * "pgsql" connection name, so no PostgreSQL server is needed.
 */

class SiOrder extends Model
{
    protected $table = 'si_orders';

    protected $guarded = [];

    public $timestamps = false;

    public function lines(): EloquentHasMany
    {
        return $this->hasMany(SiLine::class, 'order_id');
    }
}

class SiLine extends Model
{
    protected $table = 'si_lines';

    protected $guarded = [];

    public $timestamps = false;
}

class SiOrderResource extends Resource
{
    public static function model(): string
    {
        return SiOrder::class;
    }

    public static function uriKey(): string
    {
        return 'si-orders';
    }

    public static function searchableRelations(): array
    {
        return ['lines.label'];
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->searchable(), Text::make('code')->searchable()];
    }
}

/** Searchable columns behind a `canSee()` that needs the signed-in user, and one that only an admin passes. */
class SiGuardedResource extends Resource
{
    public static function model(): string
    {
        return SiOrder::class;
    }

    public static function uriKey(): string
    {
        return 'si-guarded';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->searchable(),
            // Dereferences the user: a guest request has none.
            Text::make('code')->searchable()->canSee(fn (Request $request) => $request->user()->isAdmin),
            // Hidden from a guest, shown to an admin.
            Text::make('secret')->searchable()->canSee(fn (Request $request) => $request->user()?->isAdmin ?? false),
            Section::make('Nested', [
                Text::make('nested_secret')->searchable()->canSee(fn (Request $request) => $request->user()->isAdmin),
            ]),
        ];
    }
}

/** A model on a table in another schema. */
class SiEvent extends Model
{
    protected $table = 'audit.si_events';

    protected $guarded = [];

    public $timestamps = false;
}

class SiEventResource extends Resource
{
    public static function model(): string
    {
        return SiEvent::class;
    }

    public static function uriKey(): string
    {
        return 'si-events';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')->searchable()];
    }
}

/** An inspector that reports a fixed set of indexed columns per table. */
class SiFakeInspector extends SearchIndexInspector
{
    /** @param array<string, list<string>> $indexed */
    public function __construct(private array $indexed) {}

    public function trigramColumns(Connection $connection, string $table): array
    {
        return $this->indexed[$table] ?? [];
    }
}

beforeEach(function () {
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(SiOrderResource::class);

    $this->migrations = sys_get_temp_dir().'/martis-search-index-'.uniqid();
    mkdir($this->migrations);
    app()->useDatabasePath($this->migrations);
    $this->migrationsDir = $this->migrations.'/migrations';
});

afterEach(function () {
    File::deleteDirectory($this->migrations);
});

/** Run the command against a lazy (never connected) pgsql connection and a fake index lookup. */
function siRun(array $indexed, array $options = []): array
{
    config()->set('database.connections.si_pg', ['driver' => 'pgsql', 'host' => 'localhost', 'database' => 'x', 'username' => 'x', 'password' => '']);
    app()->instance(SearchIndexInspector::class, new SiFakeInspector($indexed));

    $exit = Artisan::call('martis:search-index', $options + ['--connection' => 'si_pg']);

    return [$exit, Artisan::output()];
}

it('prints a notice and exits 0 without writing on a non-PostgreSQL connection', function () {
    $exit = Artisan::call('martis:search-index', ['--write' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('trigram indexes are a PostgreSQL feature')
        ->and((is_dir($this->migrationsDir) ? File::files($this->migrationsDir) : []))->toBe([]);
});

it('lists the columns and warns on the missing ones', function () {
    [$exit, $output] = siRun(['si_orders' => ['name']]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('si_orders.name')
        ->and($output)->toContain('si_orders.code')
        ->and($output)->toContain('si_lines.label (via lines)')
        ->and($output)->toContain('one missing column forces a sequential scan')
        ->and((is_dir($this->migrationsDir) ? File::files($this->migrationsDir) : []))->toBe([]);
});

it('writes a non-transactional migration with the missing indexes only', function () {
    [$exit, $output] = siRun(['si_orders' => ['name']], ['--write' => true]);

    $files = (is_dir($this->migrationsDir) ? File::files($this->migrationsDir) : []);
    expect($exit)->toBe(0)->and($files)->toHaveCount(1);

    $migration = File::get($files[0]->getPathname());
    expect($migration)
        ->toContain('public $withinTransaction = false;')
        ->toContain('CREATE EXTENSION IF NOT EXISTS pg_trgm')
        ->toContain('CREATE INDEX CONCURRENTLY IF NOT EXISTS "si_orders_code_trgm_idx" ON "si_orders" USING gin (("code"::text) gin_trgm_ops)')
        ->toContain('CREATE INDEX CONCURRENTLY IF NOT EXISTS "si_lines_label_trgm_idx" ON "si_lines" USING gin (("label"::text) gin_trgm_ops)')
        ->toContain('DROP INDEX CONCURRENTLY IF EXISTS "si_lines_label_trgm_idx"')
        ->not->toContain('si_orders_name_trgm_idx')
        ->not->toContain('DB::connection(NULL)');
});

it('writes nothing when every column is indexed', function () {
    [, $output] = siRun(['si_orders' => ['name', 'code'], 'si_lines' => ['label']], ['--write' => true]);

    expect($output)->toContain('no migration written')
        ->and((is_dir($this->migrationsDir) ? File::files($this->migrationsDir) : []))->toBe([]);
});

it('fails on an unknown resource', function () {
    expect(Artisan::call('martis:search-index', ['resource' => 'nope']))->toBe(1);
});

it('quotes identifiers and shortens long index names', function () {
    expect(SearchIndexCommand::quote('we"ird'))->toBe('"we""ird"')
        ->and(strlen(SearchIndexCommand::indexName(str_repeat('t', 40), str_repeat('c', 40))))->toBeLessThanOrEqual(63);
});

it('parses the trigram columns out of index definitions', function (string $definition, array $expected) {
    expect(SearchIndexInspector::parseColumns($definition))->toBe($expected);
})->with([
    'plain column' => ['CREATE INDEX a ON public.t USING gin (name gin_trgm_ops)', ['name']],
    'text cast' => ['CREATE INDEX a ON public.t USING gin (((name)::text) gin_trgm_ops)', ['name']],
    'quoted' => ['CREATE INDEX a ON public.t USING gin ("Name" gin_trgm_ops)', ['Name']],
    'two columns' => ['CREATE INDEX a ON public.t USING gin (name gin_trgm_ops, code gin_trgm_ops)', ['name', 'code']],
    'btree' => ['CREATE INDEX a ON public.t USING btree (name)', []],
    'gin without trgm' => ['CREATE INDEX a ON public.t USING gin (tags)', []],
    'partial' => ['CREATE INDEX a ON public.t USING gin (name gin_trgm_ops) WHERE (deleted_at IS NULL)', []],
]);

// ── Review fixes: visibility and schema-qualified tables ────────────────────

it('lists every searchable column whoever may see it, without evaluating canSee', function () {
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(SiGuardedResource::class);

    // A guest request: the closures dereference the user, which would throw if evaluated.
    [$exit, $output] = siRun(['si_orders' => ['name']], ['--write' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('si_orders.name')
        ->and($output)->toContain('si_orders.code')
        ->and($output)->toContain('si_orders.secret')
        ->and($output)->toContain('si_orders.nested_secret');

    $files = File::files($this->migrationsDir);
    expect($files)->toHaveCount(1);
    $migration = File::get($files[0]->getPathname());
    expect($migration)
        ->toContain('"si_orders_code_trgm_idx"')
        ->toContain('"si_orders_secret_trgm_idx"')
        ->toContain('"si_orders_nested_secret_trgm_idx"')
        ->not->toContain('si_orders_name_trgm_idx');
});

it('does not report "indexed" while an admin-only column is missing its index', function () {
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(SiGuardedResource::class);

    // Only the guest-visible column is indexed: the others must still be reported.
    [, $output] = siRun(['si_orders' => ['name']], ['--write' => true]);

    expect($output)->not->toContain('no migration written');
});

it('keeps the visibility filter for the search itself', function () {
    $request = Request::create('/');

    // The union over all users, for the inventory.
    expect(SearchResolver::searchTargets($request, SiOrderResource::class, true)['own'])->toBe(['name', 'code']);

    $guarded = (new SiGuardedResource)->fields($request);
    expect(Field::searchableFields($guarded, $request, true))->toHaveCount(4)
        ->and(fn () => Field::searchableFields($guarded, $request))->toThrow(ErrorException::class, 'Attempt to read property "isAdmin" on null');
});

it('reads a schema-qualified table by schema and table name', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('select')->once()
        ->with('select indexdef from pg_indexes where schemaname = ? and tablename = ?', ['audit', 'si_events'])
        ->andReturn([(object) ['indexdef' => 'CREATE INDEX a ON audit.si_events USING gin (title gin_trgm_ops)']]);

    expect((new SearchIndexInspector)->trigramColumns($connection, 'audit.si_events'))->toBe(['title']);

    // An unqualified table keeps the current-schema lookup.
    $plain = Mockery::mock(Connection::class);
    $plain->shouldReceive('select')->once()
        ->with('select indexdef from pg_indexes where schemaname = current_schema() and tablename = ?', ['si_events'])
        ->andReturn([]);

    expect((new SearchIndexInspector)->trigramColumns($plain, 'si_events'))->toBe([]);
});

it('generates schema-qualified SQL and an index name without a dot for a table in another schema', function () {
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(SiEventResource::class);

    [$exit, $output] = siRun(['audit.si_events' => []], ['--write' => true]);

    expect($exit)->toBe(0)->and($output)->toContain('audit.si_events.title');

    $migration = File::get(File::files($this->migrationsDir)[0]->getPathname());
    expect($migration)
        ->toContain('CREATE INDEX CONCURRENTLY IF NOT EXISTS "si_events_title_trgm_idx" ON "audit"."si_events" USING gin (("title"::text) gin_trgm_ops)')
        ->toContain('DROP INDEX CONCURRENTLY IF EXISTS "audit"."si_events_title_trgm_idx"')
        ->not->toContain('"audit.si_events"');

    // Found as indexed once the lookup knows it.
    [, $indexed] = siRun(['audit.si_events' => ['title']], ['--write' => true]);
    expect($indexed)->toContain('no migration written');
});

it('splits and quotes a qualified table name', function () {
    expect(SearchIndexCommand::quoteTable('audit.events'))->toBe('"audit"."events"')
        ->and(SearchIndexCommand::quoteTable('events'))->toBe('"events"')
        ->and(SearchIndexCommand::indexName('audit.events', 'title'))->toBe('events_title_trgm_idx');
});
