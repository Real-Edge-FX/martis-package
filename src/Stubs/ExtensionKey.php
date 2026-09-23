<?php

declare(strict_types=1);

namespace Martis\Stubs;

/**
 * The kebab-case name the consumer-extension entry derives from a file
 * name (`pascalToKebab()` in `stubs/extensions/index.ts.stub`), which it
 * registers the file under: `tools/SystemHealth.tsx` becomes
 * `tool:system-health`, and an acronym stays whole (`tools/SEOReport.tsx`
 * becomes `tool:seo-report`).
 *
 * The generators (`martis:tool`, `martis:card`, `martis:field`,
 * `martis:component`) bind the PHP side to that key, and
 * `martis:list-overrides --frontend` derives the same keys. Laravel's
 * `Str::kebab()` cannot stand in: it splits an acronym letter by letter
 * (`s-e-o-report`), a key the entry never registers. Keep both regular
 * expressions in step with the stub's (`extensionEntry.test.tsx` and
 * `ExtensionKeyTest.php` share a table of names).
 */
final class ExtensionKey
{
    public static function kebab(string $name): string
    {
        $words = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $name) ?? $name;
        $acronyms = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $words) ?? $words;

        return strtolower($acronyms);
    }
}
