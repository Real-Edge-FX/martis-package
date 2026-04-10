<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use Martis\Profile\TwoFactorService;

function tfa2faUser(array $attrs = []): User
{
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }
    /** @var User $user */
    $user = User::forceCreate(array_merge([
        'name' => 'Test',
        'email' => 'tfa'.rand(1000, 9999).'@example.com',
        'password' => bcrypt('password'),
    ], $attrs));

    return $user;
}

it('generates a setup with secret and qr code svg', function () {
    $user = tfa2faUser();
    $service = app(TwoFactorService::class);
    $result = $service->generateSetup($user);

    expect($result)->toHaveKeys(['secret', 'qr_code_svg', 'otpauth_uri'])
        ->and($result['secret'])->toBeString()->not->toBeEmpty()
        ->and($result['qr_code_svg'])->toContain('<svg')
        ->and($result['otpauth_uri'])->toStartWith('otpauth://totp/');

    expect($user->fresh()->two_factor_secret)->not->toBeNull();
});

it('returns false when verifying invalid otp code', function () {
    $user = tfa2faUser();
    $service = app(TwoFactorService::class);
    $service->generateSetup($user);

    expect($service->verifyForUser($user, '000000'))->toBeFalse();
});

it('disables 2FA and clears all columns', function () {
    $user = tfa2faUser([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => encrypt(json_encode([bcrypt('recovery-code')])),
    ]);

    $service = app(TwoFactorService::class);
    expect($service->isEnabled($user))->toBeTrue();

    $service->disable($user);
    $fresh = $user->fresh();

    expect($service->isEnabled($fresh))->toBeFalse()
        ->and($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull();
});

it('reports isEnabled correctly before and after confirming', function () {
    $user = tfa2faUser();
    $service = app(TwoFactorService::class);
    expect($service->isEnabled($user))->toBeFalse();

    $user->two_factor_confirmed_at = now();
    $user->save();

    expect($service->isEnabled($user->fresh()))->toBeTrue();
});
