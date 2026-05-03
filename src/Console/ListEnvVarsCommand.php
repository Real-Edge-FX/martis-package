<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Lists every `env('MARTIS_*', default)` reference inside the
 * package's published config (`config/martis.php`) and emits a
 * deterministic table — markdown by default, JSON on request.
 *
 * Built so the configuration doc can stay in sync with the live env
 * surface without hand-writing every row. The Martis-side workflow
 * is: edit the config, run this command, copy the output into
 * `docs/configuration.md`. The drift checker (`sync-docs.sh`) plus a
 * regular run during release cuts catches stale rows.
 *
 * Usage:
 *
 *   php artisan martis:list-env-vars                 # markdown table
 *   php artisan martis:list-env-vars --json          # JSON array
 *   php artisan martis:list-env-vars --config=path   # explicit config path
 *
 * The parser is intentionally regex-based — pulling values via the
 * Laravel container would force every default to evaluate (which
 * triggers env access at parse time and caches whatever the current
 * environment happens to expose). Static parsing is the only way to
 * get the LITERAL default the config file declares.
 */
class ListEnvVarsCommand extends Command
{
    protected $signature = 'martis:list-env-vars
                            {--json : Emit a JSON array instead of a markdown table}
                            {--config= : Path to the config file (defaults to package config)}';

    protected $description = 'List every MARTIS_* env var the published config reads, with its default and config key.';

    public function handle(Filesystem $files): int
    {
        $configPath = $this->resolveConfigPath();
        if (! $files->exists($configPath)) {
            $this->components->error("Config file not found: {$configPath}");

            return self::FAILURE;
        }

        $entries = $this->parseEnvCalls((string) $files->get($configPath));

        if ((bool) $this->option('json')) {
            $this->line(json_encode(array_values($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderMarkdown($entries);

        return self::SUCCESS;
    }

    protected function resolveConfigPath(): string
    {
        $opt = $this->option('config');
        if (is_string($opt) && $opt !== '') {
            return $opt;
        }

        // Walk up from this file (src/Console/...) to the package root,
        // then into config/martis.php.
        $packageRoot = dirname(__DIR__, 2);

        return $packageRoot.'/config/martis.php';
    }

    /**
     * @return array<string, array{name: string, default: string}>
     */
    public function parseEnvCalls(string $source): array
    {
        $entries = [];
        $length = strlen($source);
        $offset = 0;

        // Walk the source looking for `env(` followed by a quoted
        // MARTIS_* name. For each hit, scan forward with a manual
        // paren / quote tracker to capture the balanced default
        // (handles arbitrarily-deep nesting like
        // `in_array(env('APP_ENV', 'production'), […], true)`).
        // Then recurse into the captured default to harvest any
        // nested `env('MARTIS_…')` calls.
        while (($pos = strpos($source, 'env(', $offset)) !== false) {
            $cursor = $pos + 4; // past "env("
            // skip whitespace
            while ($cursor < $length && ctype_space($source[$cursor])) {
                $cursor++;
            }

            if ($cursor >= $length || ($source[$cursor] !== "'" && $source[$cursor] !== '"')) {
                $offset = $pos + 4;

                continue;
            }

            $quote = $source[$cursor];
            $nameStart = ++$cursor;
            while ($cursor < $length && $source[$cursor] !== $quote) {
                $cursor++;
            }
            if ($cursor >= $length) {
                break;
            }
            $name = substr($source, $nameStart, $cursor - $nameStart);
            $cursor++; // past closing quote

            if (! str_starts_with($name, 'MARTIS_')) {
                $offset = $cursor;

                continue;
            }

            // Skip whitespace + optional comma
            while ($cursor < $length && ctype_space($source[$cursor])) {
                $cursor++;
            }

            $default = '';
            if ($cursor < $length && $source[$cursor] === ',') {
                $cursor++;
                while ($cursor < $length && ctype_space($source[$cursor])) {
                    $cursor++;
                }
                // Capture balanced expression up to the matching `)`
                // that closes our outer `env(`.
                [$default, $cursor] = $this->captureBalancedExpression($source, $cursor);
            }

            // Skip whitespace + closing `)` of outer env(
            while ($cursor < $length && ctype_space($source[$cursor])) {
                $cursor++;
            }
            if ($cursor < $length && $source[$cursor] === ')') {
                $cursor++;
            }

            $defaultDisplay = trim($default) === '' ? '(no default)' : trim($default);
            $defaultDisplay = preg_replace('/\s+/', ' ', $defaultDisplay) ?? $defaultDisplay;

            if (! isset($entries[$name])) {
                $entries[$name] = ['name' => $name, 'default' => $defaultDisplay];
            }

            // Recurse into the default to pick up nested env() calls.
            if (str_contains($default, 'env(')) {
                $nested = $this->parseEnvCalls($default);
                foreach ($nested as $nName => $nEntry) {
                    if (! isset($entries[$nName])) {
                        $entries[$nName] = $nEntry;
                    }
                }
            }

            $offset = $cursor;
        }

        ksort($entries);

        return $entries;
    }

    /**
     * Walk `$source` from `$start` capturing characters until the
     * paren depth returns to zero — i.e. until the next `)` that is
     * not balanced by a `(`. Tracks single/double quotes so a `)`
     * inside a string literal does not close the expression.
     *
     * @return array{0: string, 1: int}  `[capturedString, nextCursor]`
     */
    protected function captureBalancedExpression(string $source, int $start): array
    {
        $length = strlen($source);
        $depth = 0;
        $i = $start;
        $inSingle = false;
        $inDouble = false;
        $escape = false;

        while ($i < $length) {
            $ch = $source[$i];

            if ($escape) {
                $escape = false;
                $i++;

                continue;
            }

            if ($inSingle) {
                if ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === "'") {
                    $inSingle = false;
                }
                $i++;

                continue;
            }
            if ($inDouble) {
                if ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inDouble = false;
                }
                $i++;

                continue;
            }

            if ($ch === "'") {
                $inSingle = true;
                $i++;

                continue;
            }
            if ($ch === '"') {
                $inDouble = true;
                $i++;

                continue;
            }
            if ($ch === '(' || $ch === '[') {
                $depth++;
                $i++;

                continue;
            }
            if ($ch === ')' || $ch === ']') {
                if ($depth === 0) {
                    // This `)` closes the outer env( — stop here.
                    return [substr($source, $start, $i - $start), $i];
                }
                $depth--;
                $i++;

                continue;
            }

            $i++;
        }

        return [substr($source, $start, $i - $start), $i];
    }

    /**
     * @param  array<string, array{name: string, default: string}>  $entries
     */
    protected function renderMarkdown(array $entries): void
    {
        $this->line('| Variable | Default |');
        $this->line('|----------|---------|');
        foreach ($entries as $entry) {
            $name = $entry['name'];
            $default = $this->escapePipes($entry['default']);
            $this->line(sprintf('| `%s` | `%s` |', $name, $default));
        }
        $this->newLine();
        $this->line(sprintf('Total: **%d** env vars.', count($entries)));
    }

    /**
     * Pipes inside a column value would close the cell. Escape them.
     */
    protected function escapePipes(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }
}
