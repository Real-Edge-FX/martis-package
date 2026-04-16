<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class FieldMakeCommand extends Command
{
    protected $signature = 'martis:field {name : The field class name (e.g. Rating)}';

    protected $description = 'Create a new Martis field (PHP class + React TSX component)';

    /** Create the command and inject the filesystem dependency. */
    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    /**
     * Handle.
     */
    public function handle(): int
    {
        /** @var string $rawName */
        $rawName = $this->argument('name');
        $name = Str::studly($rawName);

        // Ensure "Field" suffix
        if (! Str::endsWith($name, 'Field')) {
            $name .= 'Field';
        }

        $typeKey = Str::kebab(Str::before($name, 'Field'));

        $this->generatePhpClass($name, $typeKey);
        $this->generateTsxComponent($name, $typeKey);

        $this->newLine();
        $this->components->info("Martis field [{$name}] created successfully.");
        $this->newLine();
        $this->line('  Remember to register the React component in your field registry:');
        $this->line("  <fg=cyan>componentRegistry.register('{$typeKey}', {$name}Display, {$name}Input)</>");
        $this->newLine();

        return self::SUCCESS;
    }

    protected function generatePhpClass(string $name, string $typeKey): void
    {
        $namespace = $this->laravel->getNamespace().'Martis\\Fields';
        $path = app_path("Martis/Fields/{$name}.php");

        if ($this->files->exists($path)) {
            $this->components->warn("PHP class already exists: app/Martis/Fields/{$name}.php");

            return;
        }

        $stub = $this->files->get(__DIR__.'/../../stubs/field.stub');

        $content = str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ type }}'],
            [$namespace, $name, $typeKey],
            $stub
        );

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $content);

        $this->components->twoColumnDetail('<fg=green>PHP class</>', "app/Martis/Fields/{$name}.php");
    }

    protected function generateTsxComponent(string $name, string $typeKey): void
    {
        $path = resource_path("js/martis/fields/{$typeKey}.tsx");

        if ($this->files->exists($path)) {
            $this->components->warn("TSX component already exists: resources/js/martis/fields/{$typeKey}.tsx");

            return;
        }

        $stub = $this->files->get(__DIR__.'/../../stubs/field.tsx.stub');

        $content = str_replace(
            ['{{ class }}', '{{ type }}'],
            [$name, $typeKey],
            $stub
        );

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $content);

        $this->components->twoColumnDetail('<fg=green>TSX component</>', "resources/js/martis/fields/{$typeKey}.tsx");
    }
}
