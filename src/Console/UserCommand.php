<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Martis\Auth\GuardCatalog;
use Martis\Auth\PasswordPolicy;
use Martis\Console\Concerns\AsksOnlyOnATerminal;
use Symfony\Component\Console\Input\StreamableInputInterface;

class UserCommand extends Command
{
    use AsksOnlyOnATerminal;

    protected $signature = 'martis:user
                            {--name= : The full name of the admin user}
                            {--email= : The email address of the admin user}
                            {--password= : The password for the admin user}
                            {--password-stdin : Read the password from the first line of standard input, so it never appears in the process list}
                            {--if-missing : Exit successfully without changes when a user with this email already exists}
                            {--update : Update the name (when given) and password of an existing user instead of failing}';

    protected $description = 'Create a new Martis admin user';

    /**
     * Handle.
     */
    public function handle(): int
    {
        // Without a terminal nothing is asked: a pipe would answer the
        // questions (`yes |` made an admin with email, name and password
        // "y"), so the email and the password must come as options.
        $email = $this->option('email');
        if (! is_string($email) || $email === '') {
            if (! $this->canPrompt()) {
                return $this->missingOption('email');
            }
            $email = (string) $this->ask('Email', 'admin@example.com');
        }

        // The users the Martis guard signs in (MARTIS_GUARD's provider, else
        // the default guard's), as Nova's nova:user creates the Nova guard's.
        $modelClass = GuardCatalog::martisUserModel();

        /** @var Model|null $existing */
        $existing = $modelClass::query()->where('email', $email)->first();

        if ($existing !== null) {
            if ($this->option('update')) {
                return $this->updateExisting($existing, $email);
            }

            if ($this->option('if-missing')) {
                $this->components->info("A user with email [{$email}] already exists. Nothing to do.");

                return self::SUCCESS;
            }

            $this->components->error("A user with email [{$email}] already exists.");

            return self::FAILURE;
        }

        $this->components->info('Creating Martis admin user...');

        $name = $this->option('name');
        if (! is_string($name) || $name === '') {
            $name = $this->canPrompt() ? (string) $this->ask('Name', 'Martis Admin') : 'Martis Admin';
        }
        $password = $this->resolvePassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $user = new $modelClass;
        $user->setAttribute('name', $name);
        $user->setAttribute('email', $email);
        $user->setAttribute('password', Hash::make($password));
        // A Martis guard's own table may have no verification column.
        if ($user->getConnection()->getSchemaBuilder()->hasColumn($user->getTable(), 'email_verified_at')) {
            $user->setAttribute('email_verified_at', now());
        }
        $user->save();

        $this->newLine();
        $this->components->info("Admin user [{$email}] created successfully.");

        return self::SUCCESS;
    }

    /**
     * `--update` path: converge an existing row to the supplied values.
     *
     * The password is always re-hashed (it is the reason a boot script
     * re-runs the command); the name only changes when `--name` was given,
     * so a rotation script that omits it never clobbers a curated name. The
     * verification timestamp is left untouched.
     */
    private function updateExisting(Model $user, string $email): int
    {
        $this->components->info('Updating Martis admin user...');

        $password = $this->resolvePassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $name = $this->option('name');

        if (is_string($name) && $name !== '') {
            $user->setAttribute('name', $name);
        }

        $user->setAttribute('password', Hash::make($password));
        $user->save();

        $this->newLine();
        $this->components->info("Admin user [{$email}] updated successfully.");

        return self::SUCCESS;
    }

    /**
     * Read the password from `--password`, from the first line of standard
     * input (`--password-stdin`), or ask for it on a terminal; then validate
     * it with the app's password policy, as nova:user does. Null (with the
     * error already printed) when it is missing, empty, given twice or too
     * weak.
     */
    private function resolvePassword(): ?string
    {
        $password = $this->option('password');
        $fromStdin = (bool) $this->option('password-stdin');

        if ($fromStdin && is_string($password)) {
            $this->components->error('Pass either --password or --password-stdin, not both.');

            return null;
        }

        if ($fromStdin) {
            $password = $this->readPasswordFromStdin();

            if ($password === null) {
                return null;
            }
        } elseif (! is_string($password)) {
            if (! $this->canPrompt()) {
                $this->missingOption('password');
                $this->components->info('Pipe it with --password-stdin to keep it out of the process list: printf \'%s\n\' "$PASSWORD" | php artisan martis:user --password-stdin ...');

                return null;
            }
            $password = (string) $this->secret('Password');
        }

        if ($password === '') {
            $this->components->error('Password cannot be empty.');

            return null;
        }

        $validator = Validator::make(['password' => $password], ['password' => [PasswordPolicy::rule()]]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return null;
        }

        return $password;
    }

    /**
     * The first line of standard input without its line ending: the stream
     * Symfony reads input from when one is set, else STDIN. Read only when
     * --password-stdin asks for it, so a pipe still never answers a question.
     * Null, with the error printed, on a terminal (it would echo what is
     * typed; the prompt hides it) and for a line that is not text: not UTF-8,
     * or holding a control character, which is what PHP reads from a closed
     * descriptor on some systems (random bytes on macOS).
     */
    private function readPasswordFromStdin(): ?string
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $fromStdin = $stream === null;
        $stream ??= defined('STDIN') ? STDIN : null;

        if (! is_resource($stream)) {
            return '';
        }

        if ($fromStdin ? $this->stdinIsTty() : (function_exists('stream_isatty') && @stream_isatty($stream))) {
            $this->components->error('--password-stdin reads a pipe, not a terminal, where the password would show as it is typed. On a terminal, leave the option out: the command asks for the password without showing it. No user was created or changed.');

            return null;
        }

        $line = fgets($stream);

        if ($line === false) {
            return '';
        }

        $password = (string) preg_replace('/\r?\n\z/', '', $line);

        if (! mb_check_encoding($password, 'UTF-8') || preg_match('/\p{Cc}/u', $password) === 1) {
            $this->components->error('--password-stdin read a line that is not text (invalid UTF-8 or a control character). Pipe the password itself: printf \'%s\n\' "$PASSWORD" | php artisan martis:user --password-stdin ... No user was created or changed.');

            return null;
        }

        return $password;
    }

    /** The error for an option a run without a terminal cannot ask for; exit 1. */
    private function missingOption(string $option): int
    {
        $this->components->error("The --{$option} option is required when the command runs without a terminal (no TTY or --no-interaction). No user was created or changed.");

        return self::FAILURE;
    }
}
