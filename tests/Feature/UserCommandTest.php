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
function userCommandOnTerminal(bool $tty, array $options): UserCommand
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
