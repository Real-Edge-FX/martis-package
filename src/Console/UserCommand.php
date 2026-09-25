<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Martis\Console\Concerns\AsksOnlyOnATerminal;

class UserCommand extends Command
{
    use AsksOnlyOnATerminal;

    protected $signature = 'martis:user
                            {--name= : The full name of the admin user}
                            {--email= : The email address of the admin user}
                            {--password= : The password for the admin user}
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

        /** @var class-string<Model> $modelClass */
        $modelClass = (string) config('auth.providers.users.model', 'App\\Models\\User');

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
        $user->setAttribute('email_verified_at', now());
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
     * Read the password from the option, or ask for it on a terminal; null
     * (with the error already printed) when it is empty or, without a
     * terminal, not given.
     */
    private function resolvePassword(): ?string
    {
        $password = $this->option('password');
        if (! is_string($password)) {
            if (! $this->canPrompt()) {
                $this->missingOption('password');

                return null;
            }
            $password = (string) $this->secret('Password');
        }

        if ($password === '') {
            $this->components->error('Password cannot be empty.');

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
