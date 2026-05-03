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
 * is populated by the consumer's `boot.ts`. There is no PHP-side
 * `ComponentRegistry::class`, so this command cannot list what is
 * registered — only what is **expected**. The output is the canonical
 * checklist for "did I register every component my PHP code points
 * at?" debugging.
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
 */
class ListOverridesCommand extends Command
{
    protected $signature = 'martis:list-overrides
                            {--kind= : Filter by source kind: resource, tool, action}
                            {--filter= : Substring filter applied to the component key}
                            {--frontend : Cross-check against the consumer\'s boot.ts and flag missing registrations}
                            {--boot= : Path to the consumer boot.ts (default: resources/js/martis/boot.ts)}';

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

        // --frontend: parse the consumer's boot.ts statically, extract
        // every `componentRegistry.register('xxx', ...)` call, and tag
        // each row "registered" / "missing". Catches the most common
        // override bug — PHP declares a key, host forgot to wire it.
        $registered = [];
        $bootPath = null;
        if ($this->option('frontend') === true) {
            $bootOption = $this->option('boot');
            $bootPath = is_string($bootOption) && $bootOption !== ''
                ? $bootOption
                : base_path('resources/js/martis/boot.ts');

            if (! is_file($bootPath)) {
                $this->warn("--frontend: boot file not found at {$bootPath}");
                $this->line('  Pass --boot=path/to/boot.ts to override.');
            } else {
                $registered = $this->extractRegisteredKeys((string) file_get_contents($bootPath));
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
            if ($missingCount === 0) {
                $this->components->info('All declared component keys are registered in '.basename((string) $bootPath).'.');

                return self::SUCCESS;
            }
            $this->components->error("{$missingCount} component key(s) declared in PHP but not registered in ".basename((string) $bootPath).'.');
            $this->line('  Add a `componentRegistry.register(\'<key>\', Component)` call for each missing entry.');

            return self::INVALID;
        }

        $this->table(['Kind', 'Component key', 'Source'], $rows);

        $this->newLine();
        $this->line(sprintf(
            '%d component key(s) declared. Verify each one is registered in your frontend boot file (e.g. resources/js/boot.ts) via `componentRegistry.register(\'<key>\', Component)`.',
            count($rows),
        ));
        $this->line('  Run with <fg=cyan>--frontend</> to cross-check against your boot.ts automatically.');

        return self::SUCCESS;
    }

    /**
     * Extract every key passed to `componentRegistry.register(...)`,
     * `registerFieldDisplay(...)`, `registerFieldInput(...)`, and the
     * per-resource variants from a TypeScript / JavaScript source.
     *
     * Static parser — covers the 95% case (string-literal keys passed
     * directly to the registry methods). Computed keys (`register(\`field:\${kind}\`, ...)`)
     * are intentionally not resolved; if you use those, also pass the
     * `--boot` option pointing at a manifest you generate yourself.
     *
     * @return list<string>
     */
    private function extractRegisteredKeys(string $source): array
    {
        $keys = [];
        // Match both `register('foo', ...)` and shorthand
        // `registerFieldDisplay('text', ...)` / `registerFieldInput`,
        // plus the resource-scoped pair which uses two string args
        // (`registerResourceFieldDisplay('posts', 'status', ...)`).
        $patterns = [
            '/componentRegistry\.register\s*\(\s*[\'"`]([^\'"`]+)[\'"`]/',
            '/registerFieldDisplay\s*\(\s*[\'"`]([^\'"`]+)[\'"`]/',
            '/registerFieldInput\s*\(\s*[\'"`]([^\'"`]+)[\'"`]/',
            '/registerResourceFieldDisplay\s*\(\s*[\'"`]([^\'"`]+)[\'"`]\s*,\s*[\'"`]([^\'"`]+)[\'"`]/',
            '/registerResourceFieldInput\s*\(\s*[\'"`]([^\'"`]+)[\'"`]\s*,\s*[\'"`]([^\'"`]+)[\'"`]/',
        ];
        foreach ($patterns as $i => $pattern) {
            preg_match_all($pattern, $source, $matches);
            if ($i < 3) {
                // single-key patterns
                $keys = array_merge($keys, $matches[1] ?? []);
            } else {
                // (resource, field) → 'field:display:resource:field' / 'field:input:...'
                $kind = $i === 3 ? 'display' : 'input';
                foreach (($matches[1] ?? []) as $idx => $resource) {
                    $field = $matches[2][$idx] ?? '';
                    if ($field !== '') {
                        $keys[] = "field:{$kind}:{$resource}:{$field}";
                    }
                }
            }
        }
        // Resource-uri keys map directly to themselves (no prefix), tools
        // and actions share the same flat string namespace, so the raw
        // matches from `componentRegistry.register('foo', ...)` already
        // cover those cases.

        return array_values(array_unique($keys));
    }

    private function resolveRequest(): Request
    {
        return app('request') instanceof Request ? app('request') : Request::create('/');
    }
}
