<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Martis\Console\AgentsCommand;
use Martis\Stubs\StubResolver;

// ===========================================================================
// The primer `martis:agents` writes (v1.38.1).
//
// - It stamped the installed package version into a committed file, which
//   went stale on the next upgrade: it now points at `composer.lock`.
// - Its template could not be customised: `martis:stubs` did not publish it
//   and the command never read a host copy, so `--force` (the documented
//   post-upgrade step) discarded every edit. It now resolves like every
//   generator stub.
// - With the docs MCP wired it repeated what the MCP serves (a 41-row slug
//   table, the "MCP only" rule four times, operator-only transport and
//   runtime-knob subsections) and restated earlier rules in section 9.
// ===========================================================================

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/martis-agents-primer-'.uniqid();
    mkdir($this->base, 0755, true);
    file_put_contents($this->base.'/composer.json', json_encode([
        'name' => 'acme/demo',
        'autoload' => ['psr-4' => ['Acme\\' => 'app/']],
    ]));
    file_put_contents($this->base.'/.env', "APP_NAME=Demo\n");
    file_put_contents($this->base.'/.env.example', "APP_NAME=Demo\n");
    // An installed version the old primer would have stamped.
    mkdir($this->base.'/vendor/composer', 0755, true);
    file_put_contents($this->base.'/vendor/composer/installed.json', json_encode([
        'packages' => [['name' => 'martis/martis', 'version' => 'v9.9.9']],
    ]));
    $this->originalBase = base_path();
    app()->setBasePath($this->base);
});

afterEach(function () {
    if (isset($this->originalBase)) {
        app()->setBasePath($this->originalBase);
    }
    if (isset($this->base) && is_dir($this->base)) {
        rmtree($this->base);
    }
});

/** Run `martis:agents` for Claude Code and return the AGENTS.md it wrote. */
function renderPrimer(bool $withMcp): string
{
    Artisan::call('martis:agents', [
        '--agent' => ['claude'],
        $withMcp ? '--with-mcp' : '--without-mcp' => true,
        '--force' => true,
        '--no-interaction' => true,
    ]);

    return (string) file_get_contents(base_path('AGENTS.md'));
}

it('names no package version and points at composer.lock instead', function (bool $withMcp) {
    $primer = renderPrimer($withMcp);

    expect($primer)->not->toContain('v9.9.9')
        ->and($primer)->not->toContain('package version `')
        ->and($primer)->not->toContain('{{martis_version}}')
        ->and($primer)->toContain('`composer.lock`')
        ->and($primer)->toContain('**acme/demo** (namespace `Acme`)');
})->with(['with the MCP' => [true], 'without the MCP' => [false]]);

it('publishes the primer template with martis:stubs', function () {
    Artisan::call('martis:stubs');

    $published = base_path('stubs/martis/'.AgentsCommand::STUB);
    expect(is_file($published))->toBeTrue()
        ->and(file_get_contents($published))->toBe(file_get_contents(StubResolver::packagePath(AgentsCommand::STUB)));
});

it('renders a published primer template, placeholders and MCP switch included', function () {
    Artisan::call('martis:stubs');
    $published = base_path('stubs/martis/'.AgentsCommand::STUB);
    file_put_contents($published, str_replace(
        '# Working with Martis',
        "# Working with Martis at Acme\n\nAcme rule: every Resource declares a policy.",
        (string) file_get_contents($published),
    ));

    $withMcp = renderPrimer(true);
    expect($withMcp)->toContain('Acme rule: every Resource declares a policy.')
        ->and($withMcp)->toContain('**acme/demo**')
        ->and($withMcp)->toContain('martis_doc_list()')
        ->and($withMcp)->not->toContain('{{MCP_SECTION}}');

    $withoutMcp = renderPrimer(false);
    expect($withoutMcp)->toContain('Acme rule: every Resource declares a policy.')
        ->and($withoutMcp)->not->toContain('martis_doc_list()')
        ->and($withoutMcp)->not->toContain('{{^MCP_SECTION}}');
});

it('renders the package template when the host published none, byte for byte', function () {
    $fromPackage = renderPrimer(true);

    // A published copy identical to the package template renders the same.
    Artisan::call('martis:stubs');
    expect(renderPrimer(true))->toBe($fromPackage);
});

it('gives an MCP-wired project the MCP tools instead of the slug table, and the MCP rule once', function () {
    $primer = renderPrimer(true);

    expect($primer)->toContain('`martis_doc_list()` returns the page index')
        ->and($primer)->not->toContain('| `quick-start` |')
        ->and($primer)->not->toContain('| Slug | Topic |')
        // The rule and its one instruction, stated once.
        ->and(substr_count($primer, 'never from the `docs/*.md` files'))->toBe(1)
        ->and(substr_count($primer, 'never fall back to the files'))->toBe(1)
        ->and(substr_count($primer, 'MARTIS_MCP_ENABLED'))->toBe(1)
        // Operator-only material stays on the agent-guidelines doc page.
        ->and($primer)->not->toContain('### Transport')
        ->and($primer)->not->toContain('Runtime knobs')
        ->and($primer)->not->toContain('MARTIS_MCP_HEALTH_PORT')
        ->and($primer)->not->toContain('## 11.');
});

it('keeps only the anti-patterns stated nowhere else', function () {
    $primer = renderPrimer(true);
    $section9 = (string) preg_replace('/\A.*?## 9\.[^\n]*\n(.*?)\n## 10\..*\z/s', '$1', $primer);

    expect($section9)->toContain('internal task IDs')
        ->and(substr_count($primer, 'internal task IDs'))->toBe(1)
        ->and($section9)->not->toContain('config/martis.php')
        ->and($section9)->not->toContain('authorizedTo')
        ->and($section9)->not->toContain('martis:publish-assets')
        // The rules section 9 used to repeat are still stated, in their sections.
        ->and($primer)->toContain('vendor changes are erased on the next `composer install`')
        ->and($primer)->toContain('Host code (Resources, Dashboards, Tools) lives in `app/Martis/**`');
});

it('still lists every slug with the file it lives in when no MCP is wired', function () {
    $primer = renderPrimer(false);

    expect($primer)->toContain('| Slug | Topic |')
        ->and($primer)->toContain('| `quick-start` |')
        ->and($primer)->toContain('| `v1-roadmap` |')
        ->and($primer)->toContain('vendor/martis/martis/docs/<slug>.md')
        ->and($primer)->not->toContain('martis_doc_search');
});

it('keeps the MCP-wired primer lean', function () {
    // It was about 15.4 KB before the MCP content stopped repeating.
    expect(strlen(renderPrimer(true)))->toBeLessThan(10_500);
});
