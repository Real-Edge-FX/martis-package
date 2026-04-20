<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'martis:component', aliases: ['martis:override'])]
class ComponentMakeCommand extends Command
{
    protected $signature = 'martis:component
        {name? : The component class name (e.g. StatusBadge). Optional when --type=complete-layout.}
        {--type=generic : Component type: field | layout | sidebar | topbar | footer | complete-layout | generic}
        {--force : Overwrite the file if it already exists}';

    protected $aliases = ['martis:override'];

    protected $description = 'Scaffold a React override component (TSX) and auto-register it in resources/js/martis/boot.ts';

    /** @var array<string, array{key: string, stub: string}> */
    private const SHELL_PIECES = [
        'shell'   => ['key' => 'layout:shell',   'stub' => 'component-shell.tsx.stub'],
        'sidebar' => ['key' => 'layout:sidebar', 'stub' => 'component-sidebar.tsx.stub'],
        'topbar'  => ['key' => 'layout:topbar',  'stub' => 'component-topbar.tsx.stub'],
        'footer'  => ['key' => 'layout:footer',  'stub' => 'component-footer.tsx.stub'],
    ];

    public function handle(): int
    {
        /** @var string|null $name */
        $name = $this->argument('name');
        /** @var string $type */
        $type = $this->option('type');

        $allowedTypes = ['field', 'layout', 'sidebar', 'topbar', 'footer', 'complete-layout', 'generic'];
        if (! in_array($type, $allowedTypes, true)) {
            $this->error("Invalid type '{$type}'. Allowed: ".implode(', ', $allowedTypes));

            return self::FAILURE;
        }

        if ($type === 'complete-layout') {
            return $this->generateCompleteLayout();
        }

        if ($name === null || $name === '') {
            $this->error("Missing name. Usage: php artisan martis:component <Name> --type={$type}");

            return self::FAILURE;
        }

        return $this->generateSingle($type, $name);
    }

    protected function generateSingle(string $type, string $name): int
    {
        $className = Str::studly($name);
        $kebabName = Str::kebab($name);

        [$baseDir, $componentDir, $bootPath, $extensionsPath] = $this->resolvePaths();
        $componentPath = $componentDir.'/'.$className.'.tsx';

        if (! is_dir($componentDir)) {
            mkdir($componentDir, 0755, true);
        }
        if (! is_dir(dirname($bootPath))) {
            mkdir(dirname($bootPath), 0755, true);
        }

        if (file_exists($componentPath) && ! $this->option('force')) {
            $this->error("Component already exists: {$componentPath}  (re-run with --force to overwrite)");

            return self::FAILURE;
        }

        $stub = $this->getStub($type);
        $content = str_replace(
            ['{{ class }}', '{{ kebab }}'],
            [$className, $kebabName],
            $stub,
        );

        file_put_contents($componentPath, $content);

        $registryKey = $this->resolveRegistryKey($type, $kebabName);
        $this->updateBootFile($bootPath, $className, $registryKey, $type);

        $this->info("Component created: {$componentPath}");

        if ($type === 'field') {
            $this->info("Display registered as '{$registryKey}' in {$bootPath}");
            $this->info("Input registered as '{$registryKey}-input' in {$bootPath}");
            $this->newLine();
            $this->line('Usage in PHP (display — index/detail):');
            $this->line("  ->overrideIndex(new Override('{$kebabName}'))");
            $this->line("  ->overrideDetail(new Override('{$kebabName}'))");
            $this->newLine();
            $this->line('Usage in PHP (input — create/update):');
            $this->line("  ->overrideCreate(new Override('{$kebabName}-input'))");
            $this->line("  ->overrideUpdate(new Override('{$kebabName}-input'))");
        } else {
            $this->info("Registered as '{$registryKey}' in {$bootPath}");
            $this->newLine();

            if (in_array($type, ['sidebar', 'topbar', 'footer', 'layout'], true)) {
                $configHint = $type === 'layout' ? 'shell' : $type;
                $this->line('This component plugs into the shell — no further wiring needed.');
                $this->line("Optional: pin it explicitly from PHP by setting");
                $this->line("  <comment>'layout' => ['components' => ['{$configHint}' => '{$registryKey}']]</comment>");
            } else {
                $this->line('Usage in PHP (field type):');
                $this->line("  Text::make('field_name')->component('{$kebabName}')");
            }
        }

        $this->newLine();
        $this->line('Rebuild assets with:');
        $this->line("  <comment>MARTIS_USER_DIR=".resource_path($extensionsPath)." npm run build</comment>");

        return self::SUCCESS;
    }

    /**
     * Scaffold all four shell pieces at once (shell + sidebar + topbar + footer).
     * Each lands under its default registry key so the pieces work out of
     * the box. Names default to CustomShell / CustomSidebar / …; pass the
     * `name` argument to use a project-specific prefix (e.g. "Acme"
     * generates AcmeShell, AcmeSidebar, AcmeTopbar, AcmeFooter).
     */
    protected function generateCompleteLayout(): int
    {
        /** @var string|null $prefixArg */
        $prefixArg = $this->argument('name');
        $prefix = $prefixArg !== null && $prefixArg !== '' ? Str::studly($prefixArg) : 'Custom';

        [$baseDir, $componentDir, $bootPath, $extensionsPath] = $this->resolvePaths();

        if (! is_dir($componentDir)) {
            mkdir($componentDir, 0755, true);
        }
        if (! is_dir(dirname($bootPath))) {
            mkdir(dirname($bootPath), 0755, true);
        }

        $created = [];
        foreach (self::SHELL_PIECES as $piece => $meta) {
            $className = $prefix.Str::studly($piece);
            $kebabName = Str::kebab($className);
            $componentPath = $componentDir.'/'.$className.'.tsx';

            if (file_exists($componentPath) && ! $this->option('force')) {
                $this->warn("Skipped {$componentPath} (already exists, use --force to overwrite)");

                continue;
            }

            $stub = $this->getStub($piece);
            $content = str_replace(
                ['{{ class }}', '{{ kebab }}'],
                [$className, $kebabName],
                $stub,
            );
            file_put_contents($componentPath, $content);
            $this->updateBootFile($bootPath, $className, $meta['key'], $piece);

            $created[] = [$className, $meta['key'], $componentPath];
        }

        if ($created === []) {
            $this->warn('No files were created.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Complete layout scaffolded:');
        foreach ($created as [$class, $key, $path]) {
            $this->line("  <fg=green>✓</> {$class}  →  '{$key}'  ({$path})");
        }

        $this->newLine();
        $this->line('Edit the generated files to match your brand, then rebuild:');
        $this->line("  <comment>MARTIS_USER_DIR=".resource_path($extensionsPath)." npm run build</comment>");
        $this->newLine();
        $this->line('No config change required — the pieces are registered under the default');
        $this->line("`layout:*` keys. Set <comment>config('martis.layout.preset') = 'custom'</comment> if you");
        $this->line('want the shell to skip the bundled fallback and require your override.');

        return self::SUCCESS;
    }

    /**
     * @return array{0:string, 1:string, 2:string, 3:string}
     */
    protected function resolvePaths(): array
    {
        $extensionsPath = config('martis.extensions_path', 'martis-extensions');
        $baseDir = resource_path($extensionsPath.'/martis');

        return [$baseDir, $baseDir.'/components', $baseDir.'/boot.ts', $extensionsPath];
    }

    protected function resolveRegistryKey(string $type, string $kebabName): string
    {
        return match ($type) {
            'footer' => 'layout:footer',
            'layout', 'shell' => 'layout:shell',
            'sidebar' => 'layout:sidebar',
            'topbar' => 'layout:topbar',
            default => $kebabName,
        };
    }

    /**
     * Get the stub file contents for the given component type.
     */
    protected function getStub(string $type): string
    {
        $stubPath = __DIR__.'/../../stubs/component-'.$type.'.tsx.stub';

        if (! file_exists($stubPath)) {
            $stubPath = __DIR__.'/../../stubs/component-generic.tsx.stub';
        }

        return (string) file_get_contents($stubPath);
    }

    /**
     * Register the component in the user boot file.
     */
    protected function updateBootFile(string $bootPath, string $className, string $registryKey, string $type): void
    {
        if ($type === 'field') {
            $importLine = "import { {$className}Display, {$className}Input } from './components/{$className}'";
            $registerLines = [
                "componentRegistry.register('{$registryKey}', {$className}Display as never)",
                "componentRegistry.register('{$registryKey}-input', {$className}Input as never)",
            ];
        } else {
            $importLine = "import { {$className} } from './components/{$className}'";
            $registerLines = [
                "componentRegistry.register('{$registryKey}', {$className} as never)",
            ];
        }

        if (file_exists($bootPath)) {
            $content = (string) file_get_contents($bootPath);

            if (! str_contains($content, "import { componentRegistry } from '@/lib/componentRegistry'")) {
                $content = "import { componentRegistry } from '@/lib/componentRegistry'\n".$content;
            }

            if (! str_contains($content, $importLine)) {
                $lines = explode("\n", $content);
                $lastImportIndex = -1;

                foreach ($lines as $i => $line) {
                    if (str_starts_with(trim($line), 'import ')) {
                        $lastImportIndex = $i;
                    }
                }

                if ($lastImportIndex >= 0) {
                    array_splice($lines, $lastImportIndex + 1, 0, [$importLine]);
                } else {
                    array_unshift($lines, $importLine);
                }

                $content = implode("\n", $lines);
            }

            if (! str_contains($content, '// Auto-registered by martis:component')) {
                $content = rtrim($content)."\n\n// Auto-registered by martis:component\n";
            }

            foreach ($registerLines as $registerLine) {
                if (! str_contains($content, $registerLine)) {
                    $content = rtrim($content)."\n".$registerLine."\n";
                }
            }

            file_put_contents($bootPath, $content);

            return;
        }

        $content = "import { componentRegistry } from '@/lib/componentRegistry'\n";
        $content .= $importLine."\n";
        $content .= "\n// Auto-registered by martis:component\n";

        foreach ($registerLines as $registerLine) {
            $content .= $registerLine."\n";
        }

        file_put_contents($bootPath, $content);
    }
}
