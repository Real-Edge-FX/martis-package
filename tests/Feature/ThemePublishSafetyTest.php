<?php

use Illuminate\Filesystem\Filesystem;

/*
 * martis:publish-assets makes public/vendor/martis/themes/ match the theme
 * sources in resources/css/martis/, so it deletes and overwrites files there.
 * It never does that silently or blindly:
 *
 *  - every file it is about to destroy that it cannot write back is copied to
 *    storage/app/martis/theme-backups/<run>/ first, with a warning. A copy the
 *    command published itself (recorded in
 *    storage/app/martis/published-themes.json) or one identical to its source
 *    is replaced without a backup, so the edit-and-publish loop stays quiet;
 *  - it stops, changing nothing, when a theme source cannot be read, or when
 *    the run would take away the theme martis.theme.name names.
 */

const THEME_SAFETY_STALE_CHUNK = 'vendor/martis/assets/StaleThemeSafety.es-5AFE0000.js';

function themeSafetyReset(): void
{
    $fs = new Filesystem;
    $fs->deleteDirectory(resource_path('css/martis'));
    $fs->deleteDirectory(public_path('vendor/martis/themes'));
    $fs->delete(public_path(THEME_SAFETY_STALE_CHUNK));
    removeThemeState();
}

function themeSafetySource(string $file, string $css): void
{
    $fs = new Filesystem;
    $fs->ensureDirectoryExists(resource_path('css/martis'));
    $fs->put(resource_path('css/martis/'.$file), $css);
}

function themeSafetyPublished(string $file, string $css): void
{
    $fs = new Filesystem;
    $path = public_path('vendor/martis/themes/'.$file);
    $fs->ensureDirectoryExists(dirname($path));
    $fs->put($path, $css);
}

/**
 * A package chunk the wipe deletes: it is still there only when the run
 * stopped before the wipe.
 */
function themeSafetyStaleChunk(): string
{
    $fs = new Filesystem;
    $path = public_path(THEME_SAFETY_STALE_CHUNK);
    $fs->ensureDirectoryExists(dirname($path));
    $fs->put($path, '// stale chunk');

    return $path;
}

/**
 * Every file under the themes directory, hidden ones included, sorted.
 *
 * @return list<string>
 */
function themeSafetyPublishedNames(): array
{
    $names = array_map(
        fn ($file) => $file->getRelativePathname(),
        (new Filesystem)->allFiles(public_path('vendor/martis/themes'), true),
    );
    sort($names);

    return $names;
}

/** Whether a file opens for reading, the question the command asks. */
function themeSafetyOpens(string $path): bool
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    fclose($handle);

    return true;
}

/**
 * Whether the testbench app's filesystem tells names apart by case: Linux
 * does; macOS, Windows and Docker Desktop bind mounts do not.
 */
function themeSafetyIsCaseSensitive(): bool
{
    $probe = storage_path('framework/CaseProbe.tmp');
    file_put_contents($probe, '');
    $sensitive = ! file_exists(storage_path('framework/caseprobe.tmp'));
    unlink($probe);

    return $sensitive;
}

beforeEach(function () {
    themeSafetyReset();
    $this->restoreMartisConfig = preservePublishedMartisConfig();
});

afterEach(function () {
    themeSafetyReset();
    ($this->restoreMartisConfig)();
});

// ---------------------------------------------------------------------------
// Files the publish removes or replaces are backed up first
// ---------------------------------------------------------------------------

it('backs up a published theme that has no source before the wipe removes it', function () {
    themeSafetyPublished('legacy.css', ':root { --martis-accent: #abcdef; }');

    $this->artisan('martis:publish-assets')
        ->expectsOutputToContain('public/vendor/martis/themes/legacy.css has no source in resources/css/martis/')
        ->expectsOutputToContain('Backed up to storage/app/martis/theme-backups/')
        ->assertSuccessful();

    expect(public_path('vendor/martis/themes/legacy.css'))->not->toBeFile();

    $backups = themeBackups();
    expect($backups)->toHaveCount(1)
        ->and((string) array_key_first($backups))->toEndWith('/public/vendor/martis/themes/legacy.css')
        ->and(array_values($backups))->toBe([':root { --martis-accent: #abcdef; }']);
});

it('backs up a published copy edited in place before replacing it with its source', function () {
    // The common 1.x case: martis:theme wrote both files, the scaffold
    // stayed in the source and the edits went to the published copy.
    themeSafetySource('brand.css', ':root { --martis-accent: #123456; }');
    themeSafetyPublished('brand.css', ':root { --martis-accent: #abcdef; }');

    $this->artisan('martis:publish-assets')
        ->expectsOutputToContain('public/vendor/martis/themes/brand.css differs from its source')
        ->expectsOutputToContain('Backed up to storage/app/martis/theme-backups/')
        ->assertSuccessful();

    expect(file_get_contents(public_path('vendor/martis/themes/brand.css')))
        ->toBe(':root { --martis-accent: #123456; }');
    expect(array_values(themeBackups()))->toBe([':root { --martis-accent: #abcdef; }']);
});

it('backs up an edited published copy before --no-wipe overwrites it', function () {
    // 1.x kept this copy under --no-wipe; now the source overwrites it.
    themeSafetySource('brand.css', ':root { --martis-accent: #123456; }');
    themeSafetyPublished('brand.css', ':root { --martis-accent: #abcdef; }');

    $this->artisan('martis:publish-assets', ['--no-wipe' => true])
        ->expectsOutputToContain('public/vendor/martis/themes/brand.css differs from its source')
        ->assertSuccessful();

    expect(file_get_contents(public_path('vendor/martis/themes/brand.css')))
        ->toBe(':root { --martis-accent: #123456; }');
    expect(array_values(themeBackups()))->toBe([':root { --martis-accent: #abcdef; }']);
});

it('backs up other files in the themes directory before the wipe removes them', function () {
    // A 1.x theme could reference fonts or images placed next to it.
    themeSafetySource('brand.css', ':root {}');
    themeSafetyPublished('brand.css', ':root {}');
    themeSafetyPublished('fonts/brand.woff2', 'font-bytes');

    $this->artisan('martis:publish-assets')
        ->expectsOutputToContain('public/vendor/martis/themes/fonts/brand.woff2 is not generated from resources/css/martis/')
        ->assertSuccessful();

    expect(public_path('vendor/martis/themes/fonts/brand.woff2'))->not->toBeFile();

    $backups = themeBackups();
    expect($backups)->toHaveCount(1)
        ->and((string) array_key_first($backups))->toEndWith('/public/vendor/martis/themes/fonts/brand.woff2')
        ->and(array_values($backups))->toBe(['font-bytes']);
});

it('backs up hidden files in the themes directory too', function () {
    themeSafetyPublished('.htaccess', 'Header set Cache-Control "max-age=60"');

    $this->artisan('martis:publish-assets')
        ->expectsOutputToContain('public/vendor/martis/themes/.htaccess is not generated from resources/css/martis/')
        ->assertSuccessful();

    expect(array_values(themeBackups()))->toBe(['Header set Cache-Control "max-age=60"']);
});

it('backs up through martis:vendor-publish --assets as well', function () {
    themeSafetyPublished('legacy.css', ':root { --martis-accent: #abcdef; }');

    $this->artisan('martis:vendor-publish', ['--assets' => true])->assertSuccessful();

    expect(array_values(themeBackups()))->toBe([':root { --martis-accent: #abcdef; }']);
});

it('stops before deleting anything when a backup cannot be written', function () {
    $fs = new Filesystem;
    themeSafetyPublished('legacy.css', ':root {}');
    // A file where the backup directory has to go.
    $fs->ensureDirectoryExists(storage_path('app/martis'));
    $fs->put(storage_path('app/martis/theme-backups'), 'not a directory');
    $stale = themeSafetyStaleChunk();

    $this->artisan('martis:publish-assets')
        ->expectsOutputToContain('Could not back up public/vendor/martis/themes/legacy.css')
        ->assertFailed();

    expect(public_path('vendor/martis/themes/legacy.css'))->toBeFile();
    // The wipe never ran.
    expect($stale)->toBeFile();
});

it('prints no removal warning when a later backup fails', function () {
    // All backups are made before any warning: a warning that says a file
    // is removed must not appear when the run then stops.
    themeSafetyPublished('a.css', 'first');
    themeSafetyPublished('b.css', 'second');
    $unreadable = public_path('vendor/martis/themes/b.css');
    chmod($unreadable, 0000);

    try {
        if (themeSafetyOpens($unreadable)) {
            // The mode does not stop the read here: both are backed up.
            $this->artisan('martis:publish-assets')->assertSuccessful();
            expect(array_values(themeBackups()))->toEqualCanonicalizing(['first', 'second']);
        } else {
            $this->artisan('martis:publish-assets')
                ->expectsOutputToContain('Could not back up public/vendor/martis/themes/b.css')
                ->doesntExpectOutputToContain('so this publish removes it')
                ->assertFailed();
            expect(public_path('vendor/martis/themes/a.css'))->toBeFile();
        }
    } finally {
        if (file_exists($unreadable)) {
            chmod($unreadable, 0644);
        }
    }
});

it('gives every run its own backup directory', function () {
    $this->travelTo(now()->startOfSecond());

    themeSafetyPublished('legacy.css', 'first');
    $this->artisan('martis:publish-assets', ['--themes-only' => true])->assertSuccessful();

    themeSafetyPublished('legacy.css', 'second');
    $this->artisan('martis:publish-assets', ['--themes-only' => true])->assertSuccessful();

    $backups = themeBackups();
    $runs = array_map(fn (string $path): string => explode('/', $path)[0], array_keys($backups));
    expect($backups)->toHaveCount(2)
        ->and($runs[0])->not->toBe($runs[1])
        ->and(array_values($backups))->toEqualCanonicalizing(['first', 'second']);
});

it('does not back up the same content twice', function () {
    // A copy committed to git comes back on every deploy.
    themeSafetyPublished('legacy.css', 'same');
    $this->artisan('martis:publish-assets', ['--themes-only' => true])->assertSuccessful();

    themeSafetyPublished('legacy.css', 'same');
    $this->artisan('martis:publish-assets', ['--themes-only' => true])
        ->expectsOutputToContain('Already backed up to storage/app/martis/theme-backups/')
        ->assertSuccessful();

    expect(array_values(themeBackups()))->toBe(['same']);
});

it('keeps the ten newest backup runs', function () {
    $fs = new Filesystem;
    foreach (range(1, 12) as $run) {
        $old = storage_path(sprintf('app/martis/theme-backups/20250101-0000%02d/public/vendor/martis/themes/old.css', $run));
        $fs->ensureDirectoryExists(dirname($old));
        $fs->put($old, "old {$run}");
    }
    themeSafetyPublished('legacy.css', 'new');

    $this->artisan('martis:publish-assets', ['--themes-only' => true])->assertSuccessful();

    $runs = array_map('basename', $fs->directories(storage_path('app/martis/theme-backups')));
    sort($runs);
    expect($runs)->toHaveCount(10)
        ->and($runs[0])->toBe('20250101-000004')
        ->and(array_values(themeBackups()))->toContain('new');
});

it('treats an unreadable publish record as absent', function () {
    themeSafetySource('brand.css', 'source');
    themeSafetyPublished('brand.css', 'edited');
    (new Filesystem)->ensureDirectoryExists(storage_path('app/martis'));
    file_put_contents(storage_path('app/martis/published-themes.json'), '{not json');

    $this->artisan('martis:publish-assets')->assertSuccessful();

    expect(array_values(themeBackups()))->toBe(['edited']);
});

// ---------------------------------------------------------------------------
// Containment: what the publish writes back itself is not backed up
// ---------------------------------------------------------------------------

it('does not back up a copy it published itself when the source changed since', function () {
    // The edit-and-publish loop: the published copy is the previous publish.
    themeSafetySource('brand.css', ':root { --martis-accent: #111111; }');
    $this->artisan('martis:publish-assets')->assertSuccessful();

    themeSafetySource('brand.css', ':root { --martis-accent: #222222; }');
    $this->artisan('martis:publish-assets')
        ->doesntExpectOutputToContain('Backed up')
        ->assertSuccessful();

    expect(file_get_contents(public_path('vendor/martis/themes/brand.css')))
        ->toBe(':root { --martis-accent: #222222; }');
    expect(themeBackups())->toBe([]);
});

it('does not back up a published copy identical to its source', function () {
    // A 1.x copy nobody edited: there is no publish record, and nothing to lose.
    themeSafetySource('brand.css', ':root {}');
    themeSafetyPublished('brand.css', ':root {}');

    $this->artisan('martis:publish-assets')->assertSuccessful();

    expect(themeBackups())->toBe([]);
});

it('leaves a published theme without a source alone under --no-wipe', function () {
    themeSafetyPublished('legacy.css', ':root {}');

    $this->artisan('martis:publish-assets', ['--no-wipe' => true])
        ->doesntExpectOutputToContain('Backed up')
        ->assertSuccessful();

    expect(public_path('vendor/martis/themes/legacy.css'))->toBeFile();
    expect(themeBackups())->toBe([]);
});

it('keeps its publish record in storage, out of the web root', function () {
    themeSafetySource('brand.css', ':root {}');

    $this->artisan('martis:publish-assets')->assertSuccessful();

    $record = storage_path('app/martis/published-themes.json');
    expect($record)->toBeFile();
    expect(json_decode((string) file_get_contents($record), true)['themes'] ?? null)
        ->toBe(['brand' => sha1(':root {}')]);
    expect(themeSafetyPublishedNames())->toBe(['brand.css']);

    $this->artisan('martis:publish-assets')->assertSuccessful();

    expect(themeBackups())->toBe([]);
});

// ---------------------------------------------------------------------------
// The run stops, changing nothing
// ---------------------------------------------------------------------------

it('refuses to publish while a theme source is a broken symlink', function () {
    // Listed without Finder, whose files() drops broken symlinks from 7.4.19.
    themeSafetySource('brand.css', ':root {}');
    symlink(resource_path('css/martis/missing-target.css'), resource_path('css/martis/broken.css'));
    themeSafetyPublished('legacy.css', 'legacy');
    $stale = themeSafetyStaleChunk();

    $this->artisan('martis:publish-assets')
        ->expectsOutputToContain('Could not read resources/css/martis/broken.css: it is a broken symlink')
        ->assertFailed();

    expect($stale)->toBeFile();
    expect(public_path('vendor/martis/themes/legacy.css'))->toBeFile();
    expect(public_path('vendor/martis/themes/brand.css'))->not->toBeFile();
    expect(themeBackups())->toBe([]);
});

it('refuses to publish while a theme source cannot be read', function () {
    themeSafetySource('brand.css', ':root {}');
    themeSafetySource('locked.css', ':root {}');
    $locked = resource_path('css/martis/locked.css');
    chmod($locked, 0000);
    $stale = themeSafetyStaleChunk();

    // Each artisan() call runs when its statement ends, before the finally
    // block makes the file readable again. Root reads a mode-000 file on a
    // native filesystem, while a bind mount can refuse root the read, so
    // the probe asks the command's question by opening the file.
    try {
        if (themeSafetyOpens($locked)) {
            $this->artisan('martis:publish-assets')->assertSuccessful();
            expect(public_path('vendor/martis/themes/locked.css'))->toBeFile();
        } else {
            $this->artisan('martis:publish-assets')
                ->expectsOutputToContain('Could not read resources/css/martis/locked.css: it does not open for reading')
                ->assertFailed();
            expect($stale)->toBeFile();
            expect(public_path('vendor/martis/themes/brand.css'))->not->toBeFile();
        }
    } finally {
        chmod($locked, 0644);
    }
});

it('refuses to remove the published copy of the active theme when it has no source', function (array $options) {
    // The 1.x theme that only exists as its published copy, the one the
    // panel loads: removing it would leave the panel without a theme.
    themeSafetyPublished('legacy.css', 'legacy');
    config()->set('martis.theme.name', 'legacy');
    $stale = themeSafetyStaleChunk();

    $this->artisan('martis:publish-assets', $options)
        ->expectsOutputToContain('martis.theme.name is "legacy", but resources/css/martis/legacy.css does not exist')
        ->assertFailed();

    expect(public_path('vendor/martis/themes/legacy.css'))->toBeFile();
    expect($stale)->toBeFile();
    expect(themeBackups())->toBe([]);
})->with([
    'full publish' => [[]],
    '--themes-only' => [['--themes-only' => true]],
]);

it('refuses to publish when the source of the active theme is skipped', function () {
    themeSafetySource('Brand.CSS', ':root {}');
    config()->set('martis.theme.name', 'Brand');
    $stale = themeSafetyStaleChunk();

    $this->artisan('martis:publish-assets')
        ->expectsOutputToContain('martis.theme.name is "Brand", but its source resources/css/martis/Brand.CSS is skipped')
        ->assertFailed();

    expect($stale)->toBeFile();
});

// ---------------------------------------------------------------------------
// martis.theme.name warnings that do not stop the run
// ---------------------------------------------------------------------------

it('warns about an active theme without a source that --no-wipe keeps', function () {
    themeSafetyPublished('legacy.css', 'legacy');
    config()->set('martis.theme.name', 'legacy');

    $this->artisan('martis:publish-assets', ['--no-wipe' => true])
        ->expectsOutputToContain('martis.theme.name is "legacy", but resources/css/martis/legacy.css is not a theme source')
        ->assertSuccessful();

    expect(public_path('vendor/martis/themes/legacy.css'))->toBeFile();
});

it('warns when martis.theme.name has no source and nothing to remove', function () {
    config()->set('martis.theme.name', 'missing');

    $this->artisan('martis:publish-assets')
        ->expectsOutputToContain('martis.theme.name is "missing", but resources/css/martis/missing.css is not a theme source')
        ->assertSuccessful();
});

it('warns when martis.theme.name and its source differ in case', function () {
    // Found on macOS and Windows, a 404 on a Linux server.
    themeSafetySource('brand.css', ':root {}');
    config()->set('martis.theme.name', 'Brand');

    $this->artisan('martis:publish-assets')
        ->expectsOutputToContain('martis.theme.name "Brand" and resources/css/martis/brand.css differ in case')
        ->assertSuccessful();
});

it('warns when martis.theme.name is a name the panel ignores', function () {
    config()->set('martis.theme.name', 'my theme');

    $this->artisan('martis:publish-assets')
        ->expectsOutputToContain('martis.theme.name "my theme" is not a theme name the panel loads')
        ->assertSuccessful();
});

it('says nothing about martis.theme.name when its source exists or it is unset', function () {
    themeSafetySource('brand.css', ':root {}');

    config()->set('martis.theme.name', 'brand');
    $this->artisan('martis:publish-assets')
        ->doesntExpectOutputToContain('martis.theme.name')
        ->assertSuccessful();

    config()->set('martis.theme.name', null);
    $this->artisan('martis:publish-assets')
        ->doesntExpectOutputToContain('martis.theme.name')
        ->assertSuccessful();
});

// ---------------------------------------------------------------------------
// Sources skipped with a warning, symlinks, case, write failures
// ---------------------------------------------------------------------------

it('warns about a theme source whose .css extension is not in lowercase', function () {
    themeSafetySource('Brand.CSS', ':root {}');

    $this->artisan('martis:publish-assets')
        ->expectsOutputToContain('Skipped resources/css/martis/Brand.CSS: a theme source ends in .css, in lowercase')
        ->assertSuccessful();

    expect(public_path('vendor/martis/themes/Brand.css'))->not->toBeFile();
});

it('replaces a published copy that is a symlink to its own source', function (array $options) {
    // copy() onto a link to the source itself returned false and failed the run.
    themeSafetySource('brand.css', ':root { --martis-accent: #123456; }');
    (new Filesystem)->ensureDirectoryExists(public_path('vendor/martis/themes'));
    symlink(resource_path('css/martis/brand.css'), public_path('vendor/martis/themes/brand.css'));

    $this->artisan('martis:publish-assets', $options)
        ->expectsOutputToContain('public/vendor/martis/themes/brand.css is a symlink')
        ->assertSuccessful();

    $published = public_path('vendor/martis/themes/brand.css');
    expect(is_link($published))->toBeFalse()
        ->and(file_get_contents($published))->toBe(':root { --martis-accent: #123456; }')
        ->and(file_get_contents(resource_path('css/martis/brand.css')))->toBe(':root { --martis-accent: #123456; }');
    expect(themeBackups())->toBe([]);
})->with([
    '--themes-only' => [['--themes-only' => true]],
    '--no-wipe' => [['--no-wipe' => true]],
]);

it('removes a broken symlink in the themes directory without failing', function (array $options) {
    (new Filesystem)->ensureDirectoryExists(public_path('vendor/martis/themes'));
    symlink(public_path('vendor/martis/themes/missing-target.css'), public_path('vendor/martis/themes/dangling.css'));

    $this->artisan('martis:publish-assets', $options)->assertSuccessful();

    expect(is_link(public_path('vendor/martis/themes/dangling.css')))->toBeFalse();
    expect(themeBackups())->toBe([]);
})->with([
    'full publish' => [[]],
    '--themes-only' => [['--themes-only' => true]],
]);

it('keeps the copy it publishes when a published name differs from its source only in case', function () {
    themeSafetySource('brand.css', 'source');
    themeSafetyPublished('Brand.css', 'old');
    $caseSensitive = themeSafetyIsCaseSensitive();

    $this->artisan('martis:publish-assets', ['--themes-only' => true])->assertSuccessful();

    // Either way the panel gets the source at themes/brand.css.
    expect(public_path('vendor/martis/themes/brand.css'))->toBeFile()
        ->and(file_get_contents(public_path('vendor/martis/themes/brand.css')))->toBe('source');

    if ($caseSensitive) {
        // Two files: Brand.css has no source, so it is backed up and removed.
        expect(themeSafetyPublishedNames())->toBe(['brand.css']);
        expect(array_values(themeBackups()))->toBe(['old']);
    }
});

it('fails naming the theme when its copy cannot be written', function () {
    themeSafetySource('brand.css', ':root {}');
    // A directory where the published copy has to go; --no-wipe keeps it.
    (new Filesystem)->ensureDirectoryExists(public_path('vendor/martis/themes/brand.css'));

    $this->artisan('martis:publish-assets', ['--no-wipe' => true])
        ->expectsOutputToContain('Could not publish resources/css/martis/brand.css')
        ->assertFailed();
});

it('warns, and still succeeds, when the publish record cannot be written', function () {
    // Without the record the next publish only backs up more; the run's
    // copies are in place, so this is not a failure.
    themeSafetySource('brand.css', ':root {}');
    (new Filesystem)->ensureDirectoryExists(storage_path('app/martis/published-themes.json'));

    $this->artisan('martis:publish-assets', ['--themes-only' => true])
        ->expectsOutputToContain('Could not write storage/app/martis/published-themes.json')
        ->assertSuccessful();

    expect(public_path('vendor/martis/themes/brand.css'))->toBeFile();
});

// ---------------------------------------------------------------------------
// --themes-only
// ---------------------------------------------------------------------------

it('--themes-only publishes the theme sources without touching the package assets', function () {
    $stale = themeSafetyStaleChunk();
    themeSafetySource('brand.css', ':root { --martis-accent: #123456; }');

    $this->artisan('martis:publish-assets', ['--themes-only' => true])
        ->doesntExpectOutputToContain('Wiping')
        ->doesntExpectOutputToContain('Copying assets')
        ->expectsOutputToContain('Published theme brand')
        ->assertSuccessful();

    expect($stale)->toBeFile();
    expect(file_get_contents(public_path('vendor/martis/themes/brand.css')))
        ->toBe(':root { --martis-accent: #123456; }');
});

it('--themes-only removes a published theme without a source, after backing it up', function () {
    themeSafetyPublished('legacy.css', 'legacy');

    $this->artisan('martis:publish-assets', ['--themes-only' => true])
        ->expectsOutputToContain('public/vendor/martis/themes/legacy.css has no source in resources/css/martis/')
        ->assertSuccessful();

    expect(public_path('vendor/martis/themes/legacy.css'))->not->toBeFile();
    expect(array_values(themeBackups()))->toBe(['legacy']);
});

it('--themes-only --no-wipe keeps a published theme without a source', function () {
    themeSafetyPublished('legacy.css', 'legacy');

    $this->artisan('martis:publish-assets', ['--themes-only' => true, '--no-wipe' => true])
        ->assertSuccessful();

    expect(public_path('vendor/martis/themes/legacy.css'))->toBeFile();
    expect(themeBackups())->toBe([]);
});

it('--themes-only backs up an edited published copy before replacing it', function () {
    themeSafetySource('brand.css', 'source');
    themeSafetyPublished('brand.css', 'edited');

    $this->artisan('martis:publish-assets', ['--themes-only' => true])->assertSuccessful();

    expect(file_get_contents(public_path('vendor/martis/themes/brand.css')))->toBe('source');
    expect(array_values(themeBackups()))->toBe(['edited']);
});

it('--themes-only runs the edit loop without backups', function () {
    themeSafetySource('brand.css', 'first');
    $this->artisan('martis:publish-assets', ['--themes-only' => true])->assertSuccessful();

    themeSafetySource('brand.css', 'second');
    $this->artisan('martis:publish-assets', ['--themes-only' => true])
        ->doesntExpectOutputToContain('Backed up')
        ->assertSuccessful();

    expect(file_get_contents(public_path('vendor/martis/themes/brand.css')))->toBe('second');
    expect(themeBackups())->toBe([]);
});
