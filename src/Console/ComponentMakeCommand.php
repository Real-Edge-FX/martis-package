<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'martis:component')]
class ComponentMakeCommand extends Command
{
    protected $signature = 'martis:component
        {name : The component name (e.g. StatusBadge)}
        {--type=generic : Component type: field, layout, footer, generic}';

    protected $description = 'Generate a React component and auto-register it in the boot file';

    /**
     * Handle.
     */
    public function handle(): int
    {
        /** @var string $name */
        $name = $this->argument('name');
        /** @var string $type */
        $type = $this->option('type');

        $allowedTypes = ['field', 'layout', 'footer', 'generic'];
        if (! in_array($type, $allowedTypes, true)) {
            $this->error("Invalid type '{$type}'. Allowed: ".implode(', ', $allowedTypes));

            return self::FAILURE;
        }

        $className = Str::studly($name);
        $kebabName = Str::kebab($name);

        $baseDir = resource_path('js/user/martis');
        $componentDir = $baseDir.'/components';
        $componentPath = $componentDir.'/'.$className.'.tsx';
        $bootPath = $baseDir.'/boot.ts';

        if (! is_dir($componentDir)) {
            mkdir($componentDir, 0755, true);
        }

        if (! is_dir(dirname($bootPath))) {
            mkdir(dirname($bootPath), 0755, true);
        }

        if (file_exists($componentPath)) {
            $this->error("Component already exists: {$componentPath}");

            return self::FAILURE;
        }

        $stub = $this->getStub($type);
        $content = str_replace(
            ['{{ class }}', '{{ kebab }}'],
            [$className, $kebabName],
            $stub,
        );

        file_put_contents($componentPath, $content);

        $registryKey = match ($type) {
            'footer' => 'layout:footer',
            'layout' => 'layout:shell',
            default => $kebabName,
        };

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
            $this->line('Usage in PHP (field type):');
            $this->line("  Text::make('field_name')->component('{$kebabName}')");
        }

        $this->newLine();
        $this->line("Don't forget to rebuild assets: <comment>npm run build</comment>");

        return self::SUCCESS;
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
