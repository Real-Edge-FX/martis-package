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
                            {--filter= : Substring filter applied to the component key}';

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

        $this->table(['Kind', 'Component key', 'Source'], $rows);

        $this->newLine();
        $this->line(sprintf(
            '%d component key(s) declared. Verify each one is registered in your frontend boot file (e.g. resources/js/boot.ts) via `componentRegistry.register(\'<key>\', Component)`.',
            count($rows),
        ));

        return self::SUCCESS;
    }

    private function resolveRequest(): Request
    {
        return app('request') instanceof Request ? app('request') : Request::create('/');
    }
}
