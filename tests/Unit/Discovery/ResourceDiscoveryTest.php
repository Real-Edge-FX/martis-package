<?php

use Martis\Discovery\ResourceDiscovery;
use Martis\Resource;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a temporary directory with PHP resource files and return its path.
 */
function createTempResourceDir(array $files): string
{
    $dir = sys_get_temp_dir().'/martis_discovery_'.uniqid();
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

/**
 * Recursively remove a temporary directory.
 */
function removeTempDir(string $dir): void
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
// Discovery tests
// ---------------------------------------------------------------------------

it('returns empty array when directory does not exist', function () {
    $discovery = new ResourceDiscovery('/nonexistent/path/that/does/not/exist');
    expect($discovery->discover())->toBe([]);
});

it('returns empty array when directory is empty', function () {
    $dir = sys_get_temp_dir().'/martis_empty_'.uniqid();
    mkdir($dir);

    try {
        $discovery = new ResourceDiscovery($dir);
        expect($discovery->discover())->toBe([]);
    } finally {
        rmdir($dir);
    }
});

it('discovers concrete Resource subclasses', function () {
    $namespace = 'TestNs\\Discovery\\N'.uniqid();

    $dir = createTempResourceDir([
        'UserResource.php' => "<?php
namespace {$namespace};
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Http\\Request;
use Martis\\Resource;
class UserResourceModel extends Model { protected \$table = 'users'; }
class UserResource extends Resource {
    public static function model(): string { return UserResourceModel::class; }
    public function fields(Request \$request): array { return []; }
}
",
    ]);

    try {
        $discovery = new ResourceDiscovery($dir, $namespace);
        $found = $discovery->discover();

        expect($found)->toHaveCount(1);
        expect($found[0])->toBe("{$namespace}\\UserResource");
    } finally {
        removeTempDir($dir);
    }
});

it('skips abstract Resource subclasses', function () {
    $namespace = 'TestNs\\Abstract\\N'.uniqid();

    $dir = createTempResourceDir([
        'AbstractResource.php' => "<?php
namespace {$namespace};
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Http\\Request;
use Martis\\Resource;
abstract class AbstractResource extends Resource {
    public static function model(): string { return Model::class; }
}
",
    ]);

    try {
        $discovery = new ResourceDiscovery($dir, $namespace);
        expect($discovery->discover())->toBe([]);
    } finally {
        removeTempDir($dir);
    }
});

it('skips classes that do not extend Resource', function () {
    $namespace = 'TestNs\\NonResource\\N'.uniqid();

    $dir = createTempResourceDir([
        'NotAResource.php' => "<?php
namespace {$namespace};
class NotAResource {
    public function hello(): string { return 'world'; }
}
",
    ]);

    try {
        $discovery = new ResourceDiscovery($dir, $namespace);
        expect($discovery->discover())->toBe([]);
    } finally {
        removeTempDir($dir);
    }
});

it('discovers multiple resources in the same directory', function () {
    $namespace = 'TestNs\\Multi\\N'.uniqid();

    $resourcePhp = fn (string $class, string $modelClass) => "<?php
namespace {$namespace};
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Http\\Request;
use Martis\\Resource;
class {$modelClass} extends Model { protected \$table = 'users'; }
class {$class} extends Resource {
    public static function model(): string { return {$modelClass}::class; }
    public function fields(Request \$request): array { return []; }
}
";

    $dir = createTempResourceDir([
        'PostResource.php' => $resourcePhp('PostResource', 'PostModel'.uniqid()),
        'TagResource.php' => $resourcePhp('TagResource', 'TagModel'.uniqid()),
    ]);

    try {
        $discovery = new ResourceDiscovery($dir, $namespace);
        $found = $discovery->discover();

        expect($found)->toHaveCount(2);
    } finally {
        removeTempDir($dir);
    }
});

it('discovers resources in subdirectories', function () {
    $namespace = 'TestNs\\Sub\\N'.uniqid();

    $dir = createTempResourceDir([
        'Admin/AdminUserResource.php' => "<?php
namespace {$namespace}\\Admin;
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Http\\Request;
use Martis\\Resource;
class AdminUserModel extends Model { protected \$table = 'users'; }
class AdminUserResource extends Resource {
    public static function model(): string { return AdminUserModel::class; }
    public function fields(Request \$request): array { return []; }
}
",
    ]);

    try {
        $discovery = new ResourceDiscovery($dir, $namespace);
        $found = $discovery->discover();

        expect($found)->toHaveCount(1);
        expect($found[0])->toBe("{$namespace}\\Admin\\AdminUserResource");
    } finally {
        removeTempDir($dir);
    }
});

it('returns a list (sequential array)', function () {
    $namespace = 'TestNs\\List\\N'.uniqid();

    $dir = createTempResourceDir([
        'MyResource.php' => "<?php
namespace {$namespace};
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Http\\Request;
use Martis\\Resource;
class MyModel extends Model { protected \$table = 'users'; }
class MyResource extends Resource {
    public static function model(): string { return MyModel::class; }
    public function fields(Request \$request): array { return []; }
}
",
    ]);

    try {
        $discovery = new ResourceDiscovery($dir, $namespace);
        $found = $discovery->discover();

        expect(array_is_list($found))->toBeTrue();
    } finally {
        removeTempDir($dir);
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
function resourceDiscoveryRegisterPsr4(string $namespace, string $dir): callable
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

function resourceDiscoverySideEffect(string $marker): string
{
    return "<?php file_put_contents('{$marker}', 'executed;', FILE_APPEND);\n";
}

it('does not execute a non-class file under routes/ or at the root, with or without an autoloader', function (bool $withAutoloader) {
    $namespace = 'TestNs\\ResourceNonClass\\N'.uniqid();
    $marker = sys_get_temp_dir().'/martis_side_effect_'.uniqid();

    $dir = createTempResourceDir([
        'StatusResource.php' => "<?php
namespace {$namespace};
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Http\\Request;
use Martis\\Resource;
class StatusResourceModel extends Model { protected \$table = 'users'; }
class StatusResource extends Resource {
    public static function model(): string { return StatusResourceModel::class; }
    public function fields(Request \$request): array { return []; }
}
",
        // `routes\web` is a legal class name: an autoloader can resolve it.
        'routes/web.php' => resourceDiscoverySideEffect($marker),
        'helpers.php' => resourceDiscoverySideEffect($marker),
    ]);

    $loader = $withAutoloader ? resourceDiscoveryRegisterPsr4($namespace, $dir) : null;

    try {
        $found = (new ResourceDiscovery($dir, $namespace))->discover();

        expect(file_exists($marker))->toBeFalse();
        expect($found)->toBe(["{$namespace}\\StatusResource"]);
    } finally {
        if ($loader !== null) {
            spl_autoload_unregister($loader);
        }
        if (file_exists($marker)) {
            unlink($marker);
        }
        removeTempDir($dir);
    }
})->with([
    'without an autoloader' => [false],
    'with a PSR-4 autoloader' => [true],
]);

it('does not treat a file as a class when it declares a different class name', function () {
    $namespace = 'TestNs\\ResourceNonClass\\Mismatch\\N'.uniqid();
    $marker = sys_get_temp_dir().'/martis_side_effect_'.uniqid();

    $dir = createTempResourceDir([
        // The file name says StatusResource, the body declares OtherResource.
        'StatusResource.php' => ("<?php
namespace {$namespace};
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Http\\Request;
use Martis\\Resource;
class OtherResourceModel extends Model { protected \$table = 'users'; }
class OtherResource extends Resource {
    public static function model(): string { return OtherResourceModel::class; }
    public function fields(Request \$request): array { return []; }
}
")."\nfile_put_contents('{$marker}', 'executed;', FILE_APPEND);\n",
        // The word `class` and the name appear, but only in a comment and `::class`.
        'OtherResource.php' => resourceDiscoverySideEffect($marker)."// class OtherResource\n\$x = OtherResource::class;\n",
    ]);

    try {
        $found = (new ResourceDiscovery($dir, $namespace))->discover();

        expect(file_exists($marker))->toBeFalse();
        expect($found)->toBe([]);
    } finally {
        if (file_exists($marker)) {
            unlink($marker);
        }
        removeTempDir($dir);
    }
});
