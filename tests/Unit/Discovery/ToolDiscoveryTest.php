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
