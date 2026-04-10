<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

// ──────────────────────────────────────────────────────────────────────────────
// Helper: create users table with profile columns + return a test user
// ──────────────────────────────────────────────────────────────────────────────
function makeProfileUsersTable(): void
{
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('profile_picture')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    } elseif (! Schema::hasColumn('users', 'profile_picture')) {
        Schema::table('users', function ($table) {
            $table->string('profile_picture')->nullable()->after('email');
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }
}

function makeTestUser(array $attrs = []): User
{
    makeProfileUsersTable();
    /** @var User $user */
    $user = User::forceCreate(array_merge([
        'name' => 'Test User',
        'email' => 'test'.rand(1000, 9999).'@example.com',
        'password' => bcrypt('password'),
    ], $attrs));

    return $user;
}

function loginTestUser(User $user): void
{
    test()->actingAs($user, config('martis.guard'));
}

// ──────────────────────────────────────────────────────────────────────────────
// GET /api/profile
// ──────────────────────────────────────────────────────────────────────────────
it('returns profile data for authenticated user', function () {
    $user = makeTestUser(['name' => 'Profile Test', 'email' => 'profile@example.com']);
    loginTestUser($user);
    $prefix = config('martis.path', 'martis');

    $response = $this->getJson("/{$prefix}/api/profile");

    $response->assertOk()
        ->assertJsonStructure(['name', 'email', 'avatar_url', 'two_factor_enabled'])
        ->assertJsonFragment(['name' => 'Profile Test', 'email' => 'profile@example.com']);
});

it('returns 401 for unauthenticated profile request', function () {
    $prefix = config('martis.path', 'martis');
    $response = $this->getJson("/{$prefix}/api/profile");
    $response->assertUnauthorized();
});

// ──────────────────────────────────────────────────────────────────────────────
// PATCH /api/profile
// ──────────────────────────────────────────────────────────────────────────────
it('updates name and email', function () {
    $user = makeTestUser(['email' => 'original@example.com']);
    loginTestUser($user);
    $prefix = config('martis.path', 'martis');

    $response = $this->patchJson("/{$prefix}/api/profile", [
        'name' => 'Updated Name',
        'email' => 'updated@example.com',
    ]);

    $response->assertOk()
        ->assertJsonFragment(['name' => 'Updated Name', 'email' => 'updated@example.com']);

    $this->assertDatabaseHas('users', ['name' => 'Updated Name', 'email' => 'updated@example.com']);
});

it('validates required fields on profile update', function () {
    $user = makeTestUser(['email' => 'valid@example.com']);
    loginTestUser($user);
    $prefix = config('martis.path', 'martis');

    $response = $this->patchJson("/{$prefix}/api/profile", ['name' => '', 'email' => 'not-an-email']);
    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email']);
});

// ──────────────────────────────────────────────────────────────────────────────
// POST /api/profile/password
// ──────────────────────────────────────────────────────────────────────────────
it('changes password with valid current password', function () {
    $user = makeTestUser(['password' => bcrypt('OldPassword123')]);
    loginTestUser($user);
    $prefix = config('martis.path', 'martis');

    $response = $this->postJson("/{$prefix}/api/profile/password", [
        'current_password' => 'OldPassword123',
        'password' => 'NewPassword456',
        'password_confirmation' => 'NewPassword456',
    ]);

    $response->assertOk()->assertJsonFragment(['message' => __('martis::profile.password_updated')]);
});

it('rejects password change with wrong current password', function () {
    $user = makeTestUser(['password' => bcrypt('correctpassword')]);
    loginTestUser($user);
    $prefix = config('martis.path', 'martis');

    $response = $this->postJson("/{$prefix}/api/profile/password", [
        'current_password' => 'wrongpassword',
        'password' => 'newpassword456',
        'password_confirmation' => 'newpassword456',
    ]);

    $response->assertUnprocessable();
});

it('rejects password change when passwords do not match', function () {
    $user = makeTestUser(['password' => bcrypt('currentpass')]);
    loginTestUser($user);
    $prefix = config('martis.path', 'martis');

    $response = $this->postJson("/{$prefix}/api/profile/password", [
        'current_password' => 'currentpass',
        'password' => 'newpassword456',
        'password_confirmation' => 'differentpassword',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['password']);
});

// ──────────────────────────────────────────────────────────────────────────────
// Avatar upload / remove
// ──────────────────────────────────────────────────────────────────────────────
it('uploads a profile picture', function () {
    Storage::fake('public');
    $user = makeTestUser();
    loginTestUser($user);
    $prefix = config('martis.path', 'martis');

    $file = UploadedFile::fake()->image('avatar.jpg', 100, 100);

    $response = $this->postJson("/{$prefix}/api/profile/avatar", ['avatar' => $file]);

    $response->assertOk()->assertJsonStructure(['url']);
});

it('rejects avatar upload with invalid mime type', function () {
    Storage::fake('public');
    $user = makeTestUser();
    loginTestUser($user);
    $prefix = config('martis.path', 'martis');

    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
    $response = $this->postJson("/{$prefix}/api/profile/avatar", ['avatar' => $file]);
    $response->assertUnprocessable();
});

it('removes a profile picture', function () {
    Storage::fake('public');
    $user = makeTestUser();
    loginTestUser($user);
    $prefix = config('martis.path', 'martis');

    $file = UploadedFile::fake()->image('avatar.jpg');
    $this->postJson("/{$prefix}/api/profile/avatar", ['avatar' => $file]);

    $response = $this->deleteJson("/{$prefix}/api/profile/avatar");
    $response->assertOk();
    $this->assertNull($user->fresh()->profile_picture);
});

// ──────────────────────────────────────────────────────────────────────────────
// 2FA setup / confirm / disable
// ──────────────────────────────────────────────────────────────────────────────
it('returns 2FA setup data with qr_code_svg and secret', function () {
    $user = makeTestUser();
    loginTestUser($user);
    $prefix = config('martis.path', 'martis');

    $response = $this->postJson("/{$prefix}/api/profile/2fa/setup");

    $response->assertOk()
        ->assertJsonStructure(['secret', 'qr_code_svg', 'otpauth_uri']);

    $this->assertNotNull($user->fresh()->two_factor_secret);
});

it('rejects 2FA confirm with invalid code', function () {
    $user = makeTestUser();
    loginTestUser($user);
    $prefix = config('martis.path', 'martis');

    $this->postJson("/{$prefix}/api/profile/2fa/setup");

    $response = $this->postJson("/{$prefix}/api/profile/2fa/confirm", ['code' => '000000']);
    $response->assertUnprocessable();
});

it('disables 2FA', function () {
    $user = makeTestUser([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ]);
    loginTestUser($user);
    $prefix = config('martis.path', 'martis');

    $response = $this->deleteJson("/{$prefix}/api/profile/2fa");
    $response->assertOk();
    $this->assertNull($user->fresh()->two_factor_confirmed_at);
    $this->assertNull($user->fresh()->two_factor_secret);
});
