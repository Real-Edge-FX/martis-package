<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

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
