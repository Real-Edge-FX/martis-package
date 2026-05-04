<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Martis\Stubs\StubResolver;

class FieldMakeCommand extends Command
{
    protected $signature = 'martis:field
        {name : The field class name (e.g. Rating)}
        {--force : Overwrite the TSX component if it already exists}';

    protected $description = 'Create a new Martis field (PHP class + React TSX component)';

    /** Create the command and inject the filesystem dependency. */
    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    /**
     * Generate the PHP field class and the matching TSX component.
     *
     * The TSX lives at `resources/js/martis-extensions/fields/{Name}.tsx`
     * (v1.9.0+ auto-discovery convention). The bundle's entry index
     * derives the registry key from the filename via PascalCase →
     * kebab: `Rating.tsx` → `field:rating`. The PHP class binds to the
     * same key implicitly because `Field::component()` defaults to
     * `Str::kebab($name)`. No manual registration anywhere.
     */
    public function handle(): int
    {
        /** @var string $rawName */
        $rawName = $this->argument('name');
        $name = Str::studly($rawName);

        if (! Str::endsWith($name, 'Field')) {
            $name .= 'Field';
        }

        // Bare class basename (without the `Field` suffix) is what the
        // auto-discovery filename uses, so the derived registry key
        // matches what `Field::component()` produces by default.
        $tsxBaseName = Str::before($name, 'Field') !== '' ? Str::before($name, 'Field') : $name;
        $typeKey = Str::kebab($tsxBaseName);

        $this->generatePhpClass($name, $typeKey);

        if (! $this->generateTsxComponent($name, $tsxBaseName, $typeKey)) {
            // TSX scaffold aborted (collision without --force in
            // non-interactive shells) — exit early but leave the PHP
            // file in place. The dev can rerun with --force once
            // they're ready to overwrite.
            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info("Martis field [{$name}] created successfully.");
        $this->newLine();
        $this->line('  Run <fg=cyan>npm run build:extensions</> to compile the field bundle');
        $this->line('  (or just deploy — the build runs in <fg=cyan>deploy.sh</>).');
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

        $stub = $this->files->get(StubResolver::path('field.stub'));

        $content = str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ type }}'],
            [$namespace, $name, $typeKey],
            $stub
        );

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $content);

        $this->components->twoColumnDetail('<fg=green>PHP class</>', "app/Martis/Fields/{$name}.php");
    }

    /**
     * Drop the TSX component into the auto-discovery `fields/` bucket.
     *
     * Returns false when the destination exists and the dev declines
     * to overwrite (or the shell is non-interactive without `--force`).
     */
    protected function generateTsxComponent(string $name, string $tsxBaseName, string $typeKey): bool
    {
        $relative = "resources/js/martis-extensions/fields/{$tsxBaseName}.tsx";
        $path = base_path($relative);

        if ($this->files->exists($path)) {
            if ($this->option('force') !== true) {
                if (! $this->input->isInteractive() || $this->laravel->runningUnitTests()) {
                    $this->components->warn("TSX component already exists: {$relative}");
                    $this->line('  Pass <fg=cyan>--force</> to overwrite.');

                    return false;
                }
                if (! $this->confirm("{$relative} already exists. Overwrite?", false)) {
                    return false;
                }
            }
        }

        $stub = $this->files->get(StubResolver::path('field.tsx.stub'));

        $content = str_replace(
            ['{{ class }}', '{{ type }}', '{{ kebab }}'],
            [$name, $typeKey, $typeKey],
            $stub
        );

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $content);

        $this->components->twoColumnDetail('<fg=green>TSX component</>', $relative);

        return true;
    }
}
