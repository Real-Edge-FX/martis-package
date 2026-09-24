<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Martis\MartisManager;
use Martis\Support\InstalledVersion;
use Martis\Tests\TestCase;

uses(TestCase::class);

it('reports the version Composer installed for a package', function () {
    expect(InstalledVersion::of('martis/martis'))->toBe(InstalledVersions::getPrettyVersion('martis/martis'));
});

it('answers null, without throwing, for a package Composer did not install', function () {
    expect(InstalledVersion::of('acme/not-installed'))->toBeNull();
});

it('reports the installed version without its leading v on the sidebar', function () {
    config()->set('martis.brand.version', null);

    expect(app(MartisManager::class)->version())->toBe(ltrim((string) InstalledVersions::getPrettyVersion('martis/martis'), 'v'));
});

it('lets the brand.version config override the installed version on the sidebar', function () {
    config()->set('martis.brand.version', '9.9.9-custom');

    expect(app(MartisManager::class)->version())->toBe('9.9.9-custom');
});
