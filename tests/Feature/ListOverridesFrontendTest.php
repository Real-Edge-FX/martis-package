<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Martis\Console\ListOverridesCommand;
use Martis\MartisManager;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Stubs\StubResolver;
use Martis\Tools\Tool;

class ListOverridesFrontendModel extends Model
{
    protected $table = 'users';
}

class ListOverridesFrontendResource extends Resource
{
    public static function model(): string
    {
        return ListOverridesFrontendModel::class;
    }

    public function fields(Request $request): array
    {
        return [];
    }
}

/** A Tool bound to a key its TSX file name does not derive, so index.ts registers it. */
class ListOverridesFrontendTool extends Tool
{
    public function __construct()
    {
        $this->withComponent('tool:finance-imports');
    }

    public function name(): string
    {
        return 'Finance imports';
    }

    public function uriKey(): string
    {
        return 'finance-imports';
    }

    public function authorizedToSee($request): bool
    {
        return true;
    }
}

/** The keys the cross-check reads for an extensions root. */
function listOverridesFrontendKeys(string $extensionsDir): array
{
    $command = new ListOverridesCommand;
    $reflection = new ReflectionMethod($command, 'discoverRegisteredKeys');
    $reflection->setAccessible(true);

    /** @var list<string> */
    return $reflection->invoke($command, $extensionsDir);
}

/**
 * `martis:list-overrides --frontend` smoke specs (v1.10+ rewrite).
 *
 * The pre-v1.10 implementation parsed `resources/js/martis/boot.ts`
 * statically. Since v1.8.19 retired the `boot.ts` mechanism the
 * static parser was dead code; v1.10 rewrites the cross-check to
 * walk `resources/js/martis-extensions/{tools,fields,cards,overrides}/`
 * and derive the keys each `.tsx` file would auto-register through
 * the bundle entry's `import.meta.glob` loop. These specs cover the
 * happy path + the missing-directory case + the override key map.
 */
beforeEach(function () {
    // No resource or Tool from another test: each spec declares its own.
    $this->app->forgetInstance(ResourceRegistry::class);
    $this->app->forgetInstance(MartisManager::class);
    $this->app->singleton(ResourceRegistry::class, fn () => new ResourceRegistry);
    $this->app->singleton(MartisManager::class, fn () => new MartisManager);

    $fs = new Filesystem;
    $extensionsRoot = base_path('resources/js/martis-extensions');
    if ($fs->exists($extensionsRoot)) {
        $fs->deleteDirectory($extensionsRoot);
    }
});

afterEach(function () {
    $fs = new Filesystem;
    $extensionsRoot = base_path('resources/js/martis-extensions');
    if ($fs->exists($extensionsRoot)) {
        $fs->deleteDirectory($extensionsRoot);
    }
});

it('--frontend warns when the extensions directory is absent', function () {
    // Default test app has no `resources/js/martis-extensions/` until
    // `martis:install` runs. The command should surface a soft warning
    // and still exit cleanly.
    app(MartisManager::class)->tools([new ListOverridesFrontendTool]);

    $this->artisan('martis:list-overrides', ['--frontend' => true])
        ->expectsOutputToContain('extensions directory not found')
        ->run();

    expect(true)->toBeTrue();
});

it('--frontend discovers tool/field/card filenames in their respective buckets', function () {
    $fs = new Filesystem;
    foreach (['tools', 'fields', 'cards', 'overrides'] as $bucket) {
        $fs->ensureDirectoryExists(base_path("resources/js/martis-extensions/{$bucket}"));
    }
    file_put_contents(base_path('resources/js/martis-extensions/tools/Charts.tsx'), '// stub');
    file_put_contents(base_path('resources/js/martis-extensions/fields/Rating.tsx'), '// stub');
    file_put_contents(base_path('resources/js/martis-extensions/cards/RevenueGauge.tsx'), '// stub');
    file_put_contents(base_path('resources/js/martis-extensions/overrides/Sidebar.tsx'), '// stub');
    file_put_contents(base_path('resources/js/martis-extensions/overrides/LoginPage.tsx'), '// stub');

    // No PHP-declared keys in the test app, so the cross-check table
    // is empty and the command exits clean. The discovery itself is
    // covered by the unit assertion below.
    $this->artisan('martis:list-overrides', ['--frontend' => true])
        ->run();

    // Reflect into the command to exercise the discovery method
    // directly. Robust against future test-app additions that might
    // pollute the rows table.
    $command = new ListOverridesCommand;
    $reflection = new ReflectionMethod($command, 'discoverRegisteredKeys');
    $reflection->setAccessible(true);
    /** @var list<string> $keys */
    $keys = $reflection->invoke($command, base_path('resources/js/martis-extensions'));

    expect($keys)
        ->toContain('tool:charts')
        ->toContain('field:rating')
        ->toContain('card:revenue-gauge')
        ->toContain('layout:sidebar')
        ->toContain('auth:login');
});

it('--frontend derives keys for arbitrary override filenames (v1.10.1+)', function () {
    $fs = new Filesystem;
    $fs->ensureDirectoryExists(base_path('resources/js/martis-extensions/overrides'));
    file_put_contents(
        base_path('resources/js/martis-extensions/overrides/StatusBadge.tsx'),
        '// stub',
    );

    $command = new ListOverridesCommand;
    $reflection = new ReflectionMethod($command, 'discoverRegisteredKeys');
    $reflection->setAccessible(true);
    /** @var list<string> $keys */
    $keys = $reflection->invoke($command, base_path('resources/js/martis-extensions'));

    // v1.10.1+ derives `{kebab}` + `{kebab}-input` for any
    // non-canonical override filename. The bundle's auto-discovery
    // loop binds Display + Input named exports to those keys, and
    // single-default-export overrides to `{kebab}` alone.
    expect($keys)
        ->toContain('status-badge')
        ->toContain('status-badge-input');
});

it('--frontend supports a custom --extensions-dir path', function () {
    $alt = base_path('resources/js/custom-extensions');
    $fs = new Filesystem;
    $fs->ensureDirectoryExists($alt.'/tools');
    file_put_contents($alt.'/tools/Status.tsx', '// stub');

    try {
        $this->artisan('martis:list-overrides', [
            '--frontend' => true,
            '--extensions-dir' => $alt,
        ])->run();

        $command = new ListOverridesCommand;
        $reflection = new ReflectionMethod($command, 'discoverRegisteredKeys');
        $reflection->setAccessible(true);
        /** @var list<string> $keys */
        $keys = $reflection->invoke($command, $alt);

        expect($keys)->toContain('tool:status');
    } finally {
        $fs->deleteDirectory($alt);
    }
});

it('--frontend reads the keys index.ts registers by hand, with a literal key', function () {
    $root = base_path('resources/js/martis-extensions');
    (new Filesystem)->ensureDirectoryExists($root);
    file_put_contents($root.'/index.ts', <<<'TS'
        import { componentRegistry, iconRegistry, layoutRegistry } from '@martis/runtime'
        import { FinanceImportsTool } from './tools/FinanceImportsTool'

        componentRegistry.register('tool:finance-imports', FinanceImportsTool)
        componentRegistry.register(
          "custom-post-creator",
          MyPostCreator,
        )
        window.Martis?.componentRegistry?.register(`layout:topbar`, MyTopbar)
        registry.register('star-rating', StarRating)

        // componentRegistry.register('commented-out', Nothing)
        /* componentRegistry.register('block-commented', Nothing) */
        componentRegistry.register(`tool:${name}`, Computed)
        componentRegistry.registerFieldDisplay('text', MyText)
        layoutRegistry.register('users', UserLayout)
        iconRegistry.register('crown', CrownIcon)
        TS);

    $keys = listOverridesFrontendKeys($root);

    expect($keys)
        ->toContain('tool:finance-imports')
        ->toContain('custom-post-creator')
        ->toContain('layout:topbar')
        ->toContain('star-rating')
        // Commented out, computed, or a registration that is not a component key.
        ->not->toContain('commented-out')
        ->not->toContain('block-commented')
        ->not->toContain('text')
        ->not->toContain('users')
        ->not->toContain('crown');
    expect(array_filter($keys, fn (string $key) => str_contains($key, '$')))->toBe([]);
});

it('--frontend reads no key from the scaffold\'s own index.ts', function () {
    // Its registrations are computed from the file names, and its comments
    // mention `componentRegistry.register(...)`.
    $root = base_path('resources/js/martis-extensions');
    (new Filesystem)->ensureDirectoryExists($root);
    copy(StubResolver::packagePath('extensions/index.ts.stub'), $root.'/index.ts');

    expect(listOverridesFrontendKeys($root))->toBe([]);
});

it('--frontend reports a Tool key that index.ts registers as registered', function () {
    app(MartisManager::class)->tools([new ListOverridesFrontendTool]);

    $root = base_path('resources/js/martis-extensions');
    (new Filesystem)->ensureDirectoryExists($root.'/tools');
    // The file name derives `tool:finance-imports-tool`; index.ts binds the Tool's key.
    file_put_contents($root.'/tools/FinanceImportsTool.tsx', '// stub');
    file_put_contents($root.'/index.ts', "import { componentRegistry } from '@martis/runtime'\nimport FinanceImportsTool from './tools/FinanceImportsTool'\n\ncomponentRegistry.register('tool:finance-imports', FinanceImportsTool)\n");

    $this->artisan('martis:list-overrides', ['--frontend' => true])
        ->expectsOutputToContain('✓ registered')
        ->expectsOutputToContain('All declared component keys are registered')
        ->assertSuccessful();
});

it('--frontend still reports a Tool key nothing registers as missing, and exits 2', function () {
    app(MartisManager::class)->tools([new ListOverridesFrontendTool]);
    (new Filesystem)->ensureDirectoryExists(base_path('resources/js/martis-extensions/tools'));

    $this->artisan('martis:list-overrides', ['--frontend' => true])
        ->expectsOutputToContain('✗ missing')
        ->assertExitCode(2);
});

it('--frontend does not count a resource as a missing component', function () {
    // The SPA renders a resource without a component of its own: its row
    // names the URI key, which nothing has to register.
    app(ResourceRegistry::class)->register(ListOverridesFrontendResource::class);
    (new Filesystem)->ensureDirectoryExists(base_path('resources/js/martis-extensions/tools'));

    $this->artisan('martis:list-overrides', ['--frontend' => true])
        ->expectsOutputToContain(ListOverridesFrontendResource::uriKey())
        ->expectsOutputToContain('n/a')
        ->doesntExpectOutputToContain('✗ missing')
        ->assertSuccessful();
});
