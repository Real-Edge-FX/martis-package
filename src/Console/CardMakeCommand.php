<?php

namespace Martis\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Martis\Stubs\StubResolver;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Generate a custom Martis dashboard card (PHP class + React component).
 *
 * Outputs (v1.9.0+ zero-config convention):
 *  1. PHP class at `app/Martis/Cards/{Name}.php`.
 *  2. React component at `resources/js/martis-extensions/cards/{Name}.tsx`.
 *
 * **No boot.ts editing.** The auto-discovery entry shipped by
 * `martis:install` (`resources/js/martis-extensions/index.ts`) walks
 * the `cards/` bucket via `import.meta.glob` and registers every
 * `.tsx` against `window.Martis.componentRegistry` under the key
 * derived from the filename: `RevenueGauge.tsx` → `card:revenue-gauge`.
 * The PHP `Card` binds to the same key implicitly via its kebab name.
 */
#[AsCommand(name: 'martis:card')]
class CardMakeCommand extends GeneratorCommand
{
    protected $signature = 'martis:card
        {name : The card class name (e.g. WelcomeCard)}
        {--force : Overwrite the TSX component if it already exists}';

    protected $description = 'Create a custom dashboard card (PHP + React component)';

    protected $type = 'Martis card';

    protected function getStub(): string
    {
        return StubResolver::path('card.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Martis\\Cards';
    }

    public function handle(): ?bool
    {
        $name = $this->getNameInput();
        $className = Str::studly($name);
        $kebabName = Str::kebab($name);

        // 1. Generate the PHP class via GeneratorCommand
        $result = parent::handle();

        if ($result === false) {
            return false;
        }

        // 2. Generate the React component into the auto-discovery bucket.
        $tsxWritten = $this->generateReactComponent($className, $kebabName);

        $this->newLine();
        $this->components->info('Card created successfully.');
        $this->newLine();

        $this->line('  <fg=green>PHP class</>:  app/Martis/Cards/'.$className.'.php');
        if ($tsxWritten) {
            $this->line('  <fg=green>React</>:      resources/js/martis-extensions/cards/'.$className.'.tsx');
        }
        $this->newLine();

        $this->line('  Usage — add to a Dashboard\'s cards() method:');
        $this->line("    <comment>new \\App\\Martis\\Cards\\{$className}(),</comment>");
        $this->newLine();

        $this->line('  Pass data to the React component via withMeta():');
        $this->line("    <comment>(new {$className}())->withMeta(['key' => 'value']),</comment>");
        $this->newLine();

        $this->line('  Build the extension bundle:');
        $this->line('    <comment>npm run build:extensions</comment>');
        $this->line('    (or just deploy — the build also runs in deploy.sh)');

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
     * Generate the React TSX component file at the auto-discovery
     * path. Returns false when the file exists and the dev declines
     * to overwrite.
     */
    protected function generateReactComponent(string $className, string $kebabName): bool
    {
        $relative = "resources/js/martis-extensions/cards/{$className}.tsx";
        $componentPath = base_path($relative);

        if (file_exists($componentPath)) {
            if ($this->option('force') !== true) {
                if (! $this->input->isInteractive() || $this->laravel->runningUnitTests()) {
                    $this->components->warn("React component already exists: {$relative}");
                    $this->line('  Pass <fg=cyan>--force</> to overwrite.');

                    return false;
                }
                if (! $this->confirm("{$relative} already exists. Overwrite?", false)) {
                    return false;
                }
            }
        }

        $stub = (string) file_get_contents(StubResolver::path('component-card.tsx.stub'));

        $content = str_replace(
            ['{{ class }}', '{{ kebab }}'],
            [$className, $kebabName],
            $stub,
        );

        @mkdir(dirname($componentPath), 0755, true);
        file_put_contents($componentPath, $content);

        return true;
    }
}
