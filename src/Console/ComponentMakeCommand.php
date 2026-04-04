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

        // Determine paths
        $componentDir = resource_path('js/martis/components');
        $componentPath = $componentDir.'/'.$className.'.tsx';
        $bootPath = resource_path('js/martis/boot.ts');

        // Create directory if needed
        if (! is_dir($componentDir)) {
            mkdir($componentDir, 0755, true);
        }

        // Check if component already exists
        if (file_exists($componentPath)) {
            $this->error("Component already exists: {$componentPath}");

            return self::FAILURE;
        }

        // Generate component from stub
        $stub = $this->getStub($type);
        $content = str_replace('{{ class }}', $className, $stub);
        file_put_contents($componentPath, $content);

        // Determine registry key based on type
        $registryKey = match ($type) {
            'footer' => 'layout:footer',
            'layout' => 'layout:shell',
            default => $kebabName,
        };

        // Auto-register in boot file
        $this->updateBootFile($bootPath, $className, $registryKey, $type);

        $this->info("Component created: {$componentPath}");
        $this->info("Registered as '{$registryKey}' in {$bootPath}");
        $this->newLine();
        $this->line('Usage in PHP (field type):');
        $this->line("  Text::make('field_name')->component('{$kebabName}')");
        $this->newLine();
        $this->line("Don't forget to rebuild assets: <comment>make build</comment>");

        return self::SUCCESS;
    }

    protected function getStub(string $type): string
    {
        $stubPath = __DIR__.'/../../stubs/component-'.$type.'.tsx.stub';

        if (! file_exists($stubPath)) {
            // Fallback to generic
            $stubPath = __DIR__.'/../../stubs/component-generic.tsx.stub';
        }

        return (string) file_get_contents($stubPath);
    }

    protected function updateBootFile(string $bootPath, string $className, string $registryKey, string $type): void
    {
        $importLine = "import { {$className} } from './components/{$className}'";

        if ($type === 'field') {
            $registerLine = "componentRegistry.register('{$registryKey}', {$className})";
        } elseif ($type === 'layout') {
            $registerLine = "componentRegistry.register('{$registryKey}', {$className})";
        } elseif ($type === 'footer') {
            $registerLine = "componentRegistry.register('{$registryKey}', {$className})";
        } else {
            $registerLine = "componentRegistry.register('{$registryKey}', {$className})";
        }

        if (file_exists($bootPath)) {
            $content = (string) file_get_contents($bootPath);

            // Add import if not already present
            if (! str_contains($content, $importLine)) {
                // Find last import line and add after it
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

            // Add register call if not already present
            if (! str_contains($content, $registerLine)) {
                $content .= "\n{$registerLine}\n";
            }

            file_put_contents($bootPath, $content);
        } else {
            // Create boot file from scratch
            $content = "import { componentRegistry } from '@/lib/componentRegistry'\n";
            $content .= $importLine."\n";
            $content .= "\n// Auto-registered by martis:component\n";
            $content .= $registerLine."\n";

            file_put_contents($bootPath, $content);
        }
    }
}
