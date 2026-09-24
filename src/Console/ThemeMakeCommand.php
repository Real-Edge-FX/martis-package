<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Martis\Stubs\StubResolver;
use Martis\Support\ThemeFiles;
use Martis\Support\ThemePublisher;
use RuntimeException;
use Throwable;

class ThemeMakeCommand extends Command
{
    protected $signature = 'martis:theme
                            {name? : The theme name (default: custom)}
                            {--force : Overwrite an existing theme file without prompting}';

    protected $description = 'Scaffold a custom Martis theme with the full design-token surface (dark + light, accents, density, motion)';

    public function handle(): int
    {
        /** @var string $name */
        $name = $this->argument('name') ?? 'custom';
        $name = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]/', '-', $name));

        // 1. Create the theme source in resources/: the file the app owns,
        // edits and commits.
        $dir = ThemeFiles::sourceDirectory();
        $path = ThemeFiles::sourcePath($name);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_exists($path) && ! $this->option('force')) {
            // In non-interactive contexts (CI, pipes, unit tests), prompting is
            // not possible — fail explicitly so automation knows to pass --force.
            if (! $this->input->isInteractive() || app()->runningUnitTests()) {
                $this->components->error("Theme '{$name}.css' already exists. Use --force to overwrite.");

                return self::FAILURE;
            }

            if (! $this->confirm("Theme '{$name}.css' already exists. Overwrite?")) {
                $this->components->warn('Aborted.');

                return self::FAILURE;
            }
        }

        // 2. The source and the published copy are overwritten below. Back up
        // each one that holds content the scaffold and the publish record do
        // not: the source after the edit-and-publish loop, a copy edited in
        // place (the 1.x hint said to edit it) or a copy with no source. All
        // backups are made before any warning, and nothing is written when
        // one fails.
        $contents = $this->renderStub($name);
        $publisher = new ThemePublisher(new Filesystem);
        $previousSource = is_file($path) ? $path : null;
        $published = ThemeFiles::publishedPath($name);

        $atRisk = [];
        // A source that does not open hashes to false: it counts as edited,
        // and its backup fails, so it is never overwritten unseen.
        if ($previousSource !== null && @sha1_file($previousSource) !== sha1($contents)) {
            $atRisk[$previousSource] = "resources/css/martis/{$name}.css differs from the scaffold, and martis:theme replaces it.";
        }
        if (! $publisher->isReproducible($name, $previousSource)) {
            $atRisk[$published] = $previousSource === null
                ? "public/vendor/martis/themes/{$name}.css has no source in resources/css/martis/, and martis:theme replaces it with the scaffold."
                : "public/vendor/martis/themes/{$name}.css differs from its source, and martis:theme replaces it with the scaffold.";
        }

        $backups = [];
        foreach (array_keys($atRisk) as $file) {
            try {
                $backups[$file] = $publisher->backUp($file);
            } catch (Throwable $e) {
                $this->components->error('Could not back up '.$this->relativePath($file).': '.$e->getMessage());
                $this->line('  Nothing was written. Make the file readable and <fg=cyan>storage/app/martis/theme-backups/</> writable,');
                $this->line('  then run the command again.');

                return self::FAILURE;
            }
        }
        $publisher->pruneRuns();

        foreach ($backups as $file => $backup) {
            $this->components->warn($atRisk[$file]);
            $this->line('  '.($backup['reused'] ? 'Already backed up to' : 'Backed up to').' <fg=cyan>'.$this->relativePath($backup['path'])."</>. If it holds edits you want, copy them into resources/css/martis/{$name}.css.");
        }

        // Never write through a symlink left where the source goes.
        if (is_link($path)) {
            unlink($path);
        }

        file_put_contents($path, $contents);
        $this->components->info("Theme created: resources/css/martis/{$name}.css");

        // 3. Publish a copy to public/vendor/martis/themes/ so the blade
        // stylesheet tag can pick it up without a Vite rebuild. The copy is
        // generated: martis:publish-assets rewrites it from the source.
        try {
            $publisher->publish($name, $path);
        } catch (Throwable $e) {
            $this->components->error("Could not publish resources/css/martis/{$name}.css: ".$e->getMessage());

            return self::FAILURE;
        }
        $this->components->info("Published to: public/vendor/martis/themes/{$name}.css");

        try {
            $publisher->saveRecord();
        } catch (Throwable $e) {
            // Only costs a backup: without the record, the next publish
            // treats this copy as edited in place once the source changes.
            $this->components->warn('Could not write '.$this->relativePath(ThemeFiles::recordPath()).': '.$e->getMessage());
        }

        // 4. Update config/martis.php theme.name — scoped to the 'theme'
        // block only, so we don't accidentally rewrite 'name' inside 'brand'
        // or any other sibling config section.
        $configPath = config_path('martis.php');
        if (file_exists($configPath)) {
            $configContent = file_get_contents($configPath);

            if ($configContent === false) {
                return self::SUCCESS;
            }

            $updated = $this->rewriteThemeNameInConfig($configContent, $name);

            if ($updated !== null && $updated !== $configContent) {
                file_put_contents($configPath, $updated);
                $this->components->info("Config updated: config/martis.php theme.name = '{$name}'");
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>Done</>');
        $this->newLine();
        $this->line('  1. Edit CSS variables in <comment>resources/css/martis/'.$name.'.css</comment>');
        $this->line('  2. Publish your changes with <comment>php artisan martis:publish-assets --themes-only</comment> (no Vite rebuild).');
        $this->line('     It copies every theme in resources/css/martis/ to public/vendor/martis/themes/,');
        $this->line('     replacing the copies there: edit the source, never the copy.');
        $this->line('  3. Switch theme in <comment>config/martis.php</comment>:');
        $this->newLine();
        $this->line("     <comment>'theme' => ['name' => '{$name}']</comment>");
        $this->newLine();
        $this->line('  Revert to the built-in theme by setting <comment>theme.name</comment> to <comment>null</comment>.');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function relativePath(string $absolute): string
    {
        $base = base_path();
        if (str_starts_with($absolute, $base)) {
            return ltrim(substr($absolute, strlen($base)), DIRECTORY_SEPARATOR);
        }

        return $absolute;
    }

    protected function stubPath(): string
    {
        return StubResolver::path('theme.css.stub');
    }

    protected function renderStub(string $name): string
    {
        $stub = $this->stubPath();

        if (! is_file($stub)) {
            throw new RuntimeException(
                "Theme stub not found at {$stub}. The package is missing a template file."
            );
        }

        $contents = file_get_contents($stub);

        if ($contents === false) {
            throw new RuntimeException("Unable to read theme stub at {$stub}.");
        }

        return str_replace('{{ name }}', $name, $contents);
    }

    /**
     * Rewrite `theme.name` inside a published `config/martis.php` without
     * touching sibling keys (notably `brand.name`). Locates the `'theme'`
     * block first, then updates or inserts the `name` key inside it.
     *
     * Returns the rewritten content, or null if the file didn't contain
     * a recognisable `'theme' => [...]` block.
     */
    protected function rewriteThemeNameInConfig(string $content, string $name): ?string
    {
        // Match 'theme' => [ ... ] by anchoring on the closing ],? that sits
        // at the same 4-space indentation as the key, so that nested arrays
        // inside the block (e.g. 'accents' => ['blue', 'red']) do not
        // truncate the match at their inner ].
        $pattern = "/('theme'\s*=>\s*\[)(.*?)(\n    \],?)/s";

        return preg_replace_callback(
            $pattern,
            function (array $match) use ($name): string {
                $body = $match[2];

                if (preg_match("/'name'\s*=>\s*(env\([^)]+\)|'[^']*'|null)/", $body)) {
                    $body = (string) preg_replace(
                        "/('name'\s*=>\s*)(env\([^)]+\)|'[^']*'|null)/",
                        "$1'{$name}'",
                        $body,
                        1
                    );
                } elseif (preg_match("/('allowToggle'\s*=>.*?,)/", $body)) {
                    $body = (string) preg_replace(
                        "/('allowToggle'\s*=>.*?,)/",
                        "$1\n        'name' => '{$name}',",
                        $body,
                        1
                    );
                }

                return $match[1].$body.$match[3];
            },
            $content,
            1
        );
    }
}
