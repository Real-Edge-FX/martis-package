<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\Artisan;
use Martis\Console\AgentsCommand;
use Martis\Mcp\McpConfig;
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

    config()->set('app.url', 'http://demo.test');
    config()->set('martis.path', 'martis');
    config()->set('martis.mcp.transport', 'stdio');
    config()->set('martis.mcp.url', null);
    config()->set('martis.mcp.path', null);
    config()->set('martis.mcp.enabled', true);
});

afterEach(function () {
    foreach (McpConfig::REMOVED_VARIABLES as $name) {
        putenv($name);
    }
    app()->setBasePath($this->originalBase);
    if (is_dir($this->base)) {
        rmtree($this->base);
    }
});

function runAgents(array $opts = []): int
{
    $command = app(AgentsCommand::class);
    $command->setLaravel(app());

    $output = new OutputStyle(new ArrayInput([]), new BufferedOutput);
    $command->setOutput($output);
    $componentsRef = new ReflectionProperty(Command::class, 'components');
    $componentsRef->setValue($command, app()->make(Factory::class, ['output' => $output]));

    $defaults = [
        '--agent' => ['claude'],
        '--with-mcp' => true,
        '--force' => true,
        '--no-interaction' => true,
    ];

    return Artisan::call('martis:agents', array_merge($defaults, $opts));
}

function martisEntry(string $base): array
{
    return json_decode((string) file_get_contents($base.'/.mcp.json'), true)['mcpServers']['martis'];
}

it('writes the mcp:start stdio entry by default', function () {
    config()->set('martis.mcp.transport', null);

    expect(runAgents())->toBe(0);

    expect(martisEntry($this->base))->toBe([
        'command' => 'php',
        'args' => ['artisan', 'mcp:start', 'martis-docs'],
        'cwd' => $this->base,
    ]);
});

it('writes the http entry from APP_URL and the Martis path', function () {
    config()->set('martis.mcp.transport', 'http');

    runAgents();

    expect(martisEntry($this->base))->toBe(['type' => 'http', 'url' => 'http://demo.test/martis/mcp']);
});

it('writes MARTIS_MCP_URL verbatim when it is set', function () {
    config()->set('martis.mcp.transport', 'http');
    config()->set('martis.mcp.url', 'http://localhost:8000/martis/mcp');

    runAgents();

    expect(martisEntry($this->base))->toBe(['type' => 'http', 'url' => 'http://localhost:8000/martis/mcp']);
});

it('writes the env block with stdio and the three commented keys', function () {
    runAgents();

    $env = (string) file_get_contents($this->base.'/.env');
    expect($env)->toContain("MARTIS_MCP_ENABLED=true\n")
        ->and($env)->toContain("MARTIS_MCP_TRANSPORT=stdio\n")
        ->and($env)->toContain('# MARTIS_MCP_PATH=')
        ->and($env)->toContain('# MARTIS_MCP_URL=')
        ->and($env)->toContain('# MARTIS_MCP_HTTP_TOKEN=');

    foreach (McpConfig::REMOVED_VARIABLES as $removed) {
        expect($env)->not->toContain($removed);
    }
});

it('keeps an existing MARTIS_MCP_TRANSPORT line', function () {
    file_put_contents($this->base.'/.env', "APP_NAME=Demo\nMARTIS_MCP_TRANSPORT=http\n");

    runAgents();

    $env = (string) file_get_contents($this->base.'/.env');
    expect($env)->toContain("MARTIS_MCP_TRANSPORT=http\n")
        ->and($env)->not->toContain('MARTIS_MCP_TRANSPORT=stdio');
});

it('refuses to wire the MCP while a removed variable is set, writing nothing', function (string $where) {
    if ($where === 'environment') {
        putenv('MARTIS_MCP_PORT=8091');
    } else {
        file_put_contents($this->base.'/.env', "APP_NAME=Demo\nMARTIS_MCP_PORT=8091\n");
    }

    expect(runAgents())->toBe(1);

    expect(file_exists($this->base.'/.mcp.json'))->toBeFalse()
        ->and(file_exists($this->base.'/CLAUDE.md'))->toBeFalse()
        ->and(Artisan::output())->toContain('MARTIS_MCP_PORT');
})->with(['environment', '.env']);

it('ignores a commented removed variable', function () {
    file_put_contents($this->base.'/.env', "APP_NAME=Demo\n# MARTIS_MCP_PORT=8091\n");

    expect(runAgents())->toBe(0);
});

it('does not check the removed variables when the MCP is not wired', function () {
    putenv('MARTIS_MCP_PORT=8091');

    expect(runAgents(['--with-mcp' => false, '--without-mcp' => true]))->toBe(0);
});

it('refuses an unknown transport, naming the variable, without a stack trace', function () {
    config()->set('martis.mcp.transport', 'sse');

    expect(runAgents())->toBe(1);

    expect(file_exists($this->base.'/.mcp.json'))->toBeFalse()
        ->and(file_exists($this->base.'/CLAUDE.md'))->toBeFalse()
        ->and(Artisan::output())->toContain('MARTIS_MCP_TRANSPORT');
});

it('refuses to wire the MCP while the published config has a legacy mcp key, writing nothing', function () {
    config()->set('martis.mcp.port', 8091);

    expect(runAgents())->toBe(1);

    $output = Artisan::output();
    expect(file_exists($this->base.'/.mcp.json'))->toBeFalse()
        ->and(file_exists($this->base.'/CLAUDE.md'))->toBeFalse()
        ->and((string) file_get_contents($this->base.'/.env'))->toBe("APP_NAME=Demo\n")
        ->and($output)->toContain('config/martis.php')
        ->and($output)->toContain('martis.mcp.port');
});

it('does not check the legacy mcp keys when the MCP is not wired', function () {
    config()->set('martis.mcp.port', 8091);

    expect(runAgents(['--with-mcp' => false, '--without-mcp' => true]))->toBe(0);
});
