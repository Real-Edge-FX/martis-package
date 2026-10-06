<?php

namespace Martis\Tests;

use FilesystemIterator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Martis\MartisServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use RuntimeException;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    /**
     * Do not load the `.env` of the testbench skeleton the tests run in.
     *
     * The skeleton lives under vendor/ and keeps what every run leaves in it.
     * `vendor/bin/testbench` (a Testbench application like the one
     * StdioTransportTest spawns through tests/Support/mcp-artisan.php) puts
     * a `.env` there while it runs (a copy of the package root's `.env`,
     * `.env.example` or `.env.dist` when there is one, otherwise of the
     * skeleton's `.env.example`) and deletes it only when it exits cleanly,
     * so a killed subprocess leaves it behind. Loaded into the test application, its
     * `CACHE_STORE=database` and `SESSION_DRIVER=cookie` break every test
     * that touches the cache or the session (the test database has no
     * `cache` table, see migrateFreshUsing()). CI installs a fresh vendor/
     * on every run and never has the file, so the tests run on the
     * skeleton's config defaults there; ignoring the file does the same
     * locally. The skeleton copy the tests run in (applicationBasePath())
     * leaves the file out as well; this keeps any other `.env` off too.
     *
     * @var bool
     */
    protected $loadEnvironmentVariables = false;

    /**
     * The copy of the testbench skeleton this test process runs in.
     */
    private static ?string $workerSkeleton = null;

    /**
     * Run every test process in a copy of the testbench skeleton of its own.
     *
     * The skeleton lives under vendor/, and the suite writes into it:
     * martis:install publishes assets, config, translations and migrations,
     * the generators write app/Martis classes, scaffolding tests replace
     * app/Providers and bootstrap/providers.php, and `vendor/bin/testbench`
     * leaves a `.env`. Written in place, all of it stayed for every later
     * run (a leftover `.env` failed about a thousand tests locally, while
     * CI, with a fresh vendor/, passed), and under `pest --parallel` one
     * worker read the files another was writing or deleting. The first test
     * of a process copies the skeleton to a temporary directory named after
     * the process and the paratest token (TEST_TOKEN, `seq` for a sequential
     * run), and every application of that process boots there, so the
     * skeleton under vendor/ is never written. The copy goes when the
     * process exits; one whose process could not clean up (Ctrl+C, SIGKILL)
     * goes when the next copy is made.
     *
     * The copy is PHP the test process loads, and the sweep deletes by name,
     * so they live in a directory only the current user can write
     * (prepareSkeletonRoot()), never in one another user of a shared temp
     * directory could have made first, and the sweep never follows a symlink.
     */
    public static function applicationBasePath()
    {
        $token = getenv('TEST_TOKEN');

        return self::$workerSkeleton ??= self::copySkeletonForWorker(
            parent::applicationBasePath(),
            is_string($token) && $token !== '' ? $token : 'seq',
        );
    }

    private static function copySkeletonForWorker(string $skeleton, string $token): string
    {
        // One root per user and skeleton: the user id keeps two users of a
        // shared temp directory from ever meeting in the same directory.
        $root = sys_get_temp_dir().'/martis-testbench-'.(self::currentUserId() ?? 'user').'-'.substr(md5($skeleton), 0, 12);
        // The token is a number from paratest; it is a path component here, so
        // nothing else may get through.
        $copy = $root.'/'.getmypid().'-'.preg_replace('/[^A-Za-z0-9_.]/', '_', $token);
        $filesystem = new Filesystem;

        self::prepareSkeletonRoot($root);
        self::sweepOrphanCopies($root, $filesystem);

        // `vendor` is the symlink `vendor/bin/testbench` creates, `.env` a
        // copy it can leave behind, and the log only grows run after run.
        self::copyDirectory($skeleton, $copy, ['vendor', '.env', 'storage/logs/laravel.log']);

        // deleteDirectory() removes a symlink without following it, so the
        // vendor/ a testbench subprocess links in here is left alone. Only the
        // process that made the copy removes it (a forked child inherits this
        // function), and the parent goes once the last copy is gone.
        $owner = getmypid();

        register_shutdown_function(static function () use ($filesystem, $copy, $owner): void {
            if (getmypid() === $owner) {
                self::deleteQuietly($filesystem, $copy);
                @rmdir(dirname($copy));
            }
        });

        $path = realpath($copy);

        if ($path === false) {
            throw new RuntimeException("Cannot resolve the testbench skeleton copy {$copy}.");
        }

        return $path;
    }

    /**
     * The id of the user this process runs as, or null where it cannot be
     * told (no posix extension, Windows): the ownership checks are skipped
     * there, as there is no uid to compare.
     */
    private static function currentUserId(): ?int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }

    /**
     * Create the directory the skeleton copies live in, or vouch for the one
     * that is there: a real directory (not a symlink, which could lead
     * anywhere) owned by $uid that no other user can write into. In a shared
     * temp directory anyone can create the name first, and a directory they
     * own would let them read and replace the copy the suite boots from, or
     * plant entries for the sweep to delete; the suite refuses to start
     * instead. A missing root is created private (0700).
     *
     * @param  int|null  $uid  The user the root must belong to (the current one).
     */
    private static function prepareSkeletonRoot(string $root, ?int $uid = null): void
    {
        $uid ??= self::currentUserId();

        // A dangling symlink does not exist as far as file_exists() goes, but
        // mkdir() would not replace it either: it is a symlink, refused below.
        if (! is_link($root) && ! file_exists($root) && ! @mkdir($root, 0700) && ! is_dir($root)) {
            throw self::copyFailure("create {$root}");
        }

        // Another worker may have made it a moment ago: judge what is there.
        clearstatcache(true, $root);

        if (is_link($root)) {
            throw new RuntimeException("The testbench skeleton root {$root} is a symlink; remove it and run the tests again.");
        }

        if (! is_dir($root)) {
            throw new RuntimeException("The testbench skeleton root {$root} is not a directory; remove it and run the tests again.");
        }

        if ($uid === null) {
            return;
        }

        $owner = fileowner($root);
        $mode = fileperms($root);

        if ($owner !== $uid) {
            throw new RuntimeException("The testbench skeleton root {$root} is not owned by the current user (uid {$uid}, owner uid ".var_export($owner, true).'); remove it and run the tests again.');
        }

        // Group or world write: another user could add or swap entries.
        if ($mode === false || ($mode & 0022) !== 0) {
            throw new RuntimeException("The testbench skeleton root {$root} is writable by other users (mode ".decoct(($mode === false ? 0 : $mode) & 0777).'); remove it and run the tests again.');
        }
    }

    /**
     * Remove the copies whose process is gone: a worker killed before its
     * shutdown function ran (Ctrl+C, SIGKILL) leaves one, and after such a
     * run every new worker finds the same orphans at the same time. Each
     * orphan is first claimed with an atomic rename to a hidden name
     * carrying this process's pid, so exactly one worker deletes it; a
     * claim whose claimer died mid-delete is claimed again. A copy named
     * after this process can only be a leftover of an earlier process with
     * the same pid. A live copy, a sibling worker's or that of the process
     * this one was started from, is left alone.
     *
     * Entries are told apart without following links: a symlink is never a
     * copy this suite made, so it is unlinked (which leaves its target
     * alone) instead of being claimed and emptied.
     */
    private static function sweepOrphanCopies(string $root, Filesystem $filesystem): void
    {
        $orphans = [];

        foreach (scandir($root) ?: [] as $name) {
            $path = $root.'/'.$name;
            $isCopy = fnmatch('*-*', $name, FNM_PERIOD);
            $isClaim = fnmatch('.*.claimed-*', $name);

            if (! $isCopy && ! $isClaim) {
                continue;
            }

            if (is_link($path)) {
                @unlink($path);

                continue;
            }

            if (! is_dir($path)) {
                continue;
            }

            $orphans[] = $isCopy
                ? [$path, (int) strtok($name, '-')]
                : [$path, (int) substr((string) strrchr($path, '-'), 1)];
        }

        foreach ($orphans as [$path, $pid]) {
            if ($pid <= 0 || ($pid !== getmypid() && self::processIsRunning($pid))) {
                continue;
            }

            $claim = $root.'/.'.ltrim(strtok(basename($path), '.'), '.').'.claimed-'.getmypid();

            // Another worker won the rename: the orphan is its to delete.
            if (@rename($path, $claim)) {
                self::deleteQuietly($filesystem, $claim);
            }
        }
    }

    /**
     * Delete a directory this process owns, never failing the test run over
     * it: a copy left half-deleted is claimed and removed by a later sweep.
     * A symlink in its place is unlinked, never entered: deleteDirectory()
     * empties the target of a top-level symlink before it fails to remove it.
     */
    private static function deleteQuietly(Filesystem $filesystem, string $directory): void
    {
        try {
            if (is_link($directory)) {
                @unlink($directory);

                return;
            }

            $filesystem->deleteDirectory($directory);
        } catch (\Throwable) {
            // Left for the next sweep.
        }
    }

    /**
     * Whether a process exists (EPERM: it does, under another user). Without
     * the posix extension every process counts as running, so no copy is
     * ever taken for a leftover.
     */
    private static function processIsRunning(int $pid): bool
    {
        if (! function_exists('posix_kill')) {
            return true;
        }

        return posix_kill($pid, 0) || posix_get_last_error() === 1;
    }

    /**
     * Copy a directory tree, recreating symlinks instead of following them
     * and keeping each file's modification time: Blade compares a compiled
     * view's time with its source's, so a copy stamped "now" would pass a
     * stale compiled view off as current.
     *
     * @param  list<string>  $skip  Paths relative to $from.
     */
    private static function copyDirectory(string $from, string $to, array $skip, string $relative = ''): void
    {
        if (! is_dir($to) && ! @mkdir($to, 0777, true) && ! is_dir($to)) {
            throw self::copyFailure("create {$to}");
        }

        foreach (new FilesystemIterator($from, FilesystemIterator::SKIP_DOTS) as $item) {
            $path = ltrim($relative.'/'.$item->getFilename(), '/');
            $source = $item->getPathname();
            $target = $to.'/'.$item->getFilename();

            if (in_array($path, $skip, true)) {
                continue;
            }

            if ($item->isLink()) {
                $link = @readlink($source);

                if ($link === false || ! @symlink($link, $target)) {
                    throw self::copyFailure("link {$target} like {$source}");
                }
            } elseif ($item->isDir()) {
                self::copyDirectory($source, $target, $skip, $path);
            } elseif (! @copy($source, $target) || ! @touch($target, $item->getMTime())) {
                throw self::copyFailure("copy {$source} to {$target}");
            }
        }
    }

    private static function copyFailure(string $action): RuntimeException
    {
        return new RuntimeException("Cannot {$action} for the testbench skeleton copy: "
            .(error_get_last()['message'] ?? 'unknown error'));
    }

    protected function getPackageProviders($app): array
    {
        return [MartisServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('martis.middleware', ['web']);
        // Never let Laravel's session garbage collection run inside a test:
        // with the database driver it deletes, on 2 requests in 100, the
        // session fixtures whose last_activity is a small number (1970).
        $app['config']->set('session.lottery', [0, 100]);

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // The drivers the suite assumes. Without a `.env` they are the
        // skeleton's config defaults, but a variable exported in the shell
        // (APP_DEBUG, CACHE_STORE, SESSION_DRIVER, QUEUE_CONNECTION) still
        // reaches the config.
        $app['config']->set('app.debug', false);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('queue.default', 'sync');
    }

    /**
     * Point RefreshDatabase's `migrate:fresh` at an empty folder, so it
     * never scans the skeleton's `database/migrations/`. `martis:install`
     * publishes Martis migrations into that folder, and they stay there for
     * the tests that follow (and, in the shared skeleton, for later runs):
     * `migrate:fresh` ran them and crashed with "no such table users".
     *
     * Each test therefore starts from an empty in-memory database: the
     * `migrations` table plus the `martis_cache_state` table that
     * afterRefreshingDatabase() adds. A test creates the tables it needs.
     * Loading Laravel's migrations before the refresh does not provide
     * them: `migrate:fresh` drops every table first.
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--drop-views' => false,
            '--drop-types' => false,
            '--seed' => false,
            '--path' => __DIR__.'/migrations-empty',
            '--realpath' => true,
        ];
    }

    /**
     * Add the table every test's database starts with.
     */
    protected function afterRefreshingDatabase(): void
    {
        // v1.8.8 — operational metadata for the cache subsystem. Every
        // suite that exercises MartisCache writes to this table, so it
        // is part of the standard fixture.
        if (! Schema::hasTable('martis_cache_state')) {
            Schema::create('martis_cache_state', function (Blueprint $table) {
                $table->string('type')->primary();
                $table->unsignedInteger('version')->default(1);
                $table->timestamp('cleared_at')->nullable();
                $table->boolean('override')->nullable();
                $table->timestamps();
            });
        }
    }
}
