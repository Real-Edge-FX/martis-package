<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as Authenticatable;
use Martis\Auth\GuardCatalog;

beforeEach(function () {
    // Two-guard fixture exercises both `available()` (sorted list) and
    // `default()` (whatever Laravel says — typically `web`).
    config()->set('auth.guards', [
        'web' => ['driver' => 'session', 'provider' => 'users'],
        'api' => ['driver' => 'token', 'provider' => 'users'],
    ]);
    config()->set('auth.defaults.guard', 'web');

    config()->set('auth.providers', [
        'users' => [
            'driver' => 'eloquent',
            'model' => Authenticatable::class,
        ],
    ]);
});

it('returns 401 for guests', function () {
    $this->getJson('/martis/api/_meta/guards')->assertStatus(401);
});

it('returns the configured guards for authenticated users', function () {
    $user = new class extends Authenticatable
    {
        protected $table = 'users';

        public $timestamps = false;
    };
    $user->id = 1;

    $this->actingAs($user)
        ->getJson('/martis/api/_meta/guards')
        ->assertOk()
        ->assertJson([
            'guards' => ['api', 'web'], // alphabetical
            'default' => 'web',
        ]);
});

it('GuardCatalog::default falls back to web when no default is configured', function () {
    config()->set('auth.defaults.guard', null);
    expect(GuardCatalog::default())->toBe('web');

    config()->set('auth.defaults.guard', '');
    expect(GuardCatalog::default())->toBe('web');
});
