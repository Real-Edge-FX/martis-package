<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Martis\Stubs\StubResolver;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Scaffold a new Martis Tool (free-form sidebar page) — v0.10.
 *
 * Generates the PHP `Tool` subclass under `App\Martis\Tools`. With
 * `--with-component`, also drops a paired TSX stub in the consumer's
 * frontend tree so the user only has to register the component key in
 * their `boot.ts` and the page works end-to-end.
 *
 * Examples:
 *
 *   php artisan martis:tool SystemStatus
 *     # → app/Martis/Tools/SystemStatus.php only.
 *
 *   php artisan martis:tool SystemStatus --with-component
 *     # → app/Martis/Tools/SystemStatus.php + resources/js/tools/SystemStatusTool.tsx
 *
 *   php artisan martis:tool SystemStatus --component-key=tool:system-status
 *     # → uses the explicit key in the PHP `withComponent(...)` call.
 *
 *   php artisan martis:tool SystemStatus --use-bundled
 *     # → binds to the bundled `martis:tool:system-status-demo` component
 *       so the Tool renders out of the box without writing any TSX.
 */
#[AsCommand(name: 'martis:tool')]
class ToolMakeCommand extends GeneratorCommand
{
    protected $signature = 'martis:tool
        {name : The Tool class name (e.g. SystemStatus)}
        {--with-component : Also scaffold a paired React component stub under resources/js/tools/}
        {--component-key= : Explicit component key for the React renderer (defaults to tool:<kebab-name>)}
        {--use-bundled : Bind to the package-bundled SystemStatusDemo component instead of generating one}
        {--menu-section= : Optional menu section label (e.g. "Operations")}
        {--icon=wrench : Phosphor icon name for the menu entry}
        {--force : Overwrite the file if it already exists}';

    protected $description = 'Create a new Martis Tool (free-form sidebar page) — v0.10';

    protected $type = 'Martis tool';

    protected function getStub(): string
    {
        return StubResolver::path('tool.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Martis\\Tools';
    }

    /**
     * Resolve the placeholders that are specific to the Tool stub —
     * `{{ uri_key }}`, `{{ component_key }}`, `{{ icon }}`,
     * `{{ menu_section }}`. Then defer the namespace / class
     * placeholders to the parent.
     */
    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $shortName = class_basename($name);
        // Convention: TSX filename is the bare class basename (e.g.
        // `Charts.tsx`, NOT `ChartsTool.tsx`). The auto-discovery
        // entry derives the registry key from the filename — keeping
        // the filename and uriKey aligned avoids a key-vs-uriKey
        // mismatch surprise later.
        $uriKey = Str::kebab($shortName);

        $componentKey = (string) $this->option('component-key');
        if ($componentKey === '') {
            $componentKey = $this->option('use-bundled')
                ? 'martis:tool:system-status-demo'
                : 'tool:'.$uriKey;
        }

        $menuSection = $this->option('menu-section');
        $menuSectionLine = is_string($menuSection) && $menuSection !== ''
            ? "->withMenuSection('".addslashes($menuSection)."')"
            : '';

        $replacements = [
            '{{ uri_key }}' => $uriKey,
            '{{uri_key}}' => $uriKey,
            '{{ component_key }}' => $componentKey,
            '{{component_key}}' => $componentKey,
            '{{ icon }}' => (string) ($this->option('icon') ?: 'wrench'),
            '{{icon}}' => (string) ($this->option('icon') ?: 'wrench'),
            '{{ menu_section_line }}' => $menuSectionLine,
            '{{menu_section_line}}' => $menuSectionLine,
            '{{ display_name }}' => $this->humanize($shortName),
            '{{display_name}}' => $this->humanize($shortName),
        ];

        return strtr($stub, $replacements);
    }

    /**
     * Hook into the standard Laravel generator flow to also drop the
     * paired TSX component when the user asks for it.
     */
    public function handle(): ?bool
    {
        $result = parent::handle();
        if ($result === false) {
            return false;
        }

        if ($this->option('with-component') && ! $this->option('use-bundled')) {
            $this->scaffoldReactComponent();
        }

        $this->printNextSteps();

        return $result;
    }

    /**
     * Drop a TSX stub at `resources/js/martis-extensions/tools/{Name}.tsx`.
     *
     * Filename convention is the bare class basename (no "Tool"
     * suffix) so the auto-discovery entry — which lives at
     * `resources/js/martis-extensions/index.ts` and ships from
     * `martis:install` — derives the registry key directly from the
     * filename: `Charts.tsx` → `tool:charts`, matching the PHP Tool's
     * `withComponent('tool:charts')`.
     *
     * Aborts with a `[y/N]` confirmation when the destination file
     * already exists or when the derived component key is already
     * occupied by another bucket file. `--force` skips the
     * confirmation; otherwise the command exits without touching the
     * filesystem.
     */
    protected function scaffoldReactComponent(): void
    {
        $shortName = class_basename($this->getNameInput());
        $componentKey = (string) ($this->option('component-key') ?: 'tool:'.Str::kebab($shortName));
        $componentName = $shortName.'Tool';
        $relativePath = 'resources/js/martis-extensions/tools/'.$shortName.'.tsx';
        $absolutePath = base_path($relativePath);

        if (! $this->confirmCollisionForExtension($absolutePath, $componentKey, $relativePath)) {
            return;
        }

        $stub = file_get_contents(StubResolver::path('tool-component.tsx.stub')) ?: '';
        $rendered = strtr($stub, [
            '{{ component_name }}' => $componentName,
            '{{component_name}}' => $componentName,
            '{{ component_key }}' => $componentKey,
            '{{component_key}}' => $componentKey,
            '{{ class_short }}' => $shortName,
            '{{class_short}}' => $shortName,
            '{{ display_name }}' => $this->humanize($shortName),
            '{{display_name}}' => $this->humanize($shortName),
        ]);

        @mkdir(dirname($absolutePath), 0755, true);
        file_put_contents($absolutePath, $rendered);

        $this->components->info("[{$relativePath}] component stub created.");
    }

    /**
     * Detect collisions with previously-generated extension files
     * before writing anything. Two cases produce a prompt:
     *
     *   1. The destination TSX path already exists.
     *   2. Another `.tsx` under `resources/js/martis-extensions/`
     *      derives the same component key (e.g. another bucket has
     *      a same-named file). The auto-discovery loop would then
     *      register the second module against an already-taken key
     *      and the first registration would silently win — easier
     *      to surface the conflict at scaffold time.
     *
     * Returns false when the user declines to overwrite. `--force`
     * skips the prompt.
     */
    protected function confirmCollisionForExtension(string $absolutePath, string $componentKey, string $relativePath): bool
    {
        $force = (bool) $this->option('force');
        $conflicts = [];

        if (file_exists($absolutePath)) {
            $conflicts[] = $relativePath;
        }

        // Search every extension bucket for a file that derives the
        // same key. Keys are scoped by bucket prefix in the auto-
        // discovery entry, so cross-bucket "collisions" are technically
        // safe — we still flag them because the operator probably
        // does not intend two TSX files with the same basename
        // scattered across `tools/` and, say, `cards/`.
        $extensionsRoot = base_path('resources/js/martis-extensions');
        if (is_dir($extensionsRoot)) {
            $shortName = class_basename($this->getNameInput());
            $patterns = [
                $extensionsRoot.'/tools/'.$shortName.'.tsx',
                $extensionsRoot.'/fields/'.$shortName.'.tsx',
                $extensionsRoot.'/cards/'.$shortName.'.tsx',
                $extensionsRoot.'/overrides/'.$shortName.'.tsx',
            ];
            foreach ($patterns as $candidate) {
                if (file_exists($candidate) && realpath($candidate) !== realpath($absolutePath)) {
                    $conflicts[] = ltrim(str_replace(base_path(), '', $candidate), '/\\');
                }
            }
        }

        if ($conflicts === []) {
            return true;
        }

        $this->components->warn('Conflict detected for component key '.$componentKey.':');
        foreach ($conflicts as $path) {
            $this->line("  - <fg=yellow>{$path}</>");
        }

        if ($force) {
            $this->components->info('--force was passed; overwriting.');

            return true;
        }

        if (! $this->input->isInteractive() || $this->laravel->runningUnitTests()) {
            $this->components->error('Aborting (non-interactive). Pass --force to overwrite.');

            return false;
        }

        return (bool) $this->confirm('Overwrite the existing file(s) and continue?', false);
    }

    /**
     * Print the post-scaffold one-liner. The v1.9 zero-config flow
     * removed the manual "Register in MartisServiceProvider" and
     * "Register in boot.ts" steps — auto-discovery now covers both.
     * Only the build reminder remains.
     */
    protected function printNextSteps(): void
    {
        $shortName = class_basename($this->getNameInput());
        $componentKey = (string) ($this->option('component-key') ?: 'tool:'.Str::kebab($shortName));

        $this->newLine();
        $this->components->info('Next steps:');

        if ($this->option('use-bundled')) {
            $this->line('  Bound to bundled component <fg=yellow>martis:tool:system-status-demo</> — no build required.');
        } elseif ($this->option('with-component')) {
            $this->line("  Run <fg=cyan>npm run build:extensions</> to compile the component (key <fg=yellow>{$componentKey}</>),");
            $this->line('  or just deploy — the build step also runs in <fg=cyan>deploy.sh</>.');
        } else {
            $this->line("  Bind a component to the key <fg=yellow>{$componentKey}</> by either:");
            $this->line('  - Re-running with <fg=cyan>--with-component</> to scaffold the TSX stub, or');
            $this->line('  - Pointing at a bundled component via <fg=cyan>--use-bundled</>.');
        }

        $this->newLine();
    }

    /**
     * Turn `SystemStatus` into `System Status`.
     */
    protected function humanize(string $name): string
    {
        return Str::headline($name);
    }
}
