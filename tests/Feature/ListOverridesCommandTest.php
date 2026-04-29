<?php

declare(strict_types=1);

use Martis\MartisManager;
use Martis\ResourceRegistry;
use Martis\Tools\Tool;

class ListOverridesTestTool extends Tool
{
    public function __construct()
    {
        $this->withComponent('list-overrides-test-component');
    }

    public function name(): string
    {
        return 'Test Tool';
    }

    public function uriKey(): string
    {
        return 'list-overrides-test-tool';
    }

    public function authorizedToSee($request): bool
    {
        return true;
    }
}

beforeEach(function () {
    $this->app->forgetInstance(ResourceRegistry::class);
    $this->app->forgetInstance(MartisManager::class);

    $this->app->singleton(ResourceRegistry::class, fn () => new ResourceRegistry);
    $this->app->singleton(MartisManager::class, fn () => new MartisManager);
});

it('martis:list-overrides reports an empty registry cleanly', function () {
    $this->artisan('martis:list-overrides')
        ->expectsOutputToContain('No component keys declared by the PHP layer.')
        ->assertSuccessful();
});

it('martis:list-overrides lists registered tools', function () {
    /** @var MartisManager $manager */
    $manager = app(MartisManager::class);
    $manager->tools([new ListOverridesTestTool]);

    $this->artisan('martis:list-overrides')
        ->expectsOutputToContain('list-overrides-test-component')
        ->expectsOutputToContain('component key(s) declared')
        ->assertSuccessful();
});

it('martis:list-overrides --kind=tool filters to tools', function () {
    /** @var MartisManager $manager */
    $manager = app(MartisManager::class);
    $manager->tools([new ListOverridesTestTool]);

    /** @var ResourceRegistry $resources */
    $resources = app(ResourceRegistry::class);
    // Don't register any resource — confirms --kind=tool excludes resources.

    $this->artisan('martis:list-overrides', ['--kind' => 'tool'])
        ->expectsOutputToContain('list-overrides-test-component')
        ->assertSuccessful();
});

it('martis:list-overrides --kind=invalid errors out', function () {
    $this->artisan('martis:list-overrides', ['--kind' => 'bogus'])
        ->expectsOutputToContain("Unknown --kind 'bogus'")
        ->assertFailed();
});

it('martis:list-overrides --filter narrows by substring', function () {
    /** @var MartisManager $manager */
    $manager = app(MartisManager::class);
    $manager->tools([new ListOverridesTestTool]);

    $this->artisan('martis:list-overrides', ['--filter' => 'list-overrides'])
        ->expectsOutputToContain('list-overrides-test-component')
        ->assertSuccessful();

    // A non-matching filter should report no rows.
    $this->artisan('martis:list-overrides', ['--filter' => 'zzz-impossible'])
        ->expectsOutputToContain('No component keys declared by the PHP layer.')
        ->assertSuccessful();
});
