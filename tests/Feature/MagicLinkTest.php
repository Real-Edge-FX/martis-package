<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Martis\Auth\MagicLinkNotification;
use Martis\Auth\MagicLinkService;

// -----------------------------------------------------------------------------
// MagicLinkService — token issue + consume
// -----------------------------------------------------------------------------

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->rememberToken();
            $t->timestamps();
        });
    }

    Schema::dropIfExists('password_reset_tokens');
    Schema::create('password_reset_tokens', function ($t) {
        $t->string('email')->primary();
        $t->string('token');
        $t->timestamp('created_at')->nullable();
    });

    config()->set('martis.auth.magic_link.enabled', true);
    config()->set('martis.auth.magic_link.ttl_minutes', 15);
});

afterEach(function () {
    Schema::dropIfExists('password_reset_tokens');
});

it('issue() persists a token row scoped under the martis-magic prefix', function () {
    $service = app(MagicLinkService::class);
    $token = $service->issue('Foo@Example.com');

    expect($token)->toBeString()->and(strlen($token))->toBeGreaterThan(40);

    $row = DB::table('password_reset_tokens')->where('email', 'martis-magic:foo@example.com')->first();
    expect($row)->not->toBeNull();
    // Stored as a hash; the plain text never lives in the DB.
    expect((string) $row->token)->not->toBe($token);
});

it('issue() drops any prior token for the same email', function () {
    $service = app(MagicLinkService::class);
    $first = $service->issue('user@example.com');
    $second = $service->issue('user@example.com');

    expect($first)->not->toBe($second);
    expect(DB::table('password_reset_tokens')->where('email', 'martis-magic:user@example.com')->count())->toBe(1);
});

it('consume() returns the email on a fresh token and deletes the row', function () {
    $service = app(MagicLinkService::class);
    $token = $service->issue('user@example.com');

    $email = $service->consume('user@example.com', $token);

    expect($email)->toBe('user@example.com');
    expect(DB::table('password_reset_tokens')->where('email', 'martis-magic:user@example.com')->exists())->toBeFalse();
});

it('consume() rejects an expired token and clears it', function () {
    $service = app(MagicLinkService::class);
    $token = $service->issue('user@example.com');

    // Force the row to look 60 minutes old. TTL default is 15 minutes.
    DB::table('password_reset_tokens')
        ->where('email', 'martis-magic:user@example.com')
        ->update(['created_at' => now()->subMinutes(60)]);

    $result = $service->consume('user@example.com', $token);
    expect($result)->toBeNull();
    expect(DB::table('password_reset_tokens')->where('email', 'martis-magic:user@example.com')->exists())->toBeFalse();
});

it('consume() rejects an unknown token without leaking row state', function () {
    $service = app(MagicLinkService::class);
    $service->issue('user@example.com');

    expect($service->consume('user@example.com', 'wrong-token'))->toBeNull();
    expect(DB::table('password_reset_tokens')->where('email', 'martis-magic:user@example.com')->exists())->toBeTrue();
});

it('issue() returns null when the token table is missing', function () {
    Schema::dropIfExists('password_reset_tokens');

    $service = app(MagicLinkService::class);
    expect($service->issue('user@example.com'))->toBeNull();
});

// -----------------------------------------------------------------------------
// MagicLinkController — HTTP endpoints
// -----------------------------------------------------------------------------

it('POST /api/auth/magic-link/request returns 200 + dispatches notification when email exists', function () {
    Notification::fake();

    /** @var User $user */
    $user = User::forceCreate([
        'name' => 'Maria',
        'email' => 'maria@example.com',
        'password' => bcrypt('x'),
    ]);

    $prefix = config('martis.path', 'martis');
    $response = $this->postJson("/{$prefix}/api/auth/magic-link/request", ['email' => 'maria@example.com']);

    $response->assertOk()->assertJson(['ok' => true]);
    Notification::assertSentTo($user, MagicLinkNotification::class);
});

it('POST /api/auth/magic-link/request hides account-existence by returning 200 either way', function () {
    Notification::fake();

    $prefix = config('martis.path', 'martis');
    $response = $this->postJson("/{$prefix}/api/auth/magic-link/request", ['email' => 'unknown@example.com']);

    $response->assertOk()->assertJson(['ok' => true]);
    Notification::assertNothingSent();
});

it('POST /api/auth/magic-link/request returns 404 when the feature is disabled', function () {
    config()->set('martis.auth.magic_link.enabled', false);

    $prefix = config('martis.path', 'martis');
    $response = $this->postJson("/{$prefix}/api/auth/magic-link/request", ['email' => 'maria@example.com']);

    $response->assertStatus(404);
});

it('GET /api/auth/magic-link/consume signs the user in on a valid token', function () {
    /** @var User $user */
    $user = User::forceCreate([
        'name' => 'Pedro',
        'email' => 'pedro@example.com',
        'password' => bcrypt('x'),
    ]);

    $service = app(MagicLinkService::class);
    $token = $service->issue('pedro@example.com');

    $prefix = config('martis.path', 'martis');
    $response = $this->get("/{$prefix}/api/auth/magic-link/consume?email=pedro@example.com&token={$token}");

    $response->assertRedirect("/{$prefix}");
    expect(auth()->guard(config('martis.guard'))->id())->toBe($user->id);
});

it('GET /api/auth/magic-link/consume redirects to login with `expired` when token is bad', function () {
    $prefix = config('martis.path', 'martis');
    $response = $this->get("/{$prefix}/api/auth/magic-link/consume?email=unknown@example.com&token=garbage");

    $response->assertRedirect("/{$prefix}/login?magic_link=expired");
});
