<?php

use Illuminate\Filesystem\Filesystem;

/*
 * martis:publish-assets makes public/vendor/martis/themes/ match the theme
 * sources in resources/css/martis/, so it deletes and overwrites files there.
 * It must never do that silently: every file it is about to destroy that it
 * cannot write back from a source is copied to
 * storage/app/martis/theme-backups/<run>/ first, with a warning saying what
 * to do. A copy the command published itself, or one identical to its
 * source, is replaced without a backup: the edit-and-publish loop would
 * otherwise back up the previous publish on every run.
 */

function themeSafetyReset(): void
{
    $fs = new Filesystem;
    $fs->deleteDirectory(resource_path('css/martis'));
    $fs->deleteDirectory(public_path('vendor/martis/themes'));

    removeThemeBackups();
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
    // The 1.x case: the theme only exists as the published copy the old
    // martis:theme hint said to edit.
    themeSafetyPublished('legacy.css', ':root { --martis-accent: #abcdef; }');

    $this->artisan('martis:publish-assets')
        ->expectsOutputToContain('public/vendor/martis/themes/legacy.css has no source in resources/css/martis/')
        ->expectsOutputToContain('Backed up to storage/app/martis/theme-backups/')
        ->assertSuccessful();

    expect(public_path('vendor/martis/themes/legacy.css'))->not->toBeFile();

    $backups = themeBackups();
    expect($backups)->toHaveCount(1)
        ->and((string) array_key_first($backups))->toEndWith('/legacy.css')
        ->and(array_values($backups))->toBe([':root { --martis-accent: #abcdef; }']);
});

it('backs up a published copy edited in place before replacing it with its source', function () {
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
    expect(array_values(themeBackups()))
        ->toBe([':root { --martis-accent: #abcdef; }']);
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
        ->and((string) array_key_first($backups))->toEndWith('/fonts/brand.woff2')
        ->and(array_values($backups))->toBe(['font-bytes']);
});

it('backs up through martis:vendor-publish --assets as well', function () {
    themeSafetyPublished('legacy.css', ':root { --martis-accent: #abcdef; }');

    $this->artisan('martis:vendor-publish', ['--assets' => true])->assertSuccessful();

    expect(array_values(themeBackups()))
        ->toBe([':root { --martis-accent: #abcdef; }']);
});

it('stops before deleting anything when a backup cannot be written', function () {
    $fs = new Filesystem;
    themeSafetyPublished('legacy.css', ':root {}');
    // A file where the backup directory has to go.
    $fs->ensureDirectoryExists(storage_path('app/martis'));
    $fs->put(storage_path('app/martis/theme-backups'), 'not a directory');
    $stale = public_path('vendor/martis/assets/StaleBackupFailure.es-0BAC0FF0.js');
    $fs->ensureDirectoryExists(dirname($stale));
    $fs->put($stale, '// stale chunk');

    try {
        $this->artisan('martis:publish-assets')
            ->expectsOutputToContain('Could not back up public/vendor/martis/themes/legacy.css')
            ->assertFailed();

        expect(public_path('vendor/martis/themes/legacy.css'))->toBeFile();
        // The wipe never ran.
        expect($stale)->toBeFile();
    } finally {
        $fs->delete($stale);
    }
});

it('gives every run its own backup directory', function () {
    $this->travelTo(now()->startOfSecond());

    themeSafetyPublished('legacy.css', 'first');
    $this->artisan('martis:publish-assets', ['--themes-only' => true])->assertSuccessful();

    themeSafetyPublished('legacy.css', 'second');
    $this->artisan('martis:publish-assets', ['--themes-only' => true])->assertSuccessful();

    $backups = themeBackups();
    $paths = array_keys($backups);
    expect($backups)->toHaveCount(2)
        ->and(dirname($paths[0]))->not->toBe(dirname($paths[1]))
        ->and(array_values($backups))->toEqualCanonicalizing(['first', 'second']);
});

it('treats an unreadable publish record as absent', function () {
    themeSafetySource('brand.css', 'source');
    themeSafetyPublished('brand.css', 'edited');
    themeSafetyPublished('.published.json', '{not json');

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

it('records what it published without treating the record as a theme', function () {
    themeSafetySource('brand.css', ':root {}');

    $this->artisan('martis:publish-assets')->assertSuccessful();

    $record = public_path('vendor/martis/themes/.published.json');
    expect($record)->toBeFile();
    expect(json_decode((string) file_get_contents($record), true)['themes'] ?? null)
        ->toBe(['brand' => sha1(':root {}')]);

    $this->artisan('martis:publish-assets')
        ->doesntExpectOutputToContain('.published.json')
        ->assertSuccessful();

    expect(themeBackups())->toBe([]);
});

// ---------------------------------------------------------------------------
// martis.theme.name
// ---------------------------------------------------------------------------

it('warns when martis.theme.name has no theme source', function () {
    themeSafetyPublished('legacy.css', ':root {}');
    config()->set('martis.theme.name', 'legacy');

    $this->artisan('martis:publish-assets')
        ->expectsOutputToContain('martis.theme.name is "legacy", but resources/css/martis/legacy.css is not a theme source')
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
// Theme sources the publish cannot use
// ---------------------------------------------------------------------------

it('skips a broken symlink in resources/css/martis instead of crashing', function () {
    themeSafetySource('brand.css', ':root {}');
    symlink(resource_path('css/martis/missing-target.css'), resource_path('css/martis/broken.css'));

    $this->artisan('martis:publish-assets')
        ->expectsOutputToContain('Skipped resources/css/martis/broken.css: it is not a readable file')
        ->assertSuccessful();

    expect(public_path('vendor/martis/themes/brand.css'))->toBeFile();
    expect(public_path('vendor/martis/themes/broken.css'))->not->toBeFile();
});

it('skips a theme source it cannot read', function () {
    themeSafetySource('brand.css', ':root {}');
    themeSafetySource('locked.css', ':root {}');
    $locked = resource_path('css/martis/locked.css');
    chmod($locked, 0000);

    // Each artisan() call runs when its statement ends, before the finally
    // block makes the file readable again. Whether the file still opens
    // depends on the environment: root reads it on a native filesystem,
    // while a bind mount can refuse root the read that is_readable() allows.
    // The probe asks the question the command asks, by opening it.
    try {
        $handle = @fopen($locked, 'rb');
        $opens = $handle !== false;
        if ($handle !== false) {
            fclose($handle);
        }

        if ($opens) {
            // The mode does not stop the read, so the theme is published.
            $this->artisan('martis:publish-assets')->assertSuccessful();
            expect(public_path('vendor/martis/themes/locked.css'))->toBeFile();
        } else {
            $this->artisan('martis:publish-assets')
                ->expectsOutputToContain('Skipped resources/css/martis/locked.css: it is not a readable file')
                ->assertSuccessful();
            expect(public_path('vendor/martis/themes/locked.css'))->not->toBeFile();
        }

        expect(public_path('vendor/martis/themes/brand.css'))->toBeFile();
    } finally {
        chmod($locked, 0644);
    }
});

it('warns about a theme source whose .css extension is not in lowercase', function () {
    themeSafetySource('Brand.CSS', ':root {}');

    $this->artisan('martis:publish-assets')
        ->expectsOutputToContain('Skipped resources/css/martis/Brand.CSS: a theme source ends in .css, in lowercase')
        ->assertSuccessful();

    expect(public_path('vendor/martis/themes/Brand.css'))->not->toBeFile();
});

it('warns, and still succeeds, when the publish record cannot be written', function () {
    // Without the record the next publish only backs up more; the run's
    // copies are in place, so this is not a failure.
    themeSafetySource('brand.css', ':root {}');
    (new Filesystem)->ensureDirectoryExists(public_path('vendor/martis/themes/.published.json'));

    $this->artisan('martis:publish-assets', ['--themes-only' => true])
        ->expectsOutputToContain('Could not write public/vendor/martis/themes/.published.json')
        ->assertSuccessful();

    expect(public_path('vendor/martis/themes/brand.css'))->toBeFile();
});

it('fails naming the theme when its copy cannot be written', function () {
    themeSafetySource('brand.css', ':root {}');
    // A directory where the published copy has to go; --no-wipe keeps it.
    (new Filesystem)->ensureDirectoryExists(public_path('vendor/martis/themes/brand.css'));

    $this->artisan('martis:publish-assets', ['--no-wipe' => true])
        ->expectsOutputToContain('Could not publish resources/css/martis/brand.css')
        ->assertFailed();
});

// ---------------------------------------------------------------------------
// --themes-only
// ---------------------------------------------------------------------------

it('--themes-only publishes the theme sources without touching the package assets', function () {
    $fs = new Filesystem;
    $stale = public_path('vendor/martis/assets/StaleThemesOnly.es-7E3E5000.js');
    $fs->ensureDirectoryExists(dirname($stale));
    $fs->put($stale, '// stale chunk');
    themeSafetySource('brand.css', ':root { --martis-accent: #123456; }');

    try {
        $this->artisan('martis:publish-assets', ['--themes-only' => true])
            ->doesntExpectOutputToContain('Wiping')
            ->doesntExpectOutputToContain('Copying assets')
            ->expectsOutputToContain('Published theme brand')
            ->assertSuccessful();

        expect($stale)->toBeFile();
        expect(file_get_contents(public_path('vendor/martis/themes/brand.css')))
            ->toBe(':root { --martis-accent: #123456; }');
    } finally {
        $fs->delete($stale);
    }
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
