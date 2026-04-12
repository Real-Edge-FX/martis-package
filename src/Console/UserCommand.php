<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class UserCommand extends Command
{
    protected $signature = 'martis:user
                            {--name= : The full name of the admin user}
                            {--email= : The email address of the admin user}
                            {--password= : The password for the admin user}';

    protected $description = 'Create a new Martis admin user';

    /**
     * Handle.
     */
    public function handle(): int
    {
        $this->components->info('Creating Martis admin user...');

        $name = (string) ($this->option('name') ?? $this->ask('Name', 'Martis Admin'));
        $email = (string) ($this->option('email') ?? $this->ask('Email', 'admin@example.com'));
        $password = (string) ($this->option('password') ?? $this->secret('Password'));

        if ($password === '') {
            $this->components->error('Password cannot be empty.');

            return self::FAILURE;
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = (string) config('auth.providers.users.model', 'App\\Models\\User');

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
}
