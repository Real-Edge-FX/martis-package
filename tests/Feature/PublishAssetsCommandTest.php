<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Martis\Console\PublishAssetsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Exposes the command's protected completeness check so it can be
 * unit-tested against synthetic source/destination pairs without driving
 * the whole publish flow.
 */
class PublishAssetsProbe extends PublishAssetsCommand
{
    /** @return list<string> */
    public function probeMissing(string $source, string $destination): array
    {
        return $this->missingPublishedFiles($source, $destination);
    }
}

/**
 * Drives the real handle() end-to-end against a synthetic, deliberately
 * INCOMPLETE package source (a manifest that references a file absent from
 * the source tree), so the command's FAILURE branch is actually exercised
 * — the guard the reported black-screen bug depends on.
 */
class IncompletePublishProbe extends PublishAssetsCommand
{
    public string $fakeSource = '';

    protected function packagePublicPath(): string
    {
        return $this->fakeSource;
    }
}

afterEach(function () {
    // Remove synthetic destinations created by the probe tests.
    $fs = new Filesystem;
    foreach ((array) glob(sys_get_temp_dir().'/martis-pub-*') as $dir) {
        if (is_string($dir)) {
            $fs->deleteDirectory($dir);
        }
    }
});

it('martis:publish-assets is registered in the service provider', function () {
    $commands = $this->app->make(Kernel::class)->all();
    expect($commands)->toHaveKey('martis:publish-assets');
    expect($commands['martis:publish-assets'])->toBeInstanceOf(PublishAssetsCommand::class);
});

it('wipes public/vendor/martis/ before republishing', function () {
    // Seed a stale chunk in the destination so we can prove the
    // command deleted it. The post-publish state should contain
    // the package's manifest.json but NOT this stale file.
    $fs = new Filesystem;
    $destination = public_path('vendor/martis');
    $stale = $destination.'/assets/Stale.es-DEADBEEF.js';

    $fs->ensureDirectoryExists(dirname($stale));
    $fs->put($stale, '// stale chunk from a prior package version');

    expect($fs->exists($stale))->toBeTrue();

    $this->artisan('martis:publish-assets')->assertSuccessful();

    expect($fs->exists($stale))->toBeFalse();
    expect($fs->exists($destination.'/manifest.json'))->toBeTrue();
});

it('--no-wipe keeps stale chunks (legacy merge behaviour)', function () {
    $fs = new Filesystem;
    $destination = public_path('vendor/martis');
    $stale = $destination.'/assets/StaleKeep.es-CAFEBABE.js';

    $fs->ensureDirectoryExists(dirname($stale));
    $fs->put($stale, '// preserved by --no-wipe');

    $this->artisan('martis:publish-assets', ['--no-wipe' => true])->assertSuccessful();

    // With --no-wipe the stale file survives.
    expect($fs->exists($stale))->toBeTrue();
});

it('martis:vendor-publish --assets also wipes by default', function () {
    $fs = new Filesystem;
    $destination = public_path('vendor/martis');
    $stale = $destination.'/assets/StaleVendor.es-FEEDFACE.js';

    $fs->ensureDirectoryExists(dirname($stale));
    $fs->put($stale, '// stale chunk');

    $this->artisan('martis:vendor-publish', ['--assets' => true])->assertSuccessful();

    expect($fs->exists($stale))->toBeFalse();
});

it('martis:vendor-publish --assets --no-wipe preserves stale files', function () {
    $fs = new Filesystem;
    $destination = public_path('vendor/martis');
    $stale = $destination.'/assets/StaleVendorKeep.es-BADC0FFE.js';

    $fs->ensureDirectoryExists(dirname($stale));
    $fs->put($stale, '// preserved');

    $this->artisan('martis:vendor-publish', ['--assets' => true, '--no-wipe' => true])
        ->assertSuccessful();

    expect($fs->exists($stale))->toBeTrue();
});

it('publishes the COMPLETE asset set — app entry bundle + every package file', function () {
    // Direct rebuttal of the "766 of 1427 files, black screen" report:
    // after a clean publish the destination must mirror the package's
    // public/ tree exactly, app entry bundle included.
    $fs = new Filesystem;
    $destination = public_path('vendor/martis');
    $packagePublic = __DIR__.'/../../public';

    $this->artisan('martis:publish-assets')->assertSuccessful();

    // The app entry bundle (app-<hash>.js) — its absence is exactly the
    // black-screen symptom the report described.
    expect(glob($destination.'/assets/app-*.js'))->not->toBeEmpty();

    // Every file the package ships under public/ lands in the destination.
    // A subset would fail this equality.
    expect(count($fs->allFiles($destination)))->toBe(count($fs->allFiles($packagePublic)));

    // Stronger than a raw count: the command's own completeness check finds
    // nothing missing — every real manifest-referenced file landed and the
    // published manifest parses.
    expect((new PublishAssetsProbe)->probeMissing($packagePublic, $destination))->toBe([]);
});

it('missingPublishedFiles() flags a manifest-referenced file absent from the destination', function () {
    $fs = new Filesystem;
    $src = sys_get_temp_dir().'/martis-pub-src-'.uniqid();
    $dst = sys_get_temp_dir().'/martis-pub-dst-'.uniqid();
    $fs->ensureDirectoryExists($src.'/assets');
    $fs->ensureDirectoryExists($dst.'/assets');

    // Source manifest references three files; the destination received the
    // manifest + app bundle + CSS, but the chunk did not land — the
    // partial-copy failure mode.
    $manifest = json_encode([
        'resources/js/app.tsx' => [
            'file' => 'assets/app-present.js',
            'css' => ['assets/app-present.css'],
            'isEntry' => true,
        ],
        'chunk-y' => [
            'file' => 'assets/chunk-y.js',
        ],
    ]);
    $fs->put($src.'/manifest.json', $manifest);
    $fs->put($dst.'/manifest.json', $manifest);
    $fs->put($dst.'/assets/app-present.js', '// present');
    $fs->put($dst.'/assets/app-present.css', '/* present */');
    // Deliberately DO NOT create $dst/assets/chunk-y.js.

    expect((new PublishAssetsProbe)->probeMissing($src, $dst))->toBe(['assets/chunk-y.js']);
});

it('missingPublishedFiles() returns [] when the published set is complete', function () {
    $fs = new Filesystem;
    $src = sys_get_temp_dir().'/martis-pub-src-'.uniqid();
    $dst = sys_get_temp_dir().'/martis-pub-dst-'.uniqid();
    $fs->ensureDirectoryExists($src.'/assets');
    $fs->ensureDirectoryExists($dst.'/assets');

    $manifest = json_encode([
        'resources/js/app.tsx' => [
            'file' => 'assets/app.js',
            'css' => ['assets/app.css'],
            'isEntry' => true,
        ],
    ]);
    $fs->put($src.'/manifest.json', $manifest);
    $fs->put($dst.'/manifest.json', $manifest);
    $fs->put($dst.'/assets/app.js', '// ok');
    $fs->put($dst.'/assets/app.css', '/* ok */');

    expect((new PublishAssetsProbe)->probeMissing($src, $dst))->toBe([]);
});

it('missingPublishedFiles() fails closed when the destination manifest is absent', function () {
    // The fail-open hole: a partial copy that drops manifest.json must NOT
    // read as complete — the runtime cannot resolve the entry without it.
    $fs = new Filesystem;
    $src = sys_get_temp_dir().'/martis-pub-src-'.uniqid();
    $dst = sys_get_temp_dir().'/martis-pub-dst-'.uniqid();
    $fs->ensureDirectoryExists($src.'/assets');
    $fs->ensureDirectoryExists($dst.'/assets');

    $fs->put($src.'/manifest.json', json_encode([
        'resources/js/app.tsx' => ['file' => 'assets/app.js', 'isEntry' => true],
    ]));
    // Destination got the app bundle but NOT the manifest.
    $fs->put($dst.'/assets/app.js', '// ok');

    expect((new PublishAssetsProbe)->probeMissing($src, $dst))->toContain('manifest.json');
});

it('missingPublishedFiles() fails closed when the destination manifest is corrupt', function () {
    // A present-but-truncated manifest is as fatal as a missing one.
    $fs = new Filesystem;
    $src = sys_get_temp_dir().'/martis-pub-src-'.uniqid();
    $dst = sys_get_temp_dir().'/martis-pub-dst-'.uniqid();
    $fs->ensureDirectoryExists($src.'/assets');
    $fs->ensureDirectoryExists($dst.'/assets');

    $fs->put($src.'/manifest.json', json_encode([
        'resources/js/app.tsx' => ['file' => 'assets/app.js', 'isEntry' => true],
    ]));
    $fs->put($dst.'/assets/app.js', '// ok');
    // Truncated / invalid JSON at the destination.
    $fs->put($dst.'/manifest.json', '{"resources/js/app.tsx":{"file":"asse');

    expect((new PublishAssetsProbe)->probeMissing($src, $dst))->toBe(['manifest.json (unreadable)']);
});

it('missingPublishedFiles() fails closed when the destination manifest is empty ({})', function () {
    // The insidious sub-case: {} parses to an empty array, so a naive
    // "nothing referenced, nothing missing" check would pass with zero
    // files actually verified.
    $fs = new Filesystem;
    $src = sys_get_temp_dir().'/martis-pub-src-'.uniqid();
    $dst = sys_get_temp_dir().'/martis-pub-dst-'.uniqid();
    $fs->ensureDirectoryExists($src.'/assets');
    $fs->ensureDirectoryExists($dst.'/assets');

    $fs->put($src.'/manifest.json', json_encode([
        'resources/js/app.tsx' => ['file' => 'assets/app.js', 'isEntry' => true],
    ]));
    $fs->put($dst.'/assets/app.js', '// ok');
    $fs->put($dst.'/manifest.json', '{}');

    expect((new PublishAssetsProbe)->probeMissing($src, $dst))->toBe(['manifest.json (unreadable)']);
});

it('exits FAILURE end-to-end when the published set is incomplete', function () {
    // Guards the hardening itself: an incomplete publish must exit non-zero.
    // The synthetic source manifest references a ghost chunk that is not in
    // the source tree, so it can never land in the destination.
    $fs = new Filesystem;
    $src = sys_get_temp_dir().'/martis-pub-src-'.uniqid();
    $fs->ensureDirectoryExists($src.'/assets');

    $fs->put($src.'/manifest.json', json_encode([
        'resources/js/app.tsx' => [
            'file' => 'assets/app.js',
            'css' => ['assets/app.css'],
            'isEntry' => true,
        ],
        'ghost' => ['file' => 'assets/ghost.js'],
    ]));
    $fs->put($src.'/assets/app.js', '// app');
    $fs->put($src.'/assets/app.css', '/* app */');
    // assets/ghost.js deliberately absent from the source.

    $command = new IncompletePublishProbe;
    $command->fakeSource = $src;
    $command->setLaravel($this->app);

    $exit = $command->run(new ArrayInput([]), new NullOutput);

    expect($exit)->toBe(PublishAssetsCommand::FAILURE);

    // Restore the real published assets — this test wrote a synthetic,
    // incomplete tree into the shared public/vendor/martis destination.
    $this->artisan('martis:publish-assets')->assertSuccessful();
});
