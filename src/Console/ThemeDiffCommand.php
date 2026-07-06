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
 *   - **Missing**  — tokens the package *declares* that the consumer
 *                    theme does not override. Referenced-only tokens
 *                    (used via a `var()` fallback, never declared) are
 *                    NOT expected here — they have a baked-in default.
 *   - **Unknown**  — tokens the consumer defines that the package
 *                    neither declares nor references (typo, a token
 *                    removed in an upgrade, or a project-private token).
 *   - **Match**    — consumer tokens the package knows (declares or
 *                    references). Counted, not listed.
 *
 * The package's "known" set is declarations ∪ `var()` references, so a
 * consumer that declares a token the engine only uses through a `var()`
 * fallback (e.g. `--martis-accent-contrast`) is a Match, not Unknown —
 * letting a fully-declared theme reach `exit 0` as a CI drift gate.
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

        $packageCss = $filesystem->get($packagePath);
        $packageDeclared = $this->extractTokens($packageCss);
        $packageReferenced = $this->extractReferencedTokens($packageCss);
        // A token the engine *uses* via `var(--x, fallback)` but never
        // declares (e.g. `--martis-accent-contrast`, left to its inline
        // fallback) is still part of the package surface — a consumer that
        // declares it is correct, not "unknown". So the known set is
        // declarations ∪ references.
        $packageKnown = array_values(array_unique(array_merge($packageDeclared, $packageReferenced)));
        $referencedOnly = array_values(array_diff($packageReferenced, $packageDeclared));

        $consumerTokens = $this->extractTokens($filesystem->get($consumerPath));

        // Missing = declared tokens the consumer hasn't overridden. Only
        // *declared* tokens are "expected"; referenced-only tokens have a
        // baked-in fallback, so a consumer that omits them isn't drifting.
        $missing = array_values(array_diff($packageDeclared, $consumerTokens));
        // Unknown = consumer tokens the package neither declares nor
        // references (typo, removed token, or a project-private variable).
        $unknown = array_values(array_diff($consumerTokens, $packageKnown));
        $match = array_values(array_intersect($consumerTokens, $packageKnown));

        sort($missing);
        sort($unknown);
        sort($match);

        $this->newLine();
        $this->components->twoColumnDetail('Theme', "<fg=cyan>{$themeName}</>");
        $this->components->twoColumnDetail('Package tokens (declared)', (string) count($packageDeclared));
        if ($referencedOnly !== []) {
            $this->components->twoColumnDetail(
                'Package tokens (referenced-only)',
                '<fg=gray>'.count($referencedOnly).' — used via var() fallback, not declared</>'
            );
        }
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
     * Extract every distinct `--martis-*` custom property *declared*
     * (`--foo: …`) within a CSS source. Used for the consumer theme (what
     * it overrides) and the package's declared surface.
     *
     * @return list<string>
     */
    private function extractTokens(string $css): array
    {
        $matches = [];
        preg_match_all('/(--martis-[a-z0-9-]+)\s*:/i', $css, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Extract every distinct `--martis-*` custom property *referenced*
     * (`var(--foo …)`) within a CSS source. The package legitimately uses
     * some tokens only through a `var()` with an inline fallback and never
     * declares a default; those are still part of its surface, so the diff
     * must treat them as known rather than flagging a consumer that
     * declares them as "unknown".
     *
     * @return list<string>
     */
    private function extractReferencedTokens(string $css): array
    {
        $matches = [];
        preg_match_all('/var\(\s*(--martis-[a-z0-9-]+)/i', $css, $matches);

        return array_values(array_unique($matches[1]));
    }
}
