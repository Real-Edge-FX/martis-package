<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Martis\Console\Concerns\LoadsPackagesInstalledThisRun;

/*
 * `martis:roles` and `martis:sso` run `composer require` in a subprocess and
 * then use the package in the same PHP process. Two things were stale:
 *
 *  - the booted Composer ClassLoader: it keeps the pre-install class map and
 *    caches every class it failed to find, so re-applying the PSR-4 map to it
 *    still reported the package missing;
 *  - the application: a provider installed during the run was never
 *    registered or booted, so `vendor:publish --provider` found nothing.
 */

function freshPackageLoader(string $vendorPath): Command
{
    $command = new class($vendorPath) extends Command
    {
        use LoadsPackagesInstalledThisRun;

        protected $signature = 'martis:fresh-package-probe';

        public function __construct(private string $vendorPath)
        {
            parent::__construct();
        }

        protected function composerVendorPath(): string
        {
            return $this->vendorPath;
        }

        public function refresh(): void
        {
            $this->refreshComposerAutoloader();
        }

        public function load(string $provider): bool
        {
            return $this->loadServiceProvider($provider);
        }
    };
    $command->setLaravel(app());

    return $command;
}

beforeEach(function () {
    $this->vendor = sys_get_temp_dir().'/martis-fresh-vendor-'.bin2hex(random_bytes(6));
    $this->namespace = 'MartisFreshFixture'.bin2hex(random_bytes(6));
    $this->helper = 'martis_fresh_fixture_'.bin2hex(random_bytes(6));

    $files = new Filesystem;
    $files->ensureDirectoryExists($this->vendor.'/composer');
    $files->ensureDirectoryExists($this->vendor.'/acme/probe/src');

    // What the composer subprocess leaves on disk: the package's files and
    // the autoload maps rewritten to include it.
    $files->put($this->vendor.'/acme/probe/src/Probe.php', "<?php\nnamespace {$this->namespace};\nclass Probe {}\n");
    $files->put($this->vendor.'/acme/probe/src/Mapped.php', "<?php\nnamespace {$this->namespace};\nclass Mapped {}\n");
    $files->put($this->vendor.'/acme/probe/helpers.php', "<?php\nfunction {$this->helper}(): string { return 'loaded'; }\n");
    $files->put($this->vendor.'/composer/autoload_psr4.php', '<?php return '.var_export([
        $this->namespace.'\\' => [$this->vendor.'/acme/probe/src'],
    ], true).';');
    $files->put($this->vendor.'/composer/autoload_classmap.php', '<?php return '.var_export([
        $this->namespace.'\\Mapped' => $this->vendor.'/acme/probe/src/Mapped.php',
    ], true).';');
    $files->put($this->vendor.'/composer/autoload_files.php', '<?php return '.var_export([
        'fixture'.$this->helper => $this->vendor.'/acme/probe/helpers.php',
    ], true).';');
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->vendor);
});

it('loads a package installed after boot, even once the booted loader cached it as missing', function () {
    $probe = $this->namespace.'\\Probe';

    // Checked before the install: the booted ClassLoader now caches it as missing.
    expect(class_exists($probe))->toBeFalse();

    freshPackageLoader($this->vendor)->refresh();

    expect(class_exists($probe))->toBeTrue()
        ->and(class_exists($this->namespace.'\\Mapped'))->toBeTrue()
        ->and(function_exists($this->helper))->toBeTrue();
});

it('refreshes more than once without stacking loaders or requiring a files entry twice', function () {
    $command = freshPackageLoader($this->vendor);
    $command->refresh();
    $loaders = count(spl_autoload_functions());

    // A second require of helpers.php would redeclare the function (fatal).
    $command->refresh();

    expect(count(spl_autoload_functions()))->toBe($loaders)
        ->and(function_exists($this->helper))->toBeTrue();
});

it('does nothing when the vendor directory has no Composer autoload maps', function () {
    $loaders = count(spl_autoload_functions());

    freshPackageLoader($this->vendor.'/missing')->refresh();

    expect(count(spl_autoload_functions()))->toBe($loaders);
});

it('registers a provider the application did not boot, so its paths can be published', function () {
    $provider = 'Spatie\\Permission\\PermissionServiceProvider';
    if (! class_exists($provider)) {
        $this->markTestSkipped('spatie/laravel-permission not installed');
    }

    // The test application boots Martis only, like a host app that had
    // Spatie installed by a subprocess during the command.
    expect(app()->getProviders($provider))->toBe([]);

    $command = freshPackageLoader($this->vendor);

    expect($command->load($provider))->toBeTrue()
        ->and($command->load($provider))->toBeTrue()
        ->and(app()->getProviders($provider))->toHaveCount(1)
        ->and(ServiceProvider::pathsToPublish($provider))->not->toBe([]);
});

it('reports a provider it cannot load', function () {
    expect(freshPackageLoader($this->vendor)->load('Acme\\Missing\\PackageServiceProvider'))->toBeFalse();
});
