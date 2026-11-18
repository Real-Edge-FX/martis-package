<?php

use Martis\Discovery\ToolDiscovery;

// ---------------------------------------------------------------------------
// Helpers (mirror ResourceDiscoveryTest)
// ---------------------------------------------------------------------------

function createTempToolDir(array $files): string
{
    $dir = sys_get_temp_dir().'/martis_tool_discovery_'.uniqid();
    mkdir($dir, 0755, true);

    foreach ($files as $filename => $content) {
        $filePath = $dir.'/'.$filename;
        $subDir = dirname($filePath);

        if (! is_dir($subDir)) {
            mkdir($subDir, 0755, true);
        }

        file_put_contents($filePath, $content);
    }

    return $dir;
}

function removeTempToolDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($dir);
}

// ---------------------------------------------------------------------------
// Discovery tests — same shape as ResourceDiscoveryTest
// ---------------------------------------------------------------------------

it('returns empty array when directory does not exist', function () {
    $discovery = new ToolDiscovery('/nonexistent/path/that/does/not/exist');
    expect($discovery->discover())->toBe([]);
});

it('returns empty array when directory is empty', function () {
    $dir = sys_get_temp_dir().'/martis_tool_empty_'.uniqid();
    mkdir($dir);

    try {
        $discovery = new ToolDiscovery($dir);
        expect($discovery->discover())->toBe([]);
    } finally {
        rmdir($dir);
    }
});

it('discovers concrete Tool subclasses', function () {
    $namespace = 'TestNs\\ToolDiscovery\\N'.uniqid();

    $dir = createTempToolDir([
        'StatusTool.php' => "<?php
namespace {$namespace};
use Martis\\Tools\\Tool;
class StatusTool extends Tool {
    public function __construct() {
        parent::__construct(name: 'Status', uriKey: 'status');
    }
}
",
    ]);

    try {
        $discovery = new ToolDiscovery($dir, $namespace);
        $found = $discovery->discover();

        expect($found)->toHaveCount(1);
        expect($found[0])->toBe("{$namespace}\\StatusTool");
    } finally {
        removeTempToolDir($dir);
    }
});

it('skips abstract Tool subclasses', function () {
    $namespace = 'TestNs\\AbstractTool\\N'.uniqid();

    $dir = createTempToolDir([
        'AbstractBaseTool.php' => "<?php
namespace {$namespace};
use Martis\\Tools\\Tool;
abstract class AbstractBaseTool extends Tool {}
",
    ]);

    try {
        $discovery = new ToolDiscovery($dir, $namespace);
        expect($discovery->discover())->toBe([]);
    } finally {
        removeTempToolDir($dir);
    }
});

it('skips classes that do not extend Tool', function () {
    $namespace = 'TestNs\\NonTool\\N'.uniqid();

    $dir = createTempToolDir([
        'NotATool.php' => "<?php
namespace {$namespace};
class NotATool {
    public function hello(): string { return 'world'; }
}
",
    ]);

    try {
        $discovery = new ToolDiscovery($dir, $namespace);
        expect($discovery->discover())->toBe([]);
    } finally {
        removeTempToolDir($dir);
    }
});

it('discovers multiple Tools in the same directory', function () {
    $namespace = 'TestNs\\MultiTool\\N'.uniqid();

    $toolPhp = fn (string $class, string $key) => "<?php
namespace {$namespace};
use Martis\\Tools\\Tool;
class {$class} extends Tool {
    public function __construct() {
        parent::__construct(name: '{$class}', uriKey: '{$key}');
    }
}
";

    $dir = createTempToolDir([
        'AlphaTool.php' => $toolPhp('AlphaTool', 'alpha'),
        'BetaTool.php' => $toolPhp('BetaTool', 'beta'),
    ]);

    try {
        $discovery = new ToolDiscovery($dir, $namespace);
        $found = $discovery->discover();

        expect($found)->toHaveCount(2);
    } finally {
        removeTempToolDir($dir);
    }
});

it('discovers Tools in subdirectories', function () {
    $namespace = 'TestNs\\SubTool\\N'.uniqid();

    $dir = createTempToolDir([
        'Ops/HealthcheckTool.php' => "<?php
namespace {$namespace}\\Ops;
use Martis\\Tools\\Tool;
class HealthcheckTool extends Tool {
    public function __construct() {
        parent::__construct(name: 'Healthcheck', uriKey: 'healthcheck');
    }
}
",
    ]);

    try {
        $discovery = new ToolDiscovery($dir, $namespace);
        $found = $discovery->discover();

        expect($found)->toHaveCount(1);
        expect($found[0])->toBe("{$namespace}\\Ops\\HealthcheckTool");
    } finally {
        removeTempToolDir($dir);
    }
});

it('returns a list (sequential array)', function () {
    $namespace = 'TestNs\\ToolList\\N'.uniqid();

    $dir = createTempToolDir([
        'OnlyTool.php' => "<?php
namespace {$namespace};
use Martis\\Tools\\Tool;
class OnlyTool extends Tool {
    public function __construct() {
        parent::__construct(name: 'Only', uriKey: 'only');
    }
}
",
    ]);

    try {
        $discovery = new ToolDiscovery($dir, $namespace);
        $found = $discovery->discover();

        expect(array_is_list($found))->toBeTrue();
    } finally {
        removeTempToolDir($dir);
    }
});

// ---------------------------------------------------------------------------
// Files that are not classes must never be executed by discovery
//
// Discovery walks every `*.php` under the root. A routes file or a helper file
// sitting there used to be `require_once`d (and, under a PSR-4 autoloader,
// pulled in by `class_exists()` too), running its top-level code outside any
// route group, middleware or gate.
// ---------------------------------------------------------------------------

/**
 * Register a PSR-4 autoloader for the temp dir, the way Composer maps
 * `App\Martis\...` onto `app/Martis/...`. Returns the callable to unregister.
 */
function toolDiscoveryRegisterPsr4(string $namespace, string $dir): callable
{
    $loader = function (string $class) use ($namespace, $dir): void {
        $prefix = $namespace.'\\';

        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $path = $dir.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

        if (is_file($path)) {
            require_once $path;
        }
    };

    spl_autoload_register($loader);

    return $loader;
}

function toolDiscoverySideEffect(string $marker): string
{
    return "<?php file_put_contents('{$marker}', 'executed;', FILE_APPEND);\n";
}

it('does not execute a non-class file under routes/ or at the root, with or without an autoloader', function (bool $withAutoloader) {
    $namespace = 'TestNs\\ToolNonClass\\N'.uniqid();
    $marker = sys_get_temp_dir().'/martis_side_effect_'.uniqid();

    $dir = createTempToolDir([
        'StatusTool.php' => "<?php
namespace {$namespace};
use Martis\\Tools\\Tool;
class StatusTool extends Tool {
    public function __construct() {
        parent::__construct(name: 'StatusTool', uriKey: 'status');
    }
}
",
        // `routes\web` is a legal class name: an autoloader can resolve it.
        'routes/web.php' => toolDiscoverySideEffect($marker),
        'helpers.php' => toolDiscoverySideEffect($marker),
    ]);

    $loader = $withAutoloader ? toolDiscoveryRegisterPsr4($namespace, $dir) : null;

    try {
        $found = (new ToolDiscovery($dir, $namespace))->discover();

        expect(file_exists($marker))->toBeFalse();
        expect($found)->toBe(["{$namespace}\\StatusTool"]);
    } finally {
        if ($loader !== null) {
            spl_autoload_unregister($loader);
        }
        if (file_exists($marker)) {
            unlink($marker);
        }
        removeTempToolDir($dir);
    }
})->with([
    'without an autoloader' => [false],
    'with a PSR-4 autoloader' => [true],
]);

it('does not treat a file as a class when it declares a different class name', function () {
    $namespace = 'TestNs\\ToolNonClass\\Mismatch\\N'.uniqid();
    $marker = sys_get_temp_dir().'/martis_side_effect_'.uniqid();

    $dir = createTempToolDir([
        // The file name says StatusTool, the body declares OtherTool.
        'StatusTool.php' => ("<?php
namespace {$namespace};
use Martis\\Tools\\Tool;
class OtherTool extends Tool {
    public function __construct() {
        parent::__construct(name: 'OtherTool', uriKey: 'other');
    }
}
")."\nfile_put_contents('{$marker}', 'executed;', FILE_APPEND);\n",
        // The word `class` and the name appear, but only in a comment and `::class`.
        'OtherTool.php' => toolDiscoverySideEffect($marker)."// class OtherTool\n\$x = OtherTool::class;\n",
    ]);

    try {
        $found = (new ToolDiscovery($dir, $namespace))->discover();

        expect(file_exists($marker))->toBeFalse();
        expect($found)->toBe([]);
    } finally {
        if (file_exists($marker)) {
            unlink($marker);
        }
        removeTempToolDir($dir);
    }
});

it('does not execute a file whose declaration is in another namespace or inside a function', function () {
    $namespace = 'TestNs\\ToolNonClass\\Scope\\N'.uniqid();
    $marker = sys_get_temp_dir().'/martis_side_effect_'.uniqid();

    $dir = createTempToolDir([
        'StatusTool.php' => "<?php
namespace {$namespace};
use Martis\\Tools\\Tool;
class StatusTool extends Tool {
    public function __construct() {
        parent::__construct(name: 'StatusTool', uriKey: 'status');
    }
}
",
        // The short name matches, the namespace does not: the derived
        // class is `{$namespace}\\routes\\other`, not `Elsewhere\\other`.
        'routes/other.php' => '<?php
namespace Elsewhere;
class other {}
'.("file_put_contents('{$marker}', 'executed;', FILE_APPEND);\n"),
        // Declared, but only when that code runs — this file is not meant
        // to be executed at all.
        'cond.php' => "<?php
namespace {$namespace};
function build(): void { if (false) { class cond {} } }
".("file_put_contents('{$marker}', 'executed;', FILE_APPEND);\n"),
    ]);

    try {
        $found = (new ToolDiscovery($dir, $namespace))->discover();

        expect(file_exists($marker))->toBeFalse();
        expect($found)->toBe(["{$namespace}\\StatusTool"]);
    } finally {
        if (file_exists($marker)) {
            unlink($marker);
        }
        removeTempToolDir($dir);
    }
});
