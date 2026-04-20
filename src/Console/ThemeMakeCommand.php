<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use RuntimeException;

class ThemeMakeCommand extends Command
{
    protected $signature = 'martis:theme {name? : The theme name (default: custom)}';

    protected $description = 'Scaffold a custom Martis theme with the full design-token surface (dark + light, accents, density, motion)';

    public function handle(): int
    {
        /** @var string $name */
        $name = $this->argument('name') ?? 'custom';
        $name = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]/', '-', $name));

        // 1. Create source CSS in resources/ (editable copy the app owns).
        $dir = resource_path('css/martis');
        $path = $dir.'/'.$name.'.css';

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_exists($path) && ! $this->confirm("Theme '{$name}.css' already exists. Overwrite?")) {
            $this->components->warn('Aborted.');

            return self::FAILURE;
        }

        $contents = $this->renderStub($name);
        file_put_contents($path, $contents);
        $this->components->info("Theme created: resources/css/martis/{$name}.css");

        // 2. Publish to public/vendor/martis/themes/ so the blade stylesheet tag
        // can pick it up without a Vite rebuild.
        $publicDir = public_path('vendor/martis/themes');
        if (! is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }
        copy($path, $publicDir.'/'.$name.'.css');
        $this->components->info("Published to: public/vendor/martis/themes/{$name}.css");

        // 3. Update config/martis.php theme.name — scoped to the 'theme'
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
        $this->line('  1. Edit CSS variables in <comment>public/vendor/martis/themes/'.$name.'.css</comment>');
        $this->line('  2. Changes take effect immediately (plain CSS, no rebuild needed).');
        $this->line('  3. Switch theme in <comment>config/martis.php</comment>:');
        $this->newLine();
        $this->line("     <comment>'theme' => ['name' => '{$name}']</comment>");
        $this->newLine();
        $this->line('  Revert to the built-in theme by setting <comment>theme.name</comment> to <comment>null</comment>.');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function stubPath(): string
    {
        return __DIR__.'/../../stubs/theme.css.stub';
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
        // Match 'theme' => [ ... ] non-greedily, capturing the inner body.
        $pattern = "/('theme'\s*=>\s*\[)([^\]]*)(\])/s";

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
