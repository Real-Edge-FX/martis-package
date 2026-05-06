<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('responds to a tools/list JSON-RPC request', function () {
    $packageRoot = realpath(__DIR__.'/../../..');
    expect($packageRoot)->toBeString();

    $script = <<<'PHP'
<?php
chdir($_SERVER['argv'][1]);
require_once 'vendor/autoload.php';

use Martis\Mcp\DocLookup;
use Martis\Mcp\Tools;

$tools = new Tools(DocLookup::package());
echo json_encode([
    'tools' => array_map(static fn (string $name) => $name, [
        'martis_doc_list',
        'martis_doc_read',
        'martis_doc_search',
    ]),
    'list_smoke' => $tools->listDocs(),
]);
PHP;

    $tmp = tempnam(sys_get_temp_dir(), 'mcp_smoke_').'.php';
    file_put_contents($tmp, $script);
    try {
        $process = new Process(['php', $tmp, $packageRoot]);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        $payload = json_decode($process->getOutput(), true);
        expect($payload)->toBeArray()
            ->and($payload['tools'])->toBe(['martis_doc_list', 'martis_doc_read', 'martis_doc_search'])
            ->and($payload['list_smoke']['enabled'])->toBeTrue()
            ->and($payload['list_smoke']['pages'])->not->toBeEmpty();
    } finally {
        @unlink($tmp);
    }
});

it('honours MARTIS_MCP_ENABLED=false at runtime', function () {
    $packageRoot = realpath(__DIR__.'/../../..');

    $script = <<<'PHP'
<?php
chdir($_SERVER['argv'][1]);
require_once 'vendor/autoload.php';
putenv('MARTIS_MCP_ENABLED=false');

use Martis\Mcp\DocLookup;
use Martis\Mcp\Tools;

$tools = new Tools(DocLookup::package());
echo json_encode($tools->listDocs());
PHP;

    $tmp = tempnam(sys_get_temp_dir(), 'mcp_disabled_').'.php';
    file_put_contents($tmp, $script);
    try {
        $process = new Process(['php', $tmp, $packageRoot]);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $payload = json_decode($process->getOutput(), true);
        expect($payload['enabled'])->toBeFalse();
    } finally {
        @unlink($tmp);
    }
});
