<?php

declare(strict_types=1);

use Martis\Discovery\Psr4NamespaceResolver;
use Martis\Facades\Martis;
use Martis\MartisServiceProvider;
use Martis\ResourceRegistry;
use Martis\Tests\Fixtures\DiscoveryNamespace\Resources\DiscoveredWidgetResource;
use Martis\Tests\Fixtures\DiscoveryNamespace\Tools\DiscoveredStatusTool;

/**
 * `resources_namespace` / `tools_namespace` wiring in the service
 * provider: explicit string → PSR-4 derivation → conventional fallback.
 *
 * Discovery runs inside `MartisServiceProvider::boot()`, before a test
 * body can touch the config, so each scenario sets the config and then
 * re-runs the provider's discovery method through a bound closure.
 */
const DISCOVERY_FIXTURES = __DIR__.'/../Fixtures/DiscoveryNamespace';

function rerunDiscovery(string $method): void
{
    $provider = app()->getProvider(MartisServiceProvider::class);

    (fn () => $this->{$method}())->call($provider);
}

/** @return list<class-string> */
function discoveredToolClasses(): array
{
    return array_map(
        static fn (object $tool): string => $tool::class,
        Martis::resolveTools(request()),
    );
}

// ---------------------------------------------------------------------------
// Resources
// ---------------------------------------------------------------------------

it('derives the resources namespace from the PSR-4 map when the key is null', function () {
    config()->set('martis.resources_path', DISCOVERY_FIXTURES.'/Resources');
    config()->set('martis.resources_namespace', null);

    rerunDiscovery('discoverResources');

    expect(app(ResourceRegistry::class)->has('discovered-widgets'))->toBeTrue()
        ->and(app(ResourceRegistry::class)->get('discovered-widgets'))->toBe(DiscoveredWidgetResource::class);
});

it('uses an explicit resources namespace verbatim', function () {
    config()->set('martis.resources_path', DISCOVERY_FIXTURES.'/Resources');
    config()->set('martis.resources_namespace', 'Martis\\Tests\\Fixtures\\DiscoveryNamespace\\Resources');

    rerunDiscovery('discoverResources');

    expect(app(ResourceRegistry::class)->has('discovered-widgets'))->toBeTrue();
});

it('does not second-guess a wrong explicit resources namespace', function () {
    config()->set('martis.resources_path', DISCOVERY_FIXTURES.'/Resources');
    config()->set('martis.resources_namespace', 'Wrong\\Namespace');

    rerunDiscovery('discoverResources');

    expect(app(ResourceRegistry::class)->has('discovered-widgets'))->toBeFalse();
});

it('falls back to App\\Martis for a directory outside every PSR-4 root', function () {
    $dir = sys_get_temp_dir().'/martis_ns_fallback_'.uniqid();
    mkdir($dir, 0755, true);
    $suffix = 'N'.uniqid();
    $uriKey = 'fallback-'.strtolower($suffix);

    file_put_contents($dir.'/Fallback'.$suffix.'Resource.php', "<?php
namespace App\\Martis;
use Illuminate\\Http\\Request;
use Martis\\Resource;
class Fallback{$suffix}Resource extends Resource {
    public static function model(): string { return \\Martis\\Tests\\Fixtures\\DiscoveryNamespace\\DiscoveredWidget::class; }
    public static function uriKey(): string { return '{$uriKey}'; }
    public function fields(Request \$request): array { return []; }
}
");

    try {
        config()->set('martis.resources_path', $dir);
        config()->set('martis.resources_namespace', null);

        rerunDiscovery('discoverResources');

        // Not autoloadable, not under any PSR-4 root: the conventional
        // namespace is assumed and the file is required by the discovery.
        expect(app(ResourceRegistry::class)->has($uriKey))->toBeTrue();
    } finally {
        rmtree($dir);
    }
});

it('leaves the factory defaults byte-for-byte unchanged', function () {
    // The testbench skeleton's app/ is not in this package's PSR-4 map,
    // so app/Martis takes the fallback: it must equal the historical
    // convention that discovery hard-coded before v1.36.0.
    $provider = app()->getProvider(MartisServiceProvider::class);
    $namespace = (fn () => $this->discoveryNamespace('martis.resources_namespace', app_path('Martis'), 'App\\Martis'))->call($provider);

    expect($namespace)->toBe('App\\Martis');
});

it('binds the resolver as a singleton so the PSR-4 map is read once', function () {
    expect(app(Psr4NamespaceResolver::class))->toBe(app(Psr4NamespaceResolver::class));
});

// ---------------------------------------------------------------------------
// Tools
// ---------------------------------------------------------------------------

it('derives the tools namespace from the PSR-4 map when the key is null', function () {
    config()->set('martis.tools_path', DISCOVERY_FIXTURES.'/Tools');
    config()->set('martis.tools_namespace', null);

    rerunDiscovery('discoverTools');

    expect(discoveredToolClasses())->toContain(DiscoveredStatusTool::class);
});

it('keeps honouring an explicit tools namespace', function () {
    config()->set('martis.tools_path', DISCOVERY_FIXTURES.'/Tools');
    config()->set('martis.tools_namespace', 'Martis\\Tests\\Fixtures\\DiscoveryNamespace\\Tools');

    rerunDiscovery('discoverTools');

    expect(discoveredToolClasses())->toContain(DiscoveredStatusTool::class);
});
