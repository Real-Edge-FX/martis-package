<?php

declare(strict_types=1);

namespace Martis\Discovery;

use Composer\Autoload\ClassLoader;

/**
 * Derives the PHP namespace of a directory from Composer's PSR-4
 * autoload map.
 *
 * Auto-discovery ({@see ResourceDiscovery}, {@see ToolDiscovery}) maps
 * each file to a class name by prepending a base namespace to the path
 * relative to the scanned directory. Hard-coding that base namespace
 * (`App\Martis`) only fits the conventional `app/Martis/` layout: a
 * module directory, a per-panel sub-folder or an application whose
 * root namespace is not `App` all produce class names that never
 * exist. Reading the PSR-4 map instead makes any autoloadable
 * directory work with no configuration.
 *
 * By default the map is read from every registered {@see ClassLoader}
 * (the root `composer.json`, merge plugins and runtime `addPsr4()`
 * registrations such as module packages all end up there). Pass an
 * explicit map to the constructor to pin it (tests, custom loaders).
 *
 * PSR-4 fallback directories (entries without a prefix) are ignored:
 * they map to the global namespace, which no Laravel application uses
 * for its own classes.
 */
class Psr4NamespaceResolver
{
    /**
     * PSR-4 prefix (with trailing backslash) → directories.
     *
     * @var array<string, list<string>>|null
     */
    private ?array $prefixes;

    /**
     * @param  array<string, list<string>>|null  $prefixes  Explicit map; `null` reads the registered Composer class loaders lazily.
     */
    public function __construct(?array $prefixes = null)
    {
        $this->prefixes = $prefixes;
    }

    /**
     * Resolve the namespace that maps to `$directory`.
     *
     * Picks the longest PSR-4 root that contains the directory (so a
     * nested root such as `"App\Martis\": "app/Martis/"` wins over
     * `"App\": "app/"`) and appends the remaining path segments as
     * namespace segments. Returns `null` when no root contains it.
     */
    public function resolve(string $directory): ?string
    {
        $directory = $this->normalise($directory);

        $bestPrefix = null;
        $bestRoot = null;

        foreach ($this->prefixes() as $prefix => $roots) {
            foreach ($roots as $root) {
                $root = $this->normalise($root);

                if ($directory !== $root && ! str_starts_with($directory, $root.'/')) {
                    continue;
                }

                if ($bestRoot === null || strlen($root) > strlen($bestRoot)) {
                    $bestPrefix = $prefix;
                    $bestRoot = $root;
                }
            }
        }

        if ($bestPrefix === null || $bestRoot === null) {
            return null;
        }

        $namespace = trim($bestPrefix, '\\');
        $relative = trim(substr($directory, strlen($bestRoot)), '/');

        if ($relative === '') {
            return $namespace;
        }

        return $namespace.'\\'.str_replace('/', '\\', $relative);
    }

    /**
     * @return array<string, list<string>>
     */
    private function prefixes(): array
    {
        if ($this->prefixes !== null) {
            return $this->prefixes;
        }

        $prefixes = [];

        foreach (spl_autoload_functions() as $autoloader) {
            $loader = is_array($autoloader) ? $autoloader[0] : null;

            if (! $loader instanceof ClassLoader) {
                continue;
            }

            foreach ($loader->getPrefixesPsr4() as $prefix => $directories) {
                $prefixes[$prefix] = array_merge($prefixes[$prefix] ?? [], $directories);
            }
        }

        return $this->prefixes = $prefixes;
    }

    /**
     * Resolve symlinks and unify separators so path comparisons are
     * byte-exact across platforms. Falls back to the raw path when it
     * does not exist (the caller then simply finds no matching root).
     */
    private function normalise(string $path): string
    {
        $real = realpath($path);

        return rtrim(str_replace('\\', '/', $real !== false ? $real : $path), '/');
    }
}
