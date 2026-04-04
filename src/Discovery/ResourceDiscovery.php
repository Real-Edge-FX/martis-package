<?php

namespace Martis\Discovery;

use Martis\Resource;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Discovers Martis Resource subclasses in a configured directory.
 *
 * By convention, resources live in `app/Martis/` and use the `App\Martis`
 * namespace. The discovery class maps each PHP file to a class name using
 * the provided base path and base namespace, then filters for concrete
 * subclasses of {@see Resource}.
 *
 * Usage (typically in MartisServiceProvider::boot):
 *
 *   $discovery = new ResourceDiscovery(app_path('Martis'), 'App\Martis');
 *   $classes   = $discovery->discover();
 *   app(ResourceRegistry::class)->registerMany($classes);
 */
class ResourceDiscovery
{
    /**
     * Absolute path to the directory containing resource classes.
     */
    private string $resourcesPath;

    /**
     * Base PHP namespace corresponding to the resources directory.
     */
    private string $namespace;

    /**
     * @param  string  $resourcesPath  Absolute directory path (e.g. app_path('Martis'))
     * @param  string  $namespace  Corresponding PHP namespace (e.g. 'App\Martis')
     */
    public function __construct(string $resourcesPath, string $namespace = 'App\\Martis')
    {
        $this->resourcesPath = rtrim($resourcesPath, '/\\');
        $this->namespace = rtrim($namespace, '\\');
    }

    /**
     * Scan the directory and return all concrete Resource subclasses found.
     *
     * @return list<class-string<resource>>
     */
    public function discover(): array
    {
        if (! is_dir($this->resourcesPath)) {
            return [];
        }

        $resources = [];

        $finder = Finder::create()
            ->files()
            ->in($this->resourcesPath)
            ->name('*.php')
            ->sortByName();

        foreach ($finder as $file) {
            $class = $this->classFromPath($file->getRealPath());

            if ($class === null) {
                continue;
            }

            // Require the file only when the class is not yet loaded (e.g. in
            // environments without Composer's classmap autoloader pre-warming).
            if (! class_exists($class)) {
                require_once $file->getRealPath();
            }

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            if (! $reflection->isSubclassOf(Resource::class)) {
                continue;
            }

            /** @var class-string<resource> $class */
            $resources[] = $class;
        }

        return $resources;
    }

    /**
     * Derive the fully-qualified class name from an absolute file path.
     *
     * Maps: {resourcesPath}/SubDir/MyResource.php → {namespace}\SubDir\MyResource
     *
     * Returns null when the file is outside the configured resources path.
     */
    private function classFromPath(string $filePath): ?string
    {
        // Normalise directory separators for cross-platform safety.
        $filePath = str_replace('\\', '/', $filePath);
        $resourcesPath = str_replace('\\', '/', $this->resourcesPath);

        if (! str_starts_with($filePath, $resourcesPath)) {
            return null;
        }

        // Extract the path relative to the base directory.
        $relativePath = ltrim(substr($filePath, strlen($resourcesPath)), '/');

        // Strip the .php extension.
        if (str_ends_with($relativePath, '.php')) {
            $relativePath = substr($relativePath, 0, -4);
        }

        // Convert forward slashes to namespace separators.
        $relativeNamespace = str_replace('/', '\\', $relativePath);

        return $this->namespace.'\\'.$relativeNamespace;
    }
}
