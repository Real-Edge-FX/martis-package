<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Martis\Console\UserCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// Local user model bound to the `users` table. The base TestCase's
// custom `migrateFreshUsing` intentionally skips the testbench
// `database/migrations/` folder (parallel-safe), so every DB suite
// bootstraps the schema it needs — mirroring NotificationControllerTest.
class UserCommandTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    // martis:user resolves the user model from this config key.
    config(['auth.providers.users.model' => UserCommandTestUser::class]);
});

it('martis:user creates a user and reports success', function () {
    $this->artisan('martis:user', [
        '--name' => 'Test Admin',
        '--email' => 'admin@example.com',
        '--password' => 'secret123',
    ])
        ->expectsOutputToContain('admin@example.com')
        ->assertSuccessful();

    $user = UserCommandTestUser::query()->where('email', 'admin@example.com')->first();

    expect($user)->not->toBeNull();
    expect(Hash::check('secret123', (string) $user->password))->toBeTrue();
});

it('martis:user stores the password hashed, never in plaintext', function () {
    $this->artisan('martis:user', [
        '--name' => 'Test Admin',
        '--email' => 'hash@example.com',
        '--password' => 'secret123',
    ])->assertSuccessful();

    $stored = (string) UserCommandTestUser::query()->where('email', 'hash@example.com')->value('password');

    expect($stored)->not->toBe('secret123')
        ->and(Hash::check('secret123', $stored))->toBeTrue();
});

it('martis:user fails with a friendly error when the email already exists', function () {
    UserCommandTestUser::create([
        'name' => 'Existing User',
        'email' => 'duplicate@example.com',
        'password' => Hash::make('whatever'),
    ]);

    $this->artisan('martis:user', [
        '--name' => 'Test Admin',
        '--email' => 'duplicate@example.com',
        '--password' => 'secret123',
    ])
        ->expectsOutputToContain('duplicate@example.com')
        ->assertFailed();
});

it('martis:user fails when the password is empty', function () {
    $this->artisan('martis:user', [
        '--name' => 'Test Admin',
        '--email' => 'admin@example.com',
        '--password' => '',
    ])
        ->expectsOutputToContain('Password cannot be empty')
        ->assertFailed();
});

/*
 * martis:user asks only on a terminal (AsksOnlyOnATerminal). It asked on
 * isInteractive() alone, so `yes | php artisan martis:user` made an admin
 * with email, name and password "y", already verified. Without a terminal
 * the email and the password must come as options (exit 1 naming the
 * missing one, no user written); the name defaults to "Martis Admin".
 */
function userCommandOnTerminal(bool $tty, array $options, ?string $stdin = null): UserCommand
{
    $command = new class($tty) extends UserCommand
    {
        /** @var list<string> */
        public array $asked = [];

        public ?BufferedOutput $buffer = null;

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

            return $question === 'Email' ? 'asked@example.com' : 'Asked Admin';
        }

        public function secret($question, $fallback = true)
        {
            $this->asked[] = $question;

            return 'asked-secret';
        }
    };
    $command->setLaravel(app());
    $input = new ArrayInput($options, $command->getDefinition());
    $input->setInteractive(true);
    if ($stdin !== null) {
        // What a pipe would send: the stream Symfony reads input from.
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $stdin);
        rewind($stream);
        $input->setStream($stream);
    }
    $buffer = new BufferedOutput;
    (function () use ($input, $buffer) {
        $this->input = $input;
        $this->output = new OutputStyle($input, $buffer);
        $this->components = new Factory($this->output);
    })->call($command);
    $command->buffer = $buffer;

    return $command;
}

it('refuses without a TTY, naming the missing option, and creates no user', function (array $options, string $missing) {
    $command = userCommandOnTerminal(false, $options);

    expect($command->handle())->toBe(1)
        ->and($command->asked)->toBe([])
        ->and($command->buffer->fetch())->toContain("The --{$missing} option is required when the command runs without a terminal")
        ->and(UserCommandTestUser::query()->count())->toBe(0);
})->with([
    'no email' => [['--password' => 'secret123'], 'email'],
    'no password' => [['--email' => 'piped@example.com'], 'password'],
    'neither' => [[], 'email'],
]);

it('refuses to update an existing user without a TTY when the password is missing', function () {
    UserCommandTestUser::query()->create(['name' => 'Kept', 'email' => 'kept@example.com', 'password' => Hash::make('old-secret')]);
    $command = userCommandOnTerminal(false, ['--email' => 'kept@example.com', '--update' => true]);

    expect($command->handle())->toBe(1)
        ->and($command->asked)->toBe([])
        ->and(Hash::check('old-secret', (string) UserCommandTestUser::query()->first()->password))->toBeTrue();
});

it('creates the user from the options without a TTY, named "Martis Admin" by default', function () {
    $command = userCommandOnTerminal(false, ['--email' => 'piped@example.com', '--password' => 'secret123']);

    expect($command->handle())->toBe(0)->and($command->asked)->toBe([]);
    $user = UserCommandTestUser::query()->where('email', 'piped@example.com')->first();
    expect($user?->name)->toBe('Martis Admin')
        ->and(Hash::check('secret123', (string) $user?->password))->toBeTrue();
});

it('asks for the email, the name and the password on a TTY (control)', function () {
    $command = userCommandOnTerminal(true, []);

    expect($command->handle())->toBe(0)
        ->and($command->asked)->toBe(['Email', 'Name', 'Password']);
    $user = UserCommandTestUser::query()->where('email', 'asked@example.com')->first();
    expect($user?->name)->toBe('Asked Admin')
        ->and(Hash::check('asked-secret', (string) $user?->password))->toBeTrue();
});

/*
 * --password-stdin (v2.3.0): without a terminal the password could only come
 * from --password, where `ps` shows it to every local user for the life of
 * the command. The flag reads the first line of standard input instead, as
 * `docker login --password-stdin` does; the password is validated with the
 * app's Password::defaults(), as nova:user validates it.
 */
it('creates the user from the first line of standard input', function (string $stdin) {
    $command = userCommandOnTerminal(false, ['--email' => 'stdin@example.com', '--password-stdin' => true], $stdin);

    expect($command->handle())->toBe(0)->and($command->asked)->toBe([]);
    $user = UserCommandTestUser::query()->where('email', 'stdin@example.com')->first();
    expect(Hash::check('Stdin-Secret-1', (string) $user?->password))->toBeTrue();
})->with([
    'LF' => ["Stdin-Secret-1\n"],
    'CRLF' => ["Stdin-Secret-1\r\n"],
    'no line ending' => ['Stdin-Secret-1'],
    'more lines' => ["Stdin-Secret-1\nnot the password\n"],
]);

it('refuses an empty line on standard input and creates no user', function () {
    $command = userCommandOnTerminal(false, ['--email' => 'empty@example.com', '--password-stdin' => true], "\n");

    expect($command->handle())->toBe(1)
        ->and($command->buffer->fetch())->toContain('Password cannot be empty.')
        ->and(UserCommandTestUser::query()->count())->toBe(0);
});

it('refuses --password and --password-stdin together', function () {
    $command = userCommandOnTerminal(false, ['--email' => 'both@example.com', '--password' => 'Option-Secret-1', '--password-stdin' => true], "Stdin-Secret-1\n");

    expect($command->handle())->toBe(1)
        ->and($command->buffer->fetch())->toContain('Pass either --password or --password-stdin, not both.')
        ->and(UserCommandTestUser::query()->count())->toBe(0);
});

it('updates an existing user from standard input', function () {
    UserCommandTestUser::query()->create(['name' => 'Kept', 'email' => 'kept@example.com', 'password' => Hash::make('old-secret')]);
    $command = userCommandOnTerminal(false, ['--email' => 'kept@example.com', '--password-stdin' => true, '--update' => true], "Rotated-Secret-1\n");

    expect($command->handle())->toBe(0)
        ->and(Hash::check('Rotated-Secret-1', (string) UserCommandTestUser::query()->first()?->password))->toBeTrue();
});

it('leaves an existing user alone with --if-missing, without reading the input', function () {
    UserCommandTestUser::query()->create(['name' => 'Kept', 'email' => 'kept@example.com', 'password' => Hash::make('old-secret')]);
    $command = userCommandOnTerminal(false, ['--email' => 'kept@example.com', '--password-stdin' => true, '--if-missing' => true], "\n");

    expect($command->handle())->toBe(0)
        ->and(Hash::check('old-secret', (string) UserCommandTestUser::query()->first()?->password))->toBeTrue();
});

it('validates the password with the app policy, as nova:user does', function () {
    $command = userCommandOnTerminal(false, ['--email' => 'weak@example.com', '--password-stdin' => true], "short\n");

    expect($command->handle())->toBe(1)
        ->and($command->buffer->fetch())->toContain('at least 8 characters')
        ->and(UserCommandTestUser::query()->count())->toBe(0);
});

it('names --password-stdin when a terminal-less run has no password', function () {
    $command = userCommandOnTerminal(false, ['--email' => 'piped@example.com']);

    expect($command->handle())->toBe(1)
        ->and($command->buffer->fetch())->toContain('--password-stdin');
});

/*
 * What --password-stdin must refuse rather than store. On a terminal it would
 * echo the password as it is typed (the prompt hides it). From a descriptor
 * that is not a pipe of text, PHP can read unrelated bytes: with fd 0 closed,
 * PHP on macOS reads 408 bytes of random data, and the command then created
 * the user, or with --update replaced an administrator's password, with a
 * password nobody knows, and exited 0.
 */
it('refuses --password-stdin on a terminal, without reading it', function () {
    $command = userCommandOnTerminal(true, ['--email' => 'tty@example.com', '--name' => 'Tty', '--password-stdin' => true]);

    expect($command->handle())->toBe(1)
        ->and($command->buffer->fetch())->toContain('--password-stdin reads a pipe')
        ->and($command->asked)->not->toContain('Password')
        ->and(UserCommandTestUser::query()->count())->toBe(0);
});

it('refuses a line from standard input that is not text, and changes no user', function (string $stdin) {
    UserCommandTestUser::query()->create(['name' => 'Kept', 'email' => 'kept@example.com', 'password' => Hash::make('old-secret')]);
    $command = userCommandOnTerminal(false, ['--email' => 'kept@example.com', '--password-stdin' => true, '--update' => true], $stdin);

    expect($command->handle())->toBe(1)
        ->and($command->buffer->fetch())->toContain('--password-stdin read a line that is not text')
        ->and(Hash::check('old-secret', (string) UserCommandTestUser::query()->first()?->password))->toBeTrue();
})->with([
    'random bytes, as a closed descriptor gives' => ["\x16\x7b\xbd\x4c\xfb\x13\xde\xe9\x99\x59\x4f\xb5\x00\x66\x04\x1e\x41\xd4\xd3\x7b\x01\xa1\x0b\x9e\x8f\x2d\x77\xc0\x5e\x31"],
    'invalid UTF-8' => ["Secret-Pass-\xff\xfe1\n"],
    'NUL' => ["Secret-\x00Pass-1\n"],
    'escape' => ["Secret-\x1bPass-1\n"],
    'tab' => ["Secret-\tPass-1\n"],
    'DEL' => ["Secret-Pass-1\x7f\n"],
    'C1 control' => ["Secret-Pass-1\u{85}\n"],
    'a carriage return left before the line ending' => ["Secret-Pass-1\r\r\n"],
]);

it('keeps spaces and non-ASCII letters that are part of a password from standard input (control)', function () {
    $command = userCommandOnTerminal(false, ['--email' => 'text@example.com', '--password-stdin' => true], " Pässwörd Ünï 1 \r\n");

    expect($command->handle())->toBe(0);
    $user = UserCommandTestUser::query()->where('email', 'text@example.com')->first();
    expect(Hash::check(' Pässwörd Ünï 1 ', (string) $user?->password))->toBeTrue();
});

/*
 * --password is deprecated (F119): it still works, but a script that uses it
 * puts the credential in argv, where every local user reads it through the
 * process list (`ps`, /proc/<pid>/cmdline) and where shell history and CI
 * logs keep it. The command warns and points at --password-stdin.
 */
it('still creates the user from --password, and warns that argv is visible to other local users', function () {
    $command = userCommandOnTerminal(false, ['--email' => 'argv@example.com', '--password' => 'Option-Secret-1']);

    expect($command->handle())->toBe(0);

    $output = $command->buffer->fetch();
    expect($output)
        ->toContain('--password is deprecated')
        ->toContain('process list')
        ->toContain('other local users')
        ->toContain('--password-stdin')
        // The warning never echoes the password it warns about.
        ->not->toContain('Option-Secret-1');

    $user = UserCommandTestUser::query()->where('email', 'argv@example.com')->first();
    expect(Hash::check('Option-Secret-1', (string) $user?->password))->toBeTrue();
});

it('warns about --password on an update too', function () {
    UserCommandTestUser::query()->create(['name' => 'Kept', 'email' => 'kept@example.com', 'password' => Hash::make('old-secret')]);
    $command = userCommandOnTerminal(false, ['--email' => 'kept@example.com', '--password' => 'Rotated-Secret-1', '--update' => true]);

    expect($command->handle())->toBe(0)
        ->and($command->buffer->fetch())->toContain('--password is deprecated');
});

it('does not warn when the password comes from standard input or the prompt', function () {
    $piped = userCommandOnTerminal(false, ['--email' => 'stdin@example.com', '--password-stdin' => true], "Stdin-Secret-1\n");
    expect($piped->handle())->toBe(0)
        ->and($piped->buffer->fetch())->not->toContain('deprecated');

    $prompted = userCommandOnTerminal(true, []);
    expect($prompted->handle())->toBe(0)
        ->and($prompted->buffer->fetch())->not->toContain('deprecated');
});

it('does not warn about --password when it is empty, and still refuses it', function () {
    $command = userCommandOnTerminal(false, ['--email' => 'empty@example.com', '--password' => '']);

    expect($command->handle())->toBe(1)
        ->and($command->buffer->fetch())->toContain('Password cannot be empty.')
        ->and(UserCommandTestUser::query()->count())->toBe(0);
});

it('marks --password as deprecated in the command help', function () {
    $help = (new UserCommand)->getDefinition()->getOption('password')->getDescription();

    expect($help)->toContain('Deprecated')->toContain('--password-stdin');
});
