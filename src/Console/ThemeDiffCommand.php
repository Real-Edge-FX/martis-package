<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * martis:theme:diff
 *
 * Compare a consumer's published theme CSS against the bundled package
 * tokens and report any `--martis-*` variables the consumer has not
 * defined. Useful after upgrading martis/martis when the new release
 * introduces tokens the consumer's custom theme didn't know about: the
 * fallback to the package default keeps the panel functional, but a
 * brand-conscious team usually wants to declare the new tokens
 * explicitly.
 *
 * Reports three sets:
 *
 *   - **Missing**  — tokens the package defines that the consumer
 *                    theme does not.
 *   - **Unknown**  — tokens the consumer defines that no longer exist
 *                    in the package (typo, removed in an upgrade, or
 *                    a project-private token that doesn't follow the
 *                    `--martis-*` namespace).
 *   - **Match**    — tokens both files declare. Counted, not listed.
 */
class ThemeDiffCommand extends Command
{
    protected $signature = 'martis:theme:diff
                            {theme? : Theme name (default: read from config(\'martis.theme.name\'))}
                            {--show-match : Also list tokens both files declare}';

    protected $description = 'Compare a consumer theme against the bundled package tokens';

    public function handle(Filesystem $filesystem): int
    {
        $themeArg = (string) $this->argument('theme');
        $themeName = $themeArg !== '' ? $themeArg : (string) config('martis.theme.name', '');

        if ($themeName === '') {
            $this->components->error('No theme specified and `martis.theme.name` is null.');
            $this->line('  Pass a theme name explicitly: <fg=cyan>php artisan martis:theme:diff mytheme</>');

            return self::FAILURE;
        }

        $consumerPath = public_path('vendor/martis/themes/'.$themeName.'.css');
        $packagePath = __DIR__.'/../../resources/css/martis.css';

        if (! $filesystem->exists($consumerPath)) {
            $this->components->error("Consumer theme not found: {$consumerPath}");
            $this->line('  Did you forget to <fg=cyan>php artisan martis:theme '.$themeName.'</>?');

            return self::FAILURE;
        }
        if (! $filesystem->exists($packagePath)) {
            $this->components->error("Package CSS not found at expected path: {$packagePath}");

            return self::FAILURE;
        }

        $packageTokens = $this->extractTokens($filesystem->get($packagePath));
        $consumerTokens = $this->extractTokens($filesystem->get($consumerPath));

        $missing = array_values(array_diff($packageTokens, $consumerTokens));
        $unknown = array_values(array_diff($consumerTokens, $packageTokens));
        $match = array_values(array_intersect($packageTokens, $consumerTokens));

        sort($missing);
        sort($unknown);
        sort($match);

        $this->newLine();
        $this->components->twoColumnDetail('Theme', "<fg=cyan>{$themeName}</>");
        $this->components->twoColumnDetail('Package CSS tokens', (string) count($packageTokens));
        $this->components->twoColumnDetail('Consumer theme tokens', (string) count($consumerTokens));

        $this->newLine();
        $this->components->info('Missing in consumer ('.count($missing).')');
        if ($missing === []) {
            $this->line('  <fg=green>None</> — every package token has a consumer override.');
        } else {
            foreach ($missing as $token) {
                $this->line("  <fg=yellow>{$token}</>");
            }
        }

        $this->newLine();
        $this->components->info('Unknown to package ('.count($unknown).')');
        if ($unknown === []) {
            $this->line('  <fg=green>None</> — every consumer token is recognised.');
        } else {
            foreach ($unknown as $token) {
                $this->line("  <fg=red>{$token}</>");
            }
        }

        if ($this->option('show-match')) {
            $this->newLine();
            $this->components->info('Match ('.count($match).')');
            foreach ($match as $token) {
                $this->line("  <fg=gray>{$token}</>");
            }
        } else {
            $this->newLine();
            $this->components->twoColumnDetail('Match', '<fg=green>'.count($match).'</> (use --show-match to list)');
        }

        return $missing === [] && $unknown === [] ? self::SUCCESS : self::INVALID;
    }

    /**
     * Extract every distinct `--martis-*` custom property declared
     * within a CSS source. We only count *declarations* (`--foo: …`),
     * not references (`var(--foo)`), so a consumer theme that simply
     * uses tokens without redefining them shows them as "missing".
     *
     * @return list<string>
     */
    private function extractTokens(string $css): array
    {
        $matches = [];
        preg_match_all('/(--martis-[a-z0-9-]+)\s*:/i', $css, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
