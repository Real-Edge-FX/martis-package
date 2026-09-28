<?php

declare(strict_types=1);

namespace Martis\Console\Concerns;

use Composer\Autoload\ClassLoader;

/**
 * Use, in the same PHP process, a package that a `composer require`
 * subprocess has just installed.
 *
 * Two things in this process predate the install:
 *
 *  - The Composer ClassLoader booted with the app. It holds the old autoload
 *    maps, and it caches every class it failed to find: a class checked
 *    before the install stays missing even after its maps are updated.
 *    `refreshComposerAutoloader()` registers a second loader built from the
 *    maps the subprocess rewrote, and requires the new `files` entries.
 *  - The application. It never registered the new package's service
 *    provider, so the provider never booted and `vendor:publish --provider`
 *    has no paths to copy. `loadServiceProvider()` registers it.
 */
trait LoadsPackagesInstalledThisRun
{
    private static ?ClassLoader $packagesInstalledThisRunLoader = null;

    protected function composerVendorPath(): string
    {
        return base_path('vendor');
    }

    /**
     * Make the classes and `files` entries of packages Composer installed
     * after boot loadable. A no-op when the vendor directory has no
     * Composer autoload maps.
     */
    protected function refreshComposerAutoloader(): void
    {
        $composer = $this->composerVendorPath().'/composer';
        if (! is_file($composer.'/autoload_psr4.php')) {
            return;
        }

        $loader = new ClassLoader;

        foreach ($this->composerAutoloadMap($composer.'/autoload_namespaces.php') as $prefix => $paths) {
            $loader->set($prefix, $paths);
        }

        foreach ($this->composerAutoloadMap($composer.'/autoload_psr4.php') as $prefix => $paths) {
            $loader->setPsr4($prefix, $paths);
        }

        /** @var array<class-string, string> $classMap */
        $classMap = $this->composerAutoloadMap($composer.'/autoload_classmap.php');
        $loader->addClassMap($classMap);

        // Appended: the booted loader keeps answering for every class it
        // knows, this one answers only for what it cannot find.
        self::$packagesInstalledThisRunLoader?->unregister();
        $loader->register();
        self::$packagesInstalledThisRunLoader = $loader;

        // Composer's own bookkeeping of the files it already required.
        foreach ($this->composerAutoloadMap($composer.'/autoload_files.php') as $identifier => $file) {
            if (empty($GLOBALS['__composer_autoload_files'][$identifier])) {
                $GLOBALS['__composer_autoload_files'][$identifier] = true;

                require $file;
            }
        }
    }

    /**
     * Register a service provider the application did not boot (a package
     * installed during this run), so its publishable paths, config and
     * commands exist. Registering one already registered is a no-op.
     *
     * @return bool whether the provider class could be loaded
     */
    protected function loadServiceProvider(string $provider): bool
    {
        if (! class_exists($provider)) {
            return false;
        }

        $this->laravel->register($provider);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function composerAutoloadMap(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $map = require $path;

        return is_array($map) ? $map : [];
    }
}
