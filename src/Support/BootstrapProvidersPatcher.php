<?php

declare(strict_types=1);

namespace Martis\Support;

/**
 * Adds a service provider to the array `bootstrap/providers.php` returns,
 * in the file's own style.
 *
 * The file belongs to the host app. The new entry goes first in the array,
 * indented by four spaces, and the entry after it keeps its own indentation.
 * A file that imports its providers with `use` (the Laravel 13 skeleton)
 * gets an import and a short `::class` entry, which Pint's `laravel` preset
 * expects. Existing lines are never moved or removed.
 */
final class BootstrapProvidersPatcher
{
    /**
     * @param  string  $contents  The current contents of `bootstrap/providers.php`.
     * @param  string  $providerClass  Fully qualified class name, without `::class`.
     * @return string|null The new contents, or null when the file does not
     *                     return an array literal, or when the entry or the
     *                     import would not land in code (a block comment, a
     *                     closure returning its own array).
     */
    public function add(string $contents, string $providerClass): ?string
    {
        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $importStyle = preg_match('/^use\s+[^;\r\n]+;\h*\r?$/m', $contents) === 1;
        $entry = ($importStyle ? class_basename($providerClass) : $providerClass).'::class';

        $patched = preg_replace_callback(
            '/^(\h*)return\s*\[\h*\R?/m',
            static fn (array $match): string => $match[1].'return ['.$eol.'    '.$entry.','.$eol,
            $contents,
            1,
            $count,
        );

        if ($patched === null || $count === 0) {
            return null;
        }

        if ($importStyle) {
            $patched = $this->addImport($patched, $providerClass, $eol);
        }

        // The first line-start `return [` or `use` block may sit in a block
        // comment or a closure. Laravel drops a provider class it cannot
        // load without a word, so a misplaced entry would register nothing
        // behind a success message: check where it landed.
        if ($patched === null || ! $this->landedInCode($patched, $providerClass, $importStyle)) {
            return null;
        }

        return $patched;
    }

    /**
     * Whether the entry is the first element of the array the file itself
     * returns (the first `return [` outside any braces) and, in import style,
     * the import is a top-level `use` statement, both as code rather than
     * inside a comment.
     */
    private function landedInCode(string $contents, string $providerClass, bool $importStyle): bool
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($contents),
            static fn (\PhpToken $token): bool => ! $token->isIgnorable(),
        ));
        $entry = $importStyle ? class_basename($providerClass) : $providerClass;
        $depth = 0;
        $entryFound = false;
        $importFound = ! $importStyle;

        foreach ($tokens as $index => $token) {
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;

                continue;
            }

            if ($token->text === '}') {
                $depth--;

                continue;
            }

            if ($depth !== 0) {
                continue;
            }

            if (! $importFound && $token->is(T_USE)
                && ($tokens[$index + 1] ?? null)?->text === $providerClass
                && ($tokens[$index + 2] ?? null)?->text === ';') {
                $importFound = true;
            }

            if (! $entryFound && $token->is(T_RETURN)) {
                if (($tokens[$index + 1] ?? null)?->text !== '[') {
                    return false;
                }

                $entryFound = ($tokens[$index + 2] ?? null)?->text === $entry
                    && ($tokens[$index + 3] ?? null)?->is(T_DOUBLE_COLON) === true
                    && strtolower((string) ($tokens[$index + 4] ?? null)?->text) === 'class';

                if (! $entryFound) {
                    return false;
                }
            }
        }

        return $entryFound && $importFound;
    }

    /**
     * Put `use $providerClass;` into the first block of imports, before the
     * first import that sorts after it, or after the block's last line.
     *
     * @param  non-empty-string  $eol  Either "\n" or "\r\n".
     */
    private function addImport(string $contents, string $providerClass, string $eol): ?string
    {
        $lines = explode($eol, $contents);
        $firstAfter = null;
        $lastImport = null;

        foreach ($lines as $index => $line) {
            if (preg_match('/^use\s+([^;]+);\h*$/', $line, $match) !== 1) {
                if ($lastImport !== null) {
                    break;
                }

                continue;
            }

            $lastImport = $index;

            if ($firstAfter === null && strcasecmp(trim($match[1]), $providerClass) > 0) {
                $firstAfter = $index;
            }
        }

        if ($lastImport === null) {
            return null;
        }

        array_splice($lines, $firstAfter ?? $lastImport + 1, 0, ['use '.$providerClass.';']);

        return implode($eol, $lines);
    }
}
