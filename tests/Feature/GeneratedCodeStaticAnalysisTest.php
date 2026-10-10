<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Martis\Tests\Support\SkeletonSnapshot;
use Martis\Tests\TestCase;
use Symfony\Component\Process\Process;

/*
 * A host that analyses its own app at PHPStan level 8 must not fail on code
 * Martis wrote for it. Every generator and scaffold writes PHP into the host
 * (actions, cards, dashboards, fields, filters, lenses, policies, resources,
 * tools, metrics, the invitations and roles scaffolds with their migrations,
 * notification and seeder), and that code is the host's to analyse from the
 * moment it lands. A stub that narrows a parameter its parent declares, a
 * constructor a `@phpstan-consistent-constructor` parent forbids, or a
 * package type the documented code cannot satisfy is invisible to the
 * package's own analysis of `src/` and surfaced only in a consumer's CI
 * (v2.10.1, from a consumer report on the published provider).
 *
 * This test runs the real commands inside testbench, copies every PHP file
 * they wrote into a temporary directory next to the minimal fixtures that
 * code needs, and runs PHPStan with Larastan, as a Laravel host does, on
 * that directory at level 8 with no baseline and no ignored errors. Without
 * Larastan, `__()` (typed `array|string|null` by Laravel) and Eloquent's
 * static forwarding (`Role::firstOrCreate()`) would fail code that is fine
 * in a host. Any error fails the test with `file:line message [identifier]`
 * per error.
 *
 * Not covered: `martis:sso` (it writes into AppServiceProvider and the
 * environment and shells out to composer unless told not to, and its only
 * generated PHP is a migration), `martis:component` and `martis:theme`
 * (they write JS/CSS, not PHP the host analyses).
 */

// The commands write into app/ and database/ of the testbench skeleton:
// capture both before the file runs and put them back exactly afterwards.
beforeAll(function () {
    $GLOBALS['__martis_generated_analysis_skeleton'] = SkeletonSnapshot::take(TestCase::applicationBasePath(), [
        'app',
        'database',
    ]);
});

afterAll(function () {
    $GLOBALS['__martis_generated_analysis_skeleton']->restore();
});

/**
 * @return list<string> Absolute paths of the PHP files below $dir.
 */
function generatedAnalysisPhpFiles(string $dir): array
{
    $found = [];

    if (! is_dir($dir)) {
        return $found;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}

/**
 * @param  array<string, string>  $files  Path relative to the analysed root => PHP source.
 */
function generatedAnalysisWrite(string $root, array $files): void
{
    foreach ($files as $relative => $source) {
        $target = $root.'/'.$relative;
        (new Filesystem)->ensureDirectoryExists(dirname($target));
        file_put_contents($target, $source);
    }
}

it('produces code that passes PHPStan level 8 in a host app', function () {
    $filesystem = new Filesystem;
    $packageRoot = dirname(__DIR__, 2);
    $work = sys_get_temp_dir().'/martis-generated-analysis-'.bin2hex(random_bytes(6));
    $analysed = $work.'/app';

    try {
        $filesystem->ensureDirectoryExists($analysed);

        // The scaffolds patch the host's User model and AuthServiceProvider
        // files and read their namespace, so the skeleton gets plain ones
        // first (the skeleton snapshot above puts them back).
        $filesystem->ensureDirectoryExists(app_path('Models'));
        $filesystem->ensureDirectoryExists(app_path('Providers'));
        $filesystem->put(app_path('Models/User.php'), <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;
}

PHP);
        $filesystem->put(app_path('Providers/AuthServiceProvider.php'), <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }
}

PHP);

        $before = [...generatedAnalysisPhpFiles(app_path()), ...generatedAnalysisPhpFiles(database_path())];

        // Every generator, with the flags that select each stub variant.
        $commands = [
            ['martis:action', ['name' => 'ProbeAction']],
            ['martis:action', ['name' => 'ProbeDestructive', '--destructive' => true]],
            ['martis:card', ['name' => 'ProbeCard']],
            ['martis:dashboard', ['name' => 'ProbeDashboard']],
            ['martis:field', ['name' => 'ProbeField']],
            ['martis:filter', ['name' => 'ProbeSelectFilter']],
            ['martis:filter', ['name' => 'ProbeBooleanFilter', '--boolean' => true]],
            ['martis:filter', ['name' => 'ProbeDateFilter', '--date' => true]],
            ['martis:lens', ['name' => 'ProbeLens']],
            ['martis:policy', ['name' => 'ProbePolicy']],
            ['martis:resource', ['name' => 'ProbeResource']],
            ['martis:tool', ['name' => 'ProbeTool']],
            ['martis:trend', ['name' => 'ProbeTrend']],
            ['martis:value', ['name' => 'ProbeValue']],
            ['martis:partition', ['name' => 'ProbePartition']],
            ['martis:progress', ['name' => 'ProbeProgress']],
            ['martis:activity-feed', ['name' => 'ProbeFeed']],
            ['martis:endpoint-table', ['name' => 'ProbeTable']],
            ['martis:invitations', ['--no-install' => true, '--no-migrate' => true]],
            ['martis:roles', ['--no-install' => true, '--no-publish-spatie' => true, '--no-migrate' => true, '--no-seed' => true]],
        ];

        foreach ($commands as [$command, $arguments]) {
            $this->artisan($command, $arguments)->assertSuccessful();
        }

        // Copy what the commands wrote, keeping the path under app/ and database/.
        $generated = 0;

        foreach ([app_path() => 'app', database_path() => 'database'] as $dir => $prefix) {
            foreach (generatedAnalysisPhpFiles($dir) as $file) {
                if (in_array($file, $before, true) && $prefix === 'database') {
                    continue;
                }

                $target = $analysed.'/'.$prefix.'/'.ltrim(substr($file, strlen($dir)), '/');
                $filesystem->ensureDirectoryExists(dirname($target));
                $filesystem->copy($file, $target);
                $generated++;
            }
        }

        expect($generated)->toBeGreaterThan(30);

        // Fixtures: only symbols the generated code needs and the testbench
        // skeleton lacks, so the analysis fails on the generated code and
        // not on a missing class of the host.
        generatedAnalysisWrite($analysed, [
            // The model `martis:resource ProbeResource` points at; the host
            // owns its models, the generator does not write one.
            'fixtures/Models/Probe.php' => <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Probe extends Model
{
}

PHP,
        ]);

        $neon = $work.'/phpstan.neon';
        file_put_contents($neon, "includes:\n    - {$packageRoot}/vendor/larastan/larastan/extension.neon\nparameters:\n    level: 8\n    paths:\n        - {$analysed}\n");

        $process = new Process(
            [PHP_BINARY, $packageRoot.'/vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '--memory-limit=1G', '-c', $neon],
            $packageRoot,
            null,
            null,
            300,
        );
        $process->run();

        /** @var array{totals?: array<string, int>, files?: array<string, array{messages: list<array{line: int|null, message: string, identifier?: string}>}>, errors?: list<string>}|null $report */
        $report = json_decode($process->getOutput(), true);

        expect($report)->toBeArray('PHPStan produced no JSON report: '.$process->getErrorOutput().$process->getOutput());

        $lines = array_map(fn (string $error): string => "(general) {$error}", $report['errors'] ?? []);

        foreach ($report['files'] ?? [] as $path => $entry) {
            foreach ($entry['messages'] as $message) {
                $lines[] = sprintf(
                    '%s:%s %s [%s]',
                    str_replace($analysed.'/', '', $path),
                    $message['line'] ?? '?',
                    $message['message'],
                    $message['identifier'] ?? '-',
                );
            }
        }

        expect($lines)->toBe([], "Generated code fails PHPStan level 8:\n".implode("\n", $lines));
    } finally {
        $filesystem->deleteDirectory($work);
    }
});
