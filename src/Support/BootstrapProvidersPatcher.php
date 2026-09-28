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
     *                     return an array literal.
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

        return $importStyle ? $this->addImport($patched, $providerClass, $eol) : $patched;
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
