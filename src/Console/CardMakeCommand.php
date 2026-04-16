<?php

namespace Martis\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Generate a custom Martis dashboard card (PHP class + React component).
 *
 * Creates:
 *  1. PHP class in app/Martis/Cards/{Name}.php
 *  2. React component in resources/{extensions_path}/martis/components/{Name}.tsx
 *  3. Auto-registers the component in the boot.ts file
 */
#[AsCommand(name: 'martis:card')]
class CardMakeCommand extends GeneratorCommand
{
    protected $signature = 'martis:card {name : The card class name (e.g. WelcomeCard)}';

    protected $description = 'Create a custom dashboard card (PHP + React component)';

    protected $type = 'Martis card';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/card.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Martis\\Cards';
    }

    public function handle(): bool|null
    {
        $name = $this->getNameInput();
        $className = Str::studly($name);
        $kebabName = Str::kebab($name);

        // 1. Generate the PHP class via GeneratorCommand
        $result = parent::handle();

        if ($result === false) {
            return false;
        }

        // 2. Generate the React component
        $this->generateReactComponent($className, $kebabName);

        // 3. Register in boot.ts
        $this->registerInBootFile($className, $kebabName);

        $this->newLine();
        $this->components->info('Card created successfully.');
        $this->newLine();

        $this->line('  <fg=green>PHP class</>:  app/Martis/Cards/'.$className.'.php');
        $this->line('  <fg=green>React</>:      resources/'.config('martis.extensions_path', 'martis-extensions').'/martis/components/'.$className.'.tsx');
        $this->newLine();

        $this->line('  Usage — add to a Dashboard\'s cards() method:');
        $this->line("    <comment>new \\App\\Martis\\Cards\\{$className}(),</comment>");
        $this->newLine();

        $this->line('  Pass data to the React component via withMeta():');
        $this->line("    <comment>(new {$className}())->withMeta(['key' => 'value']),</comment>");
        $this->newLine();

        $extensionsPath = config('martis.extensions_path', 'martis-extensions');
        $this->line('  Rebuild assets:');
        $this->line('    <comment>MARTIS_USER_DIR='.resource_path($extensionsPath).' npm run build</comment>');

        return $result;
    }

    /**
     * Replace stub placeholders with actual values.
     */
    protected function replaceClass($stub, $name): string
    {
        $stub = parent::replaceClass($stub, $name);

        $className = class_basename($name);
        $kebabName = Str::kebab($className);

        return str_replace(
            ['{{ label }}', '{{ key }}'],
            [$className, $kebabName],
            $stub,
        );
    }

    /**
     * Generate the React TSX component file.
     */
    protected function generateReactComponent(string $className, string $kebabName): void
    {
        $extensionsPath = config('martis.extensions_path', 'martis-extensions');
        $componentDir = resource_path($extensionsPath.'/martis/components');
        $componentPath = $componentDir.'/'.$className.'.tsx';

        if (! is_dir($componentDir)) {
            mkdir($componentDir, 0755, true);
        }

        if (file_exists($componentPath)) {
            $this->components->warn("React component already exists: {$componentPath}");

            return;
        }

        $stub = (string) file_get_contents(__DIR__.'/../../stubs/component-card.tsx.stub');

        $content = str_replace(
            ['{{ class }}', '{{ kebab }}'],
            [$className, $kebabName],
            $stub,
        );

        file_put_contents($componentPath, $content);
    }

    /**
     * Register the component in the user boot.ts file.
     */
    protected function registerInBootFile(string $className, string $kebabName): void
    {
        $extensionsPath = config('martis.extensions_path', 'martis-extensions');
        $bootPath = resource_path($extensionsPath.'/martis/boot.ts');

        $bootDir = dirname($bootPath);
        if (! is_dir($bootDir)) {
            mkdir($bootDir, 0755, true);
        }

        $importLine = "import { {$className} } from './components/{$className}'";
        $registerLine = "componentRegistry.register('{$kebabName}', {$className} as never)";

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

            if (! str_contains($content, $registerLine)) {
                $content = rtrim($content)."\n".$registerLine."\n";
            }

            file_put_contents($bootPath, $content);

            return;
        }

        $content = "import { componentRegistry } from '@/lib/componentRegistry'\n";
        $content .= $importLine."\n";
        $content .= "\n".$registerLine."\n";

        file_put_contents($bootPath, $content);
    }
}
