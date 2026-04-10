<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Martis\Profile\AvatarService;

function avatarTestUser(array $attrs = []): User
{
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('profile_picture')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    } elseif (! Schema::hasColumn('users', 'profile_picture')) {
        Schema::table('users', function ($table) {
            $table->string('profile_picture')->nullable();
        });
    }
    /** @var User $user */
    $user = User::forceCreate(array_merge([
        'name' => 'Avatar User',
        'email' => 'avatar'.rand(1000, 9999).'@example.com',
        'password' => bcrypt('password'),
    ], $attrs));

    return $user;
}

it('stores avatar file and returns public url', function () {
    Storage::fake('public');
    $user = avatarTestUser();
    $file = UploadedFile::fake()->image('photo.jpg', 200, 200);
    $service = app(AvatarService::class);
    $url = $service->upload($user, $file);

    expect($url)->toBeString()->not->toBeEmpty();
    expect($user->fresh()->profile_picture)->not->toBeNull();
    Storage::disk('public')->assertExists($user->fresh()->profile_picture);
});

it('removes avatar file and clears column', function () {
    Storage::fake('public');
    $user = avatarTestUser();
    $service = app(AvatarService::class);
    $file = UploadedFile::fake()->image('photo2.jpg');
    $service->upload($user, $file);

    $storedPath = $user->fresh()->profile_picture;
    Storage::disk('public')->assertExists($storedPath);

    $service->remove($user->fresh());

    Storage::disk('public')->assertMissing($storedPath);
    expect($user->fresh()->profile_picture)->toBeNull();
});

it('deletes old avatar when uploading a new one', function () {
    Storage::fake('public');
    $user = avatarTestUser();
    $service = app(AvatarService::class);

    $file1 = UploadedFile::fake()->image('photo_old.jpg');
    $service->upload($user, $file1);
    $oldPath = $user->fresh()->profile_picture;

    $file2 = UploadedFile::fake()->image('photo_new.jpg');
    $service->upload($user->fresh(), $file2);

    Storage::disk('public')->assertMissing($oldPath);
    expect($user->fresh()->profile_picture)->not->toEqual($oldPath);
});
