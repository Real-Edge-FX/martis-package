<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Martis\Auth\MagicLinkNotification;
use Martis\Auth\MagicLinkService;

/** A user model as an app ships it: Notifiable, so the link is mailed to it. */
class MagicLinkNotifiableUser extends User
{
    use Notifiable;

    protected $table = 'users';
}

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
    config()->set('auth.providers.users.model', MagicLinkNotifiableUser::class);

    /** @var MagicLinkNotifiableUser $user */
    $user = MagicLinkNotifiableUser::forceCreate([
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

/** A user and a fresh token for them. */
function magicLinkFor(string $email = 'pedro@example.com'): array
{
    $user = User::forceCreate([
        'name' => 'Pedro',
        'email' => $email,
        'password' => bcrypt('x'),
    ]);

    return [$user, app(MagicLinkService::class)->issue($email)];
}

function magicLinkTokenRows(string $email = 'pedro@example.com'): int
{
    return DB::table('password_reset_tokens')->where('email', 'martis-magic:'.$email)->count();
}

it('GET /magic-link/confirm opens the confirmation page without signing in or burning the token', function () {
    [$user, $token] = magicLinkFor();

    // The emailed link carries the email and token in the fragment: the
    // request is the bare page.
    $page = $this->get('/martis/magic-link/confirm');

    $page->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
    expect($page->headers->get('Cache-Control'))->toContain('no-store');
    $this->assertGuest(config('martis.guard'));
    expect(magicLinkTokenRows())->toBe(1);
});

it('GET /magic-link/confirm leaves the token valid however many times a prefetch loads it', function () {
    [$user, $token] = magicLinkFor();

    foreach (range(1, 3) as $_) {
        $this->get('/martis/magic-link/confirm')->assertOk();
    }

    expect(magicLinkTokenRows())->toBe(1);
    $this->postJson('/martis/api/auth/magic-link/consume', ['email' => 'pedro@example.com', 'token' => $token])
        ->assertOk()
        ->assertJsonPath('redirect', '/martis');
    expect(auth()->guard(config('martis.guard'))->id())->toBe($user->id);
});

it('sends a link emailed before v2.4.0 (GET of the consume URL) to the confirmation page without signing in', function () {
    [$user, $token] = magicLinkFor();

    $response = $this->get('/martis/api/auth/magic-link/consume?email=Pedro@example.com&token='.$token);

    $response->assertRedirect('/martis/magic-link/confirm#email=pedro%40example.com&token='.$token)
        ->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $this->assertGuest(config('martis.guard'));
    expect(magicLinkTokenRows())->toBe(1);
});

it('sends a link emailed by v2.4.0 to v2.5.x (token in the query string) to the fragment form', function () {
    [$user, $token] = magicLinkFor();

    $response = $this->get('/martis/magic-link/confirm?email=Pedro@example.com&token='.$token);

    $response->assertRedirect('/martis/magic-link/confirm#email=pedro%40example.com&token='.$token)
        ->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    // The token is only after the `#`: the part a proxy logs has none.
    $location = parse_url($response->headers->get('Location'));
    expect($location['path'])->toBe('/martis/magic-link/confirm')
        ->and($location)->not->toHaveKey('query')
        ->and($location['fragment'])->toContain($token);
    $this->assertGuest(config('martis.guard'));
    expect(magicLinkTokenRows())->toBe(1);
});

it('GET /magic-link/confirm no longer checks the token: the page does, through the POST', function () {
    [$user, $token] = magicLinkFor();

    // An expired, invalid or missing token in a legacy query link is sent on
    // to the fragment form like a good one (the server does not look it up).
    $this->get('/martis/magic-link/confirm?email=unknown@example.com&token=garbage')
        ->assertRedirect('/martis/magic-link/confirm#email=unknown%40example.com&token=garbage');
    $this->get('/martis/magic-link/confirm?email=pedro@example.com')
        ->assertRedirect('/martis/magic-link/confirm#email=pedro%40example.com&token=');

    DB::table('password_reset_tokens')->update(['created_at' => now()->subMinutes(60)]);
    $this->get('/martis/magic-link/confirm?email=pedro@example.com&token='.$token)
        ->assertRedirect('/martis/magic-link/confirm#email=pedro%40example.com&token='.$token);

    // The POST the page makes reports the expiry, as the old GET did.
    $this->postJson('/martis/api/auth/magic-link/consume', ['email' => 'pedro@example.com', 'token' => $token])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'expired');
    $this->postJson('/martis/api/auth/magic-link/consume', ['email' => 'pedro@example.com', 'token' => 'garbage'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'expired');
    $this->postJson('/martis/api/auth/magic-link/consume', ['email' => 'pedro@example.com', 'token' => ''])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'invalid');
    $this->assertGuest(config('martis.guard'));
});

it('GET /magic-link/confirm redirects to login while magic links are off', function () {
    [$user, $token] = magicLinkFor();
    config()->set('martis.auth.magic_link.enabled', false);

    // Ending with `#`: the browser would carry the link's fragment over otherwise.
    $this->get('/martis/magic-link/confirm')->assertRedirect('/martis/login?magic_link=disabled#');
    $this->get('/martis/magic-link/confirm?email=pedro@example.com&token='.$token)
        ->assertRedirect('/martis/login?magic_link=disabled#')
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

it('signs in with the email and token parsed from the fragment of the emailed URL', function () {
    Notification::fake();
    config()->set('auth.providers.users.model', MagicLinkNotifiableUser::class);
    $user = MagicLinkNotifiableUser::forceCreate(['name' => 'Maria', 'email' => 'maria@example.com', 'password' => bcrypt('x')]);

    $this->postJson('/martis/api/auth/magic-link/request', ['email' => 'maria@example.com'])->assertOk();

    $url = null;
    Notification::assertSentTo($user, MagicLinkNotification::class, function (MagicLinkNotification $notification) use (&$url): bool {
        $url = $notification->url;

        return true;
    });

    $parts = parse_url($url);
    parse_str($parts['fragment'], $link);
    expect($parts['path'])->toBe('/martis/magic-link/confirm')
        ->and($parts)->not->toHaveKey('query')
        ->and($parts['path'])->not->toContain($link['token']);

    // What the page does: read the fragment, POST it.
    $this->postJson('/martis/api/auth/magic-link/consume', ['email' => $link['email'], 'token' => $link['token']])
        ->assertOk();
    expect(auth()->guard(config('martis.guard'))->id())->toBe($user->id);
});

it('POST /api/auth/magic-link/consume signs the user in on a valid token and burns it', function () {
    [$user, $token] = magicLinkFor();

    $this->postJson('/martis/api/auth/magic-link/consume', ['email' => 'pedro@example.com', 'token' => $token])
        ->assertOk()
        ->assertJsonPath('redirect', '/martis');

    expect(auth()->guard(config('martis.guard'))->id())->toBe($user->id)
        ->and(magicLinkTokenRows())->toBe(0);

    auth()->forgetGuards();
    $this->flushSession();
    $this->postJson('/martis/api/auth/magic-link/consume', ['email' => 'pedro@example.com', 'token' => $token])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'expired');
    $this->assertGuest(config('martis.guard'));
});

it('POST /api/auth/magic-link/consume answers 422 for a bad token and signs nobody in', function () {
    [$user, $token] = magicLinkFor();

    $this->postJson('/martis/api/auth/magic-link/consume', ['email' => 'pedro@example.com', 'token' => 'garbage'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'token')
        ->assertJsonPath('errors.0.code', 'expired');
    $this->postJson('/martis/api/auth/magic-link/consume', ['email' => 'unknown@example.com', 'token' => 'garbage'])
        ->assertStatus(422);
    $this->postJson('/martis/api/auth/magic-link/consume', ['email' => '', 'token' => ''])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'invalid');

    $this->assertGuest(config('martis.guard'));
    expect(magicLinkTokenRows())->toBe(1);
});

it('POST /api/auth/magic-link/consume answers 404 while magic links are off', function () {
    [$user, $token] = magicLinkFor();
    config()->set('martis.auth.magic_link.enabled', false);

    $this->postJson('/martis/api/auth/magic-link/consume', ['email' => 'pedro@example.com', 'token' => $token])
        ->assertNotFound();

    $this->assertGuest(config('martis.guard'));
    expect(magicLinkTokenRows())->toBe(1);
});

it('does not replace the session of another user without the confirmation', function () {
    [$pedro, $token] = magicLinkFor();
    $other = User::forceCreate(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => bcrypt('x')]);

    $this->actingAs($other)
        ->postJson('/martis/api/auth/magic-link/consume', ['email' => 'pedro@example.com', 'token' => $token])
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'session_conflict');

    // Still Ana's session, and the token was not spent: the page can send it again.
    expect(auth()->guard(config('martis.guard'))->id())->toBe($other->id)
        ->and(magicLinkTokenRows())->toBe(1);

    $this->postJson('/martis/api/auth/magic-link/consume', [
        'email' => 'pedro@example.com',
        'token' => $token,
        'replace_session' => true,
    ])->assertOk();

    expect(auth()->guard(config('martis.guard'))->id())->toBe($pedro->id)
        ->and(magicLinkTokenRows())->toBe(0);
});

it('signs the same user in again without asking for a confirmation', function () {
    [$pedro, $token] = magicLinkFor();

    $this->actingAs($pedro)
        ->postJson('/martis/api/auth/magic-link/consume', ['email' => 'pedro@example.com', 'token' => $token])
        ->assertOk();

    expect(magicLinkTokenRows())->toBe(0);
});

it('drops the 2FA pass and the other session data of the user it replaces', function () {
    [$pedro, $token] = magicLinkFor();
    $other = User::forceCreate(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => bcrypt('x')]);

    $this->actingAs($other)
        ->withSession(['martis_two_factor_passed' => true, 'leftover' => 'ana'])
        ->postJson('/martis/api/auth/magic-link/consume', [
            'email' => 'pedro@example.com',
            'token' => $token,
            'replace_session' => true,
        ])
        ->assertOk()
        ->assertSessionMissing('martis_two_factor_passed')
        ->assertSessionMissing('leftover');
});

it('enforces the CSRF check on the sign-in POST', function () {
    [$user, $token] = magicLinkFor();
    // The framework skips the CSRF check while the app runs as `testing`.
    $this->app['env'] = 'local';

    $this->post('/martis/api/auth/magic-link/consume', ['email' => 'pedro@example.com', 'token' => $token])
        ->assertStatus(419);

    $this->assertGuest(config('martis.guard'));
    expect(magicLinkTokenRows())->toBe(1);

    $this->withSession(['_token' => 'csrf-token'])
        ->post('/martis/api/auth/magic-link/consume', ['email' => 'pedro@example.com', 'token' => $token, '_token' => 'csrf-token'])
        ->assertOk();
    expect(auth()->guard(config('martis.guard'))->id())->toBe($user->id);
});

it('mails a link to the confirmation page, not to a URL that signs in', function () {
    Notification::fake();
    config()->set('auth.providers.users.model', MagicLinkNotifiableUser::class);
    MagicLinkNotifiableUser::forceCreate(['name' => 'Maria', 'email' => 'maria@example.com', 'password' => bcrypt('x')]);

    $this->postJson('/martis/api/auth/magic-link/request', ['email' => 'maria@example.com'])->assertOk();

    Notification::assertSentTo(
        MagicLinkNotifiableUser::query()->first(),
        MagicLinkNotification::class,
        fn (MagicLinkNotification $notification): bool => str_starts_with($notification->url, 'http://localhost/martis/magic-link/confirm#email=maria%40example.com&token=')
            && ! str_contains($notification->url, '/api/'),
    );
});
