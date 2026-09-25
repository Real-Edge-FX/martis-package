<?php

use Illuminate\Console\OutputStyle;
use Martis\Console\InstallCommand;
use Martis\Stubs\StubResolver;
use Martis\Tests\Support\SkeletonSnapshot;
use Martis\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * martis:install on a pipe and on an application's own migrations.
 *
 * The avatar column questions checked only Symfony's isInteractive(), not
 * the TTY the other prompts check: `yes | php artisan martis:install
 * --with-profile` answered "y" and published a migration adding
 * `users.y`, and `docker compose exec -T` waited forever. And --force
 * rewrote any `*_create_notifications_table.php` / `*_create_sessions_table.php`,
 * the application's own `make:notifications-table` / `make:session-table`
 * migrations included.
 */

// martis:install publishes migrations, the host provider and translations
// into the skeleton: put them back as this file found them.
beforeAll(function () {
    $GLOBALS['__martis_install_prompts_skeleton'] = SkeletonSnapshot::take(TestCase::applicationBasePath(), [
        'database/migrations',
        'app/Providers/MartisServiceProvider.php',
        'bootstrap/providers.php',
        'lang',
    ]);
});

afterAll(function () {
    $GLOBALS['__martis_install_prompts_skeleton']->restore();
});

/**
 * An install command that believes it runs outside the tests, with or
 * without a TTY, and records every question it asks (`ask()`, `confirm()`).
 */
function installCommandWithTty(bool $tty): InstallCommand
{
    $command = new class($tty) extends InstallCommand
    {
        public array $asked = [];

        public function __construct(private bool $tty)
        {
            parent::__construct();
        }

        protected function insideUnitTests(): bool
        {
            return false;
        }

        protected function stdinIsTty(): bool
        {
            return $this->tty;
        }

        public function ask($question, $default = null)
        {
            $this->asked[] = $question;

            return 'avatar_path';
        }

        public function confirm($question, $default = false)
        {
            $this->asked[] = $question;

            return true;
        }
    };
    $command->setLaravel(app());
    $input = new ArrayInput([], $command->getDefinition());
    $input->setInteractive(true);
    (fn () => $this->input = $input)->call($command);
    (fn () => $this->output = new OutputStyle($input, new BufferedOutput))->call($command);

    return $command;
}

it('takes the default avatar column without asking when stdin is not a TTY', function () {
    $command = installCommandWithTty(false);

    $column = (fn () => $this->resolveAvatarColumnFromOptionOrPrompt(null, 'profile_picture'))->call($command);

    expect($column)->toBe('profile_picture')->and($command->asked)->toBe([]);
});

it('asks for the avatar column on a TTY (control)', function () {
    $command = installCommandWithTty(true);

    $column = (fn () => $this->resolveAvatarColumnFromOptionOrPrompt(null, 'profile_picture'))->call($command);

    expect($column)->toBe('avatar_path')->and($command->asked)->toHaveCount(1);
});

it('refuses the existing-column mode without the column when stdin is not a TTY, rather than read the pipe', function () {
    $command = installCommandWithTty(false);

    expect(fn () => (fn () => $this->resolveExistingAvatarColumn(null))->call($command))
        ->toThrow(RuntimeException::class, 'The --existing-avatar-column option requires --avatar-column=<column_name> when the command runs without a terminal (no TTY or --no-interaction).');
    expect($command->asked)->toBe([]);
});

it('asks for the existing avatar column on a TTY (control)', function () {
    $command = installCommandWithTty(true);

    $column = (fn () => $this->resolveExistingAvatarColumn(null))->call($command);

    expect($column)->toBe('avatar_path')->and($command->asked)->toBe(['Which existing users table column should Martis use for avatar paths?']);
});

it('asks nothing about the features without a TTY, and leaves profile and 2FA off', function () {
    $command = installCommandWithTty(false);

    $options = (fn () => $this->resolveInstallOptions())->call($command);

    expect($command->asked)->toBe([])
        ->and($options['profile_enabled'])->toBeFalse()
        ->and($options['two_factor_enabled'])->toBeFalse();
});

it('asks about the features on a TTY (control)', function () {
    $command = installCommandWithTty(true);

    $options = (fn () => $this->resolveInstallOptions())->call($command);

    expect($command->asked)->toContain('Would you like to enable the Martis Profile feature?')
        ->and($options['profile_enabled'])->toBeTrue();
});

/** @return list<string> */
function sharedMigrationFiles(string $name): array
{
    return glob(database_path("migrations/*_{$name}.php")) ?: [];
}

afterEach(function () {
    foreach (['create_notifications_table', 'create_sessions_table'] as $name) {
        foreach (sharedMigrationFiles($name) as $file) {
            @unlink($file);
        }
    }
});

dataset('shared migrations', [
    'notifications' => ['create_notifications_table', 'create_martis_notifications_table.php.stub'],
    'sessions' => ['create_sessions_table', 'create_sessions_table.php.stub'],
]);

function writeOwnMigration(string $name, string $comment): array
{
    @mkdir(database_path('migrations'), 0777, true);
    $own = database_path("migrations/2024_01_01_000000_{$name}.php");
    $contents = "<?php\n\n// {$comment}\nreturn new class extends Illuminate\\Database\\Migrations\\Migration {};\n";
    file_put_contents($own, $contents);

    return [$own, $contents];
}

function forceInstallWithSessions(): void
{
    test()->artisan('martis:install', ['--force' => true, '--with-sessions' => true, '--no-profile' => true, '--no-2fa' => true])
        ->assertSuccessful();
}

it('leaves an application\'s own notifications and sessions migrations alone under --force', function (string $name) {
    [$own, $contents] = writeOwnMigration($name, "Generated by the application with Laravel's make command.");

    forceInstallWithSessions();

    expect(file_get_contents($own))->toBe($contents)
        ->and(sharedMigrationFiles($name))->toBe([$own]);
})->with('shared migrations');

it('leaves alone an application migration that merely mentions Martis', function (string $name) {
    // Only the sentence of the Martis stub's header marks a file as Martis's.
    [$own, $contents] = writeOwnMigration($name, 'Read by the Martis notification bell.');

    forceInstallWithSessions();

    expect(file_get_contents($own))->toBe($contents);
})->with('shared migrations');

it('still rewrites the notifications and sessions migrations Martis published under --force', function (string $name, string $stub) {
    @mkdir(database_path('migrations'), 0777, true);
    $published = database_path("migrations/2024_01_01_000000_{$name}.php");
    // The file an earlier install published, edited since.
    $real = (string) file_get_contents(StubResolver::path($stub));
    file_put_contents($published, $real."\n// An older copy.\n");

    forceInstallWithSessions();

    expect(file_get_contents($published))->toBe($real)
        ->and(sharedMigrationFiles($name))->toBe([$published]);
})->with('shared migrations');
