<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Martis\Contracts\ToolContract;
use Martis\MartisManager;
use Martis\ResourceRegistry;

/**
 * `martis:list-overrides` — print every component key the PHP layer
 * expects the frontend `componentRegistry` to resolve.
 *
 * Note on scope. The actual override registry is **frontend-only**:
 * it lives in `@martis/martis/lib/componentRegistry` (TypeScript) and
 * is populated by the consumer-extension bundle (v1.9.0+ auto-discovery
 * over `resources/js/martis-extensions/{tools,fields,cards,overrides}/`).
 * There is no PHP-side `ComponentRegistry::class`, so this command
 * cannot list what is registered — only what is **expected**.
 *
 * Sources of expected keys:
 *   - Each registered Resource (via `martis.resources` config).
 *   - Each registered Tool — `Tool::component($key)`.
 *   - Each Action that opted into a custom component —
 *     `Action::component($key, $props)`.
 *
 * Run from the host app:
 *
 *     php artisan martis:list-overrides
 *     php artisan martis:list-overrides --kind=tool
 *     php artisan martis:list-overrides --filter=order
 *     php artisan martis:list-overrides --frontend       # v1.9+ filesystem cross-check
 */
class ListOverridesCommand extends Command
{
    protected $signature = 'martis:list-overrides
                            {--kind= : Filter by source kind: resource, tool, action}
                            {--filter= : Substring filter applied to the component key}
                            {--frontend : Cross-check against resources/js/martis-extensions/ (v1.9+ filesystem auto-discovery) and flag missing TSX files}
                            {--extensions-dir= : Override the consumer extensions root (default: resources/js/martis-extensions)}';

    protected $description = 'List every component key the PHP layer expects the frontend componentRegistry to resolve.';

    public function handle(MartisManager $manager, ResourceRegistry $resources): int
    {
        $rows = [];

        // Symfony Console types these as `string|string[]|bool|null`; the
        // narrowing below tells PHPStan we treat them as nullable strings.
        $kindOption = $this->option('kind');
        $kindFilter = is_string($kindOption) ? $kindOption : null;

        $textOption = $this->option('filter');
        $textFilter = is_string($textOption) ? $textOption : null;

        if ($kindFilter !== null && ! in_array($kindFilter, ['resource', 'tool', 'action'], true)) {
            $this->error("Unknown --kind '{$kindFilter}'. Valid values: resource, tool, action.");

            return self::INVALID;
        }

        // Resources — the URI key is what the frontend uses to route.
        if ($kindFilter === null || $kindFilter === 'resource') {
            foreach ($resources->all() as $uriKey => $class) {
                $rows[] = [
                    'kind' => 'resource',
                    'key' => (string) $uriKey,
                    'source' => (string) $class,
                ];
            }
        }

        // Tools — every Tool declares its own component key.
        if ($kindFilter === null || $kindFilter === 'tool') {
            $request = $this->resolveRequest();
            foreach ($manager->resolveTools($request) as $tool) {
                /** @var ToolContract $tool */
                $rows[] = [
                    'kind' => 'tool',
                    'key' => (string) $tool->component(),
                    'source' => $tool::class,
                ];
            }
        }

        // Actions — those that opted into a custom React component
        // expose their key via `Action::component($key, $props)`. We
        // walk every registered Resource's actions to enumerate.
        if ($kindFilter === null || $kindFilter === 'action') {
            $request = $this->resolveRequest();
            foreach ($resources->all() as $uriKey => $resourceClass) {
                if (! class_exists($resourceClass)) {
                    continue;
                }
                /** @var object $instance */
                $instance = new $resourceClass;
                if (! method_exists($instance, 'actions')) {
                    continue;
                }
                /** @var iterable<object> $actions */
                $actions = $instance->actions($request);
                foreach ($actions as $action) {
                    if (method_exists($action, 'toArray')) {
                        $payload = $action->toArray($request);
                    } elseif ($action instanceof \JsonSerializable) {
                        $payload = $action->jsonSerialize();
                    } else {
                        continue;
                    }
                    $componentKey = is_array($payload) ? ($payload['customComponent'] ?? null) : null;
                    if ($componentKey === null || $componentKey === '') {
                        continue;
                    }
                    $rows[] = [
                        'kind' => 'action',
                        'key' => (string) $componentKey,
                        'source' => "{$resourceClass} → ".$action::class,
                    ];
                }
            }
        }

        if ($textFilter !== null && $textFilter !== '') {
            $needle = strtolower($textFilter);
            $rows = array_values(array_filter($rows, fn (array $r) => str_contains(strtolower($r['key']), $needle)));
        }

        if (empty($rows)) {
            $this->info('No component keys declared by the PHP layer.');
            $this->line('  (resources, tools, and actions with `Action::component()` all show up here)');

            return self::SUCCESS;
        }

        // --frontend: walk the v1.9+ extension buckets
        // (resources/js/martis-extensions/{tools,fields,cards,overrides})
        // and derive the keys each bucket would auto-register. Compare
        // against the rows above so the dev sees which PHP-declared
        // keys still need a TSX file to ship them. The previous
        // boot.ts parser is dead code: that path was retired in
        // v1.8.19 / v1.9.0. The --boot flag was removed in v1.10.
        $registered = [];
        $extensionsDir = null;
        if ($this->option('frontend') === true) {
            $extOption = $this->option('extensions-dir');
            $extensionsDir = is_string($extOption) && $extOption !== ''
                ? $extOption
                : base_path('resources/js/martis-extensions');

            if (! is_dir($extensionsDir)) {
                $this->warn("--frontend: extensions directory not found at {$extensionsDir}");
                $this->line('  Run `php artisan martis:install` to publish the v1.9+ extension scaffold,');
                $this->line('  or pass --extensions-dir=<path> to point at your custom location.');
            } else {
                $registered = $this->discoverRegisteredKeys($extensionsDir);
            }
        }

        if ($this->option('frontend') === true) {
            $rowsWithStatus = [];
            foreach ($rows as $r) {
                $status = in_array($r['key'], $registered, true)
                    ? '<fg=green>✓ registered</>'
                    : '<fg=red>✗ missing</>';
                $rowsWithStatus[] = [$r['kind'], $r['key'], $r['source'], $status];
            }
            $this->table(['Kind', 'Component key', 'Source', 'Frontend'], $rowsWithStatus);

            $missingCount = count($rows) - count(array_filter(
                $rows,
                fn (array $r) => in_array($r['key'], $registered, true),
            ));

            $this->newLine();
            $label = $extensionsDir !== null
                ? str_replace((string) base_path().'/', '', $extensionsDir)
                : 'resources/js/martis-extensions';
            if ($missingCount === 0) {
                $this->components->info("All declared component keys are present under {$label}.");

                return self::SUCCESS;
            }
            $this->components->error("{$missingCount} component key(s) declared in PHP but missing under {$label}.");
            $this->line('  Drop a TSX file in the matching bucket so the v1.9+ auto-discovery loop registers it,');
            $this->line('  e.g. `martis:tool Foo --with-component`, `martis:field Bar`, etc.');

            return self::INVALID;
        }

        $this->table(['Kind', 'Component key', 'Source'], $rows);

        $this->newLine();
        $this->line(sprintf(
            '%d component key(s) declared. Verify each one is backed by a TSX file under resources/js/martis-extensions/{tools,fields,cards,overrides}/ (v1.9+ filename → key auto-discovery).',
            count($rows),
        ));
        $this->line('  Run with <fg=cyan>--frontend</> to cross-check against the buckets automatically.');

        return self::SUCCESS;
    }

    /**
     * Walk `resources/js/martis-extensions/{tools,fields,cards,overrides}/`
     * and return the registry keys each `.tsx` file would auto-register
     * via the bundle entry's `import.meta.glob` loop.
     *
     * Mirrors the conventions in the published `index.ts.stub`:
     *
     *   tools/{Name}.tsx       → "tool:{kebab(Name)}"
     *   cards/{Name}.tsx       → "card:{kebab(Name)}"
     *   fields/{Name}.tsx      → "field:{kebab(Name)}"
     *   overrides/{Name}.tsx   → OVERRIDE_KEYS[Name] (Sidebar → "layout:sidebar",
     *                              LoginPage → "auth:login", …)
     *
     * @return list<string>
     */
    private function discoverRegisteredKeys(string $extensionsDir): array
    {
        $keys = [];

        $kebab = static function (string $pascal): string {
            $stage1 = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $pascal) ?? $pascal;
            $stage2 = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $stage1) ?? $stage1;

            return strtolower($stage2);
        };

        $simpleBuckets = [
            'tools' => 'tool',
            'cards' => 'card',
            'fields' => 'field',
        ];

        foreach ($simpleBuckets as $dir => $prefix) {
            $bucketPath = $extensionsDir.'/'.$dir;
            if (! is_dir($bucketPath)) {
                continue;
            }
            foreach (glob($bucketPath.'/*.tsx') ?: [] as $file) {
                $base = basename($file, '.tsx');
                if ($base === '' || $base[0] === '.') {
                    continue;
                }
                $keys[] = $prefix.':'.$kebab($base);
            }
        }

        // Overrides — fixed key map first, then filename-derived
        // fallback for generic / field-shape overrides. Mirrors the
        // logic in `stubs/extensions/index.ts.stub` (v1.10.1+). Keep
        // the fixed table here in sync with that file.
        $overrideKeys = [
            'Shell' => 'layout:shell',
            'Sidebar' => 'layout:sidebar',
            'Topbar' => 'layout:topbar',
            'Footer' => 'layout:footer',
            'LoginPage' => 'auth:login',
            'RegisterPage' => 'auth:register',
            'ForgotPasswordPage' => 'auth:forgot-password',
            'ResetPasswordPage' => 'auth:reset-password',
            'EmailVerifyNoticePage' => 'auth:email-verify-notice',
        ];
        $overridesPath = $extensionsDir.'/overrides';
        if (is_dir($overridesPath)) {
            foreach (glob($overridesPath.'/*.tsx') ?: [] as $file) {
                $base = basename($file, '.tsx');
                if ($base === '' || $base[0] === '.') {
                    continue;
                }
                if (isset($overrideKeys[$base])) {
                    $keys[] = $overrideKeys[$base];

                    continue;
                }
                // Generic / field-shape override — derive `{kebab}`
                // and emit both halves of the field-shape pair so the
                // cross-check reports either as registered when the
                // PHP layer asks for it. Field-only TSX without an
                // Input export still registers under `{kebab}`; the
                // `-input` row tags as registered if the dev follows
                // the convention.
                $derived = $kebab($base);
                $keys[] = $derived;
                $keys[] = $derived.'-input';
            }
        }

        return array_values(array_unique($keys));
    }

    private function resolveRequest(): Request
    {
        return app('request') instanceof Request ? app('request') : Request::create('/');
    }
}
