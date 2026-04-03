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
