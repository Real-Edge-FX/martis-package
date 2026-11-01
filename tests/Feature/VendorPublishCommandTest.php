<?php

use Illuminate\Support\Facades\File;

// martis:vendor-publish

/**
 * Where each group lands in the test application. A group counts as
 * published when its entry file is there.
 *
 * @return array<string, string>
 */
function vendorPublishTargets(): array
{
    return [
        'config' => config_path('martis.php'),
        'assets' => public_path('vendor/martis/manifest.json'),
        'views' => resource_path('views/vendor/martis/app.blade.php'),
        'lang' => lang_path('vendor/martis/en'),
    ];
}

/**
 * @return array<string, bool>
 */
function vendorPublishFootprint(): array
{
    return array_map(fn (string $path): bool => file_exists($path), vendorPublishTargets());
}

function vendorPublishClean(): void
{
    File::delete(config_path('martis.php'));
    File::deleteDirectory(public_path('vendor/martis'));
    File::deleteDirectory(resource_path('views/vendor/martis'));
    File::deleteDirectory(lang_path('vendor/martis'));
}

// The groups share one test application, and a published view overrides the
// package one for every later test in the same environment (the blade-output
// passthrough tests would then assert against a stale copy). Start and end
// every spec with nothing published.
beforeEach(fn () => vendorPublishClean());
afterEach(fn () => vendorPublishClean());

it('martis:vendor-publish publishes exactly the groups it is asked for', function (array $options, array $published) {
    $this->artisan('martis:vendor-publish', $options)->assertSuccessful();

    expect(vendorPublishFootprint())->toBe(array_merge(
        ['config' => false, 'assets' => false, 'views' => false, 'lang' => false],
        array_fill_keys($published, true),
    ));
})->with([
    'no options (config and assets)' => [[], ['config', 'assets']],
    '--config' => [['--config' => true], ['config']],
    '--assets' => [['--assets' => true], ['assets']],
    '--views' => [['--views' => true], ['views']],
    '--lang' => [['--lang' => true], ['lang']],
    '--views --lang' => [['--views' => true, '--lang' => true], ['views', 'lang']],
]);

it('martis:vendor-publish --force overwrites a published file that a plain run leaves alone', function () {
    $packageConfig = file_get_contents(__DIR__.'/../../config/martis.php');
    File::ensureDirectoryExists(dirname(config_path('martis.php')));
    file_put_contents(config_path('martis.php'), '<?php // edited by the host app');

    $this->artisan('martis:vendor-publish', ['--config' => true])->assertSuccessful();
    expect(file_get_contents(config_path('martis.php')))->toBe('<?php // edited by the host app');

    $this->artisan('martis:vendor-publish', ['--config' => true, '--force' => true])->assertSuccessful();
    expect(file_get_contents(config_path('martis.php')))->toBe($packageConfig);
});
