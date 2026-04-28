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
     * Drop a TSX stub at `resources/js/tools/{Name}Tool.tsx`. The
     * generated component is a small "hello world" with the Tool
     * descriptor wired up — enough that the user can build on top of
     * it without reading the docs first.
     */
    protected function scaffoldReactComponent(): void
    {
        $shortName = class_basename($this->getNameInput());
        $componentKey = (string) ($this->option('component-key') ?: 'tool:'.Str::kebab($shortName));
        $componentName = $shortName.'Tool';
        $relativePath = 'resources/js/tools/'.$componentName.'.tsx';
        $absolutePath = base_path($relativePath);

        if (file_exists($absolutePath) && ! $this->option('force')) {
            $this->components->warn("[{$relativePath}] already exists; pass --force to overwrite.");

            return;
        }

        $stub = file_get_contents(StubResolver::path('tool-component.tsx.stub')) ?: '';
        $rendered = strtr($stub, [
            '{{ component_name }}' => $componentName,
            '{{component_name}}' => $componentName,
            '{{ component_key }}' => $componentKey,
            '{{component_key}}' => $componentKey,
            '{{ display_name }}' => $this->humanize($shortName),
            '{{display_name}}' => $this->humanize($shortName),
        ]);

        @mkdir(dirname($absolutePath), 0755, true);
        file_put_contents($absolutePath, $rendered);

        $this->components->info("[{$relativePath}] component stub created.");
    }

    /**
     * Print a small "what to do next" block so the user does not need
     * to alt-tab to the docs after running the command.
     */
    protected function printNextSteps(): void
    {
        $shortName = class_basename($this->getNameInput());
        $namespace = trim($this->getDefaultNamespace($this->rootNamespace()), '\\');
        $fullClass = "{$namespace}\\{$shortName}";
        $componentKey = (string) ($this->option('component-key') ?: 'tool:'.Str::kebab($shortName));

        $this->newLine();
        $this->components->info('Next steps:');
        $this->line('  <fg=gray>1.</> Register the Tool in your service provider:');
        $this->line('');
        $this->line("     <fg=cyan>use {$fullClass};</>");
        $this->line("     <fg=cyan>Martis::tools([{$shortName}::class]);</>");
        $this->line('');
        $this->line('  <fg=gray>2.</> Surface it in the menu (optional):');
        $this->line('');
        $this->line("     <fg=cyan>MenuItem::tool({$shortName}::class)</>");
        $this->line('');

        if ($this->option('use-bundled')) {
            $this->line('  <fg=gray>3.</> The bundled `martis:tool:system-status-demo` React component renders this Tool as-is.');
        } elseif ($this->option('with-component')) {
            $this->line('  <fg=gray>3.</> Register your component in `resources/js/martis/boot.ts`:');
            $this->line('');
            $this->line("     <fg=cyan>componentRegistry.register('{$componentKey}', {$shortName}Tool)</>");
        } else {
            $this->line("  <fg=gray>3.</> Bind a React component to the key <fg=yellow>'{$componentKey}'</> in `boot.ts`,");
            $this->line('     <fg=gray>   </> or rerun with <fg=yellow>--with-component</> to scaffold a TSX stub.');
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
