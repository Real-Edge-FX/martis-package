<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Martis\Auth\GuardCatalog;

class UserCommand extends Command
{
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
        $email = (string) ($this->option('email') ?? $this->ask('Email', 'admin@example.com'));

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

        $name = (string) ($this->option('name') ?? $this->ask('Name', 'Martis Admin'));
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
     * Read the password from the option or an interactive prompt; null (with
     * the error already printed) when it is empty.
     */
    private function resolvePassword(): ?string
    {
        $password = (string) ($this->option('password') ?? $this->secret('Password'));

        if ($password === '') {
            $this->components->error('Password cannot be empty.');

            return null;
        }

        return $password;
    }
}
