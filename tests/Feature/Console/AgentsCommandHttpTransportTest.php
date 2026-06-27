<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\Artisan;
use Martis\Console\AgentsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/martis-agents-http-'.uniqid();
    mkdir($this->base, 0755, true);
    file_put_contents($this->base.'/composer.json', json_encode([
        'name' => 'acme/demo',
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));
    file_put_contents($this->base.'/.env', "APP_NAME=Demo\n");
    file_put_contents($this->base.'/.env.example', "APP_NAME=Demo\n");

    $this->originalBase = base_path();
    app()->setBasePath($this->base);

    // Reset MCP config to defaults each test.
    config()->set('martis.mcp.transport', 'stdio');
    config()->set('martis.mcp.url', null);
    config()->set('martis.mcp.host', '127.0.0.1');
    config()->set('martis.mcp.port', 8091);
    config()->set('martis.mcp.path', '/mcp');
    config()->set('martis.mcp.enabled', true);
});

afterEach(function () {
    app()->setBasePath($this->originalBase);
    if (is_dir($this->base)) {
        rmtree($this->base);
    }
});

function runAgents(array $opts = []): void
{
    $command = app(AgentsCommand::class);
    $command->setLaravel(app());

    $output = new OutputStyle(new ArrayInput([]), new BufferedOutput);
    $command->setOutput($output);
    $componentsRef = new ReflectionProperty(Command::class, 'components');
    $componentsRef->setAccessible(true);
    $componentsRef->setValue($command, app()->make(Factory::class, ['output' => $output]));

    $defaults = [
        '--agent' => ['claude'],
        '--with-mcp' => true,
        '--force' => true,
        '--no-interaction' => true,
    ];

    Artisan::call('martis:agents', array_merge($defaults, $opts));
}

it('writes a URL entry when transport=http and url is set', function () {
    config()->set('martis.mcp.transport', 'http');
    config()->set('martis.mcp.url', 'http://example.test:8091/mcp');

    runAgents();

    $entry = json_decode((string) file_get_contents($this->base.'/.mcp.json'), true);
    expect($entry['mcpServers']['martis'])->toMatchArray([
        'type' => 'http',
        'url' => 'http://example.test:8091/mcp',
    ]);
    expect($entry['mcpServers']['martis'])->not->toHaveKey('command');
});

it('guesses url from host+port+path when url is absent and http transport', function () {
    config()->set('martis.mcp.transport', 'http');
    config()->set('martis.mcp.host', '127.0.0.1');
    config()->set('martis.mcp.port', 9000);
    config()->set('martis.mcp.path', '/mcp');

    runAgents();

    $entry = json_decode((string) file_get_contents($this->base.'/.mcp.json'), true);
    expect($entry['mcpServers']['martis']['url'])->toBe('http://127.0.0.1:9000/mcp');
});

it('substitutes 0.0.0.0 -> localhost in the guessed url', function () {
    config()->set('martis.mcp.transport', 'http');
    config()->set('martis.mcp.host', '0.0.0.0');
    config()->set('martis.mcp.port', 8091);

    runAgents();

    $entry = json_decode((string) file_get_contents($this->base.'/.mcp.json'), true);
    expect($entry['mcpServers']['martis']['url'])->toBe('http://localhost:8091/mcp');
});

it('keeps the stdio spawn entry when transport=stdio (default, regression guard)', function () {
    // transport already stdio in beforeEach.
    runAgents();

    $entry = json_decode((string) file_get_contents($this->base.'/.mcp.json'), true);
    expect($entry['mcpServers']['martis'])->toHaveKey('command');
    expect($entry['mcpServers']['martis'])->not->toHaveKey('url');
});

it('writes the full 7-env block on first run', function () {
    runAgents();

    $env = (string) file_get_contents($this->base.'/.env');
    $keys = [
        'MARTIS_MCP_ENABLED',
        'MARTIS_MCP_TRANSPORT',
        'MARTIS_MCP_HOST',
        'MARTIS_MCP_PORT',
        'MARTIS_MCP_PATH',
        'MARTIS_MCP_URL',
        'MARTIS_MCP_HTTP_TOKEN',
        'MARTIS_MCP_HEALTH_PORT',
    ];
    foreach ($keys as $key) {
        expect(str_contains($env, $key))->toBeTrue("Expected {$key} in the .env block");
    }
});
