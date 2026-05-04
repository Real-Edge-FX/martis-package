<?php

namespace Martis\Discovery;

use Martis\Tools\Tool;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Discovers Martis Tool subclasses in a configured directory.
 *
 * Mirrors {@see ResourceDiscovery}: scans a directory tree, derives
 * each `*.php` file's fully-qualified class name from path + base
 * namespace, then keeps the concrete subclasses of {@see Tool}.
 *
 * Convention: tools live in `app/Martis/Tools/` under the `App\Martis\Tools`
 * namespace. Auto-discovery removes the need for the consumer to call
 * `Martis::tools([...])` for every Tool — the same ergonomics Resources
 * have had since v0.7.
 *
 * Manually registered Tools (`Martis::tools([...])`) are deduplicated
 * by class-string at registration time, so combining manual and
 * automatic registration is safe.
 *
 * Usage (typically in MartisServiceProvider::boot):
 *
 *   $discovery = new ToolDiscovery(app_path('Martis/Tools'), 'App\Martis\Tools');
 *   Martis::tools($discovery->discover());
 */
class ToolDiscovery
{
    private string $toolsPath;

    private string $namespace;

    /**
     * @param  string  $toolsPath  Absolute directory path (e.g. app_path('Martis/Tools'))
     * @param  string  $namespace  Corresponding PHP namespace (e.g. 'App\Martis\Tools')
     */
    public function __construct(string $toolsPath, string $namespace = 'App\\Martis\\Tools')
    {
        $realPath = realpath($toolsPath);
        $this->toolsPath = rtrim($realPath !== false ? $realPath : $toolsPath, '/\\');
        $this->namespace = rtrim($namespace, '\\');
    }

    /**
     * Scan the directory and return all concrete Tool subclasses found.
     *
     * @return list<class-string<Tool>>
     */
    public function discover(): array
    {
        if (! is_dir($this->toolsPath)) {
            return [];
        }

        $tools = [];

        $finder = Finder::create()
            ->files()
            ->in($this->toolsPath)
            ->name('*.php')
            ->sortByName();

        foreach ($finder as $file) {
            $class = $this->classFromPath($file->getRealPath());

            if ($class === null) {
                continue;
            }

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

            if (! $reflection->isSubclassOf(Tool::class)) {
                continue;
            }

            // Tool's constructor is non-trivial (subclasses often
            // accept zero args and call parent with literals); we let
            // the caller instantiate. Only the class-string is needed
            // for `Martis::tools([...])`.

            /** @var class-string<Tool> $class */
            $tools[] = $class;
        }

        return $tools;
    }

    /**
     * Derive the fully-qualified class name from an absolute file path.
     *
     * Maps: {toolsPath}/SubDir/MyTool.php → {namespace}\SubDir\MyTool
     *
     * Returns null when the file is outside the configured tools path.
     */
    private function classFromPath(string $filePath): ?string
    {
        $filePath = str_replace('\\', '/', $filePath);
        $toolsPath = str_replace('\\', '/', $this->toolsPath);

        if (! str_starts_with($filePath, $toolsPath)) {
            return null;
        }

        $relativePath = ltrim(substr($filePath, strlen($toolsPath)), '/');

        if (str_ends_with($relativePath, '.php')) {
            $relativePath = substr($relativePath, 0, -4);
        }

        $relativeNamespace = str_replace('/', '\\', $relativePath);

        return $this->namespace.'\\'.$relativeNamespace;
    }
}
