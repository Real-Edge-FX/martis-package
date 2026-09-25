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

/**
 * A temporary directory for a synthetic publish source or destination,
 * recorded so the afterEach hook removes it.
 */
function publishProbeDir(string $kind): string
{
    $dir = sys_get_temp_dir().'/martis-pub-'.$kind.'-'.uniqid('', true);
    $GLOBALS['__martis_publish_probe_dirs'][] = $dir;

    return $dir;
}

$GLOBALS['__martis_publish_probe_dirs'] = [];

beforeEach(function () {
    // Theme sources left in the shared testbench app by other specs would be
    // published too; each test here starts without any.
    $fs = new Filesystem;
    $fs->deleteDirectory(resource_path('css/martis'));
    $fs->deleteDirectory(public_path('vendor/martis/themes'));
});

afterEach(function () {
    // Remove the synthetic sources and destinations this file's tests
    // created, and only those: another suite on the same machine has its own.
    $fs = new Filesystem;
    foreach ($GLOBALS['__martis_publish_probe_dirs'] as $dir) {
        $fs->deleteDirectory($dir);
    }
    $GLOBALS['__martis_publish_probe_dirs'] = [];

    // Remove the theme sources and published copies the theme tests create.
    $fs->deleteDirectory(resource_path('css/martis'));
    $fs->deleteDirectory(public_path('vendor/martis/themes'));
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

it('keeps a theme scaffolded by martis:theme across a publish', function () {
    // The reported bug: martis:theme writes the source and a published copy,
    // then every asset publish wiped public/vendor/martis/ with the copy in
    // it, so the panel 404'd the stylesheet and martis:theme:diff found no
    // theme.
    // martis:theme also writes theme.name into a published config/martis.php.
    $restoreMartisConfig = preservePublishedMartisConfig();

    try {
        $this->artisan('martis:theme', ['name' => 'survivor'])->assertSuccessful();

        $this->artisan('martis:publish-assets')->assertSuccessful();

        $published = public_path('vendor/martis/themes/survivor.css');
        expect($published)->toBeFile();
        expect(file_get_contents($published))->toBe(file_get_contents(resource_path('css/martis/survivor.css')));

        $this->artisan('martis:theme:diff', ['theme' => 'survivor'])->assertExitCode(0);
    } finally {
        $restoreMartisConfig();
    }
});

it('publishes every theme source in resources/css/martis', function () {
    // An app whose published copies are already gone (every publish before
    // the fix deleted them) gets them back on the next publish.
    $fs = new Filesystem;
    $fs->ensureDirectoryExists(resource_path('css/martis/partials'));
    $fs->put(resource_path('css/martis/alpha.css'), ':root { --martis-accent: #ff0000; }');
    $fs->put(resource_path('css/martis/beta.css'), ':root { --martis-accent: #00ff00; }');
    // Not themes: another extension, and a stylesheet in a subdirectory.
    $fs->put(resource_path('css/martis/notes.md'), '# Notes');
    $fs->put(resource_path('css/martis/partials/colors.css'), ':root {}');

    $this->artisan('martis:publish-assets')->assertSuccessful();

    $published = public_path('vendor/martis/themes');
    expect($published.'/alpha.css')->toBeFile();
    expect($published.'/beta.css')->toBeFile();
    expect(file_get_contents($published.'/alpha.css'))->toBe(':root { --martis-accent: #ff0000; }');
    expect(file_get_contents($published.'/beta.css'))->toBe(':root { --martis-accent: #00ff00; }');
    expect(array_map(fn ($file) => $file->getRelativePathname(), $fs->allFiles($published)))
        ->toEqualCanonicalizing(['alpha.css', 'beta.css']);
});

it('replaces a published copy edited in place with its source', function () {
    // resources/css/martis/ holds the theme source. The published copy is
    // generated from it, so edits made to the copy do not survive a publish.
    $fs = new Filesystem;
    $fs->ensureDirectoryExists(resource_path('css/martis'));
    $fs->put(resource_path('css/martis/brand.css'), ':root { --martis-accent: #123456; }');
    $fs->ensureDirectoryExists(public_path('vendor/martis/themes'));
    $fs->put(public_path('vendor/martis/themes/brand.css'), ':root { --martis-accent: #abcdef; }');

    $this->artisan('martis:publish-assets')->assertSuccessful();

    $published = public_path('vendor/martis/themes/brand.css');
    expect($published)->toBeFile();
    expect(file_get_contents($published))->toBe(':root { --martis-accent: #123456; }');
});

it('drops a published theme whose source is gone', function () {
    $fs = new Filesystem;
    $fs->ensureDirectoryExists(public_path('vendor/martis/themes'));
    $fs->put(public_path('vendor/martis/themes/orphan.css'), ':root { --martis-accent: #abcdef; }');

    $this->artisan('martis:publish-assets')->assertSuccessful();

    expect(public_path('vendor/martis/themes/orphan.css'))->not->toBeFile();
});

it('republishes the theme sources with --no-wipe too', function () {
    $fs = new Filesystem;
    $fs->ensureDirectoryExists(resource_path('css/martis'));
    $fs->put(resource_path('css/martis/brand.css'), ':root { --martis-accent: #123456; }');
    $fs->ensureDirectoryExists(public_path('vendor/martis/themes'));
    $fs->put(public_path('vendor/martis/themes/brand.css'), ':root { --martis-accent: #abcdef; }');

    $this->artisan('martis:publish-assets', ['--no-wipe' => true])->assertSuccessful();

    expect(file_get_contents(public_path('vendor/martis/themes/brand.css')))
        ->toBe(':root { --martis-accent: #123456; }');
});

it('martis:vendor-publish --assets republishes the theme sources too', function () {
    // martis:vendor-publish --assets and martis:install wipe through
    // martis:publish-assets, so a theme has to survive them the same way.
    $fs = new Filesystem;
    $fs->ensureDirectoryExists(resource_path('css/martis'));
    $fs->put(resource_path('css/martis/brand.css'), ':root { --martis-accent: #123456; }');

    $this->artisan('martis:vendor-publish', ['--assets' => true])->assertSuccessful();

    $published = public_path('vendor/martis/themes/brand.css');
    expect($published)->toBeFile();
    expect(file_get_contents($published))->toBe(':root { --martis-accent: #123456; }');
});

it('skips a theme source whose name the panel does not load', function () {
    // app.blade.php only links a theme named with letters, digits, dashes and
    // underscores, so publishing any other file would never reach the panel.
    $fs = new Filesystem;
    $fs->ensureDirectoryExists(resource_path('css/martis'));
    $fs->put(resource_path('css/martis/my theme.css'), ':root {}');

    $this->artisan('martis:publish-assets')
        ->expectsOutputToContain('Skipped resources/css/martis/my theme.css')
        ->assertSuccessful();

    expect(public_path('vendor/martis/themes/my theme.css'))->not->toBeFile();
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
    $src = publishProbeDir('src');
    $dst = publishProbeDir('dst');
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
    $src = publishProbeDir('src');
    $dst = publishProbeDir('dst');
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
    $src = publishProbeDir('src');
    $dst = publishProbeDir('dst');
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
    $src = publishProbeDir('src');
    $dst = publishProbeDir('dst');
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
    $src = publishProbeDir('src');
    $dst = publishProbeDir('dst');
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
    $src = publishProbeDir('src');
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
