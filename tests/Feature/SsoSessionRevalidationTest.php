<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Martis\Http\RouteMiddleware;
use Martis\Sso\Contracts\RevalidatesSessions;
use Martis\Sso\Facades\MartisSso;
use Martis\Sso\Providers\AzureProvider;
use Martis\Sso\SsoIdentity;
use Martis\Sso\SsoManager;
use Martis\Sso\SsoSession;
use Symfony\Component\HttpFoundation\Cookie;

// ===========================================================================
// What an SSO session keeps after sign-in (v2.21.0): when it began, who the
// IdP said the person was, and the three ways Martis ends it later without a
// sign-out: an absolute age (`max_session_minutes`), a revocation
// (`MartisSso::revokeFor()`) and the provider's own revalidation hook
// (`revalidate_every_minutes`, RevalidatesSessions). Everything is off by
// default and a session opened without SSO is never touched.
// ===========================================================================

class SsoRevUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class SsoRevRole extends Model
{
    protected $table = 'sso_rev_roles';

    protected $guarded = [];

    public $timestamps = false;
}

/** An Azure provider that implements the revalidation contract, with a scripted answer. */
class SsoRevProvider extends AzureProvider implements RevalidatesSessions
{
    public static SsoIdentity $identity;

    /** @var list<array{0: Authenticatable, 1: string|null}> */
    public static array $calls = [];

    /** What stillValid() answers: a bool, or a Throwable to throw. */
    public static bool|Throwable $answer = true;

    public function __construct() {}

    public function resolveIdentity(Request $request): SsoIdentity
    {
        return self::$identity;
    }

    public function redirect(Request $request): RedirectResponse
    {
        return redirect('/');
    }

    public function stillValid(Authenticatable $user, ?string $externalId): bool
    {
        self::$calls[] = [$user, $externalId];

        if (self::$answer instanceof Throwable) {
            throw self::$answer;
        }

        return self::$answer;
    }
}

/** The same without the contract. */
class SsoRevPlainProvider extends AzureProvider
{
    public function __construct() {}

    public function resolveIdentity(Request $request): SsoIdentity
    {
        return SsoRevProvider::$identity;
    }

    public function redirect(Request $request): RedirectResponse
    {
        return redirect('/');
    }
}

class SsoRevLogoutUrl
{
    /** @var list<array{0: Request, 1: Authenticatable|null, 2: string}> */
    public static array $calls = [];

    public static ?string $answer = 'https://idp.example/end-session';

    public function __invoke(Request $request, ?Authenticatable $user, string $postLogoutRedirectUri): ?string
    {
        self::$calls[] = [$request, $user, $postLogoutRedirectUri];

        return self::$answer;
    }
}

class SsoRevNotInvokable {}

/** A logout_url class that cannot reach its IdP. */
class SsoRevThrowingLogoutUrl
{
    public function __invoke(Request $request, ?Authenticatable $user, string $postLogoutRedirectUri): ?string
    {
        throw new RuntimeException('IdP discovery timed out');
    }
}

/** An invokable with no return type, so it can hand back anything. */
class SsoRevLooseLogoutUrl
{
    public function __invoke($request, $user, $postLogoutRedirectUri)
    {
        return 42;
    }
}

class SsoRevShopUser extends User
{
    protected $table = 'sso_rev_shop_users';

    protected $guarded = [];
}

/** A cache whose reads or writes fail, for the fail-closed checks. */
class SsoRevFailingStore extends ArrayStore
{
    public static bool $failReads = false;

    public static bool $failWrites = false;

    public function get($key)
    {
        if (self::$failReads) {
            throw new RuntimeException('cache read failed');
        }

        return parent::get($key);
    }

    public function put($key, $value, $seconds)
    {
        if (self::$failWrites) {
            throw new RuntimeException('cache write failed');
        }

        return parent::put($key, $value, $seconds);
    }
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0));

    Schema::dropIfExists('users');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->boolean('must_change_password')->default(false);
        $table->rememberToken();
        $table->timestamps();
    });
    Schema::dropIfExists('sso_rev_roles');
    Schema::create('sso_rev_roles', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('azure_group_name')->nullable();
    });
    SsoRevRole::create(['name' => 'admin', 'azure_group_name' => 'ADMINS']);

    config([
        'auth.providers.users.model' => SsoRevUser::class,
        'martis.auth.sso.enabled' => true,
        'martis.impersonation.enabled' => true,
    ]);

    // The SSO routes register only when SSO is on at boot.
    $this->app['router']->setRoutes(new RouteCollection);
    require __DIR__.'/../../routes/martis.php';

    config()->set('martis.auth.sso.providers.azure', [
        'enabled' => true,
        'driver' => 'azure',
        'role_strategy' => 'column',
        'role_column' => 'azure_group_name',
        'role_model' => SsoRevRole::class,
        'auto_create_user' => true,
        'identity_match_attribute' => 'email',
        'sync_user_attributes' => ['name', 'email'],
        'sync_roles' => true,
        'permission_adapter' => 'callable',
        'on_no_role_match' => 'deny',
        'remember' => false,
    ]);

    $this->app->make(SsoManager::class)->flushHooksForTesting();
    MartisSso::syncRolesUsing(function (User $user, Collection $roles) {});
    MartisSso::resolveUserUsing(fn (SsoIdentity $id, string $provider) => SsoRevUser::query()->updateOrCreate(
        ['email' => $id->email],
        ['name' => $id->name, 'password' => Hash::make(Str::random(40))],
    ));

    SsoRevProvider::$identity = new SsoIdentity(provider: 'azure', externalId: 'azure-sso', email: 'sso@example.com', name: 'Sso', externalRoles: ['ADMINS']);
    SsoRevProvider::$calls = [];
    SsoRevProvider::$answer = true;
    SsoRevLogoutUrl::$calls = [];
    SsoRevLogoutUrl::$answer = 'https://idp.example/end-session';
    SsoRevFailingStore::$failReads = false;
    SsoRevFailingStore::$failWrites = false;
    $this->app->instance(AzureProvider::class, new SsoRevProvider);
});

afterEach(function () {
    SsoRevFailingStore::$failReads = false;
    SsoRevFailingStore::$failWrites = false;
    Schema::dropIfExists('sso_rev_roles');
    Schema::dropIfExists('sso_rev_shop_users');
    Schema::dropIfExists('sessions');
    $this->app->make(SsoManager::class)->flushHooksForTesting();
    Carbon::setTestNow();
});

function ssoRevCookie(TestResponse $response, string $prefix): ?Cookie
{
    return collect($response->headers->getCookies())->first(fn (Cookie $cookie) => str_starts_with($cookie->getName(), $prefix));
}

/** The session ends on its own (SESSION_LIFETIME); the browser keeps its cookies. */
function ssoRevExpireSession(): void
{
    test()->flushSession();
    Auth::forgetGuards();
    app('cookie')->flushQueuedCookies();
}

/** A request carrying the cookies of a response as the browser holds them. */
function ssoRevBrowser(Cookie ...$cookies): mixed
{
    $request = test()->withCredentials();
    foreach ($cookies as $cookie) {
        $request = $request->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    return $request;
}

/** Sign in through the callback: the response, with the remember and origin cookies of it. */
function ssoRevSignIn(): array
{
    $callback = test()->get('/martis/sso/azure/callback')->assertRedirect();

    return [$callback, ssoRevCookie($callback, 'remember_'), ssoRevCookie($callback, SsoSession::COOKIE)];
}

/** The provider keeps the panel open with a remember cookie (it cannot also carry a session limit). */
function ssoRevRemember(): void
{
    config()->set('martis.auth.sso.providers.azure.remember', true);
}

function ssoRevUserId(): int
{
    return (int) SsoRevUser::query()->where('email', 'sso@example.com')->value('id');
}

function ssoRevPasswordUser(): SsoRevUser
{
    return SsoRevUser::query()->create(['name' => 'P', 'email' => 'p@example.com', 'password' => Hash::make('Password-Pass-1')]);
}

// ---------------------------------------------------------------------------
// 1. Sign-in time and identity
// ---------------------------------------------------------------------------

it('records when the SSO sign-in happened and the external id of the identity', function () {
    ssoRevSignIn();

    expect(session(SsoSession::SESSION_AT))->toBe(Carbon::now()->getTimestamp())
        ->and(session(SsoSession::SESSION_EXTERNAL_ID))->toBe('azure-sso');
});

it('keeps the original sign-in time after a remember-me re-login, never the time of the re-login', function () {
    ssoRevRemember();
    [, $recaller, $marker] = ssoRevSignIn();
    $signedInAt = Carbon::now()->getTimestamp();

    ssoRevExpireSession();
    $this->travel(3)->hours();

    ssoRevBrowser($recaller, $marker)->getJson('/martis/api/tools')->assertOk();

    expect(Auth::guard()->viaRemember())->toBeTrue()
        ->and(session(SsoSession::SESSION_AT))->toBe($signedInAt)
        ->and(session(SsoSession::SESSION_EXTERNAL_ID))->toBe('azure-sso');
});

it('drops the sign-in time with the origin on a sign-out and on a password sign-in', function () {
    ssoRevSignIn();
    $this->postJson('/martis/api/auth/logout')->assertOk();

    expect(session(SsoSession::SESSION_AT))->toBeNull()
        ->and(session(SsoSession::SESSION_EXTERNAL_ID))->toBeNull();

    ssoRevSignIn();
    ssoRevPasswordUser();
    $this->postJson('/martis/api/auth/login', ['email' => 'p@example.com', 'password' => 'Password-Pass-1'])->assertOk();

    expect(session(SsoSession::SESSION_KEY))->toBeNull()
        ->and(session(SsoSession::SESSION_AT))->toBeNull();
});

// ---------------------------------------------------------------------------
// 2. Absolute age
// ---------------------------------------------------------------------------

it('ends an SSO session older than max_session_minutes: a JSON request gets 401 and the way back through the provider', function () {
    config()->set('martis.auth.sso.providers.azure.max_session_minutes', 60);
    ssoRevSignIn();
    $this->getJson('/martis/api/tools')->assertOk();

    $this->travel(61)->minutes();

    $this->getJson('/martis/api/tools')
        ->assertUnauthorized()
        ->assertJsonPath('sso_reauth_url', '/martis/sso/azure/redirect')
        ->assertJsonStructure(['message', 'sso_reauth_url']);

    // Ended as a sign-out is: guard, session, origin.
    expect(Auth::guard()->check())->toBeFalse()
        ->and(session(SsoSession::SESSION_KEY))->toBeNull()
        ->and(session(SsoSession::SESSION_AT))->toBeNull();
    $this->getJson('/martis/api/tools')->assertUnauthorized()->assertJsonMissingPath('sso_reauth_url');
});

it('sends a page request of an expired SSO session through the provider again', function () {
    config()->set('martis.auth.sso.providers.azure.max_session_minutes', 60);
    ssoRevSignIn();

    $this->travel(61)->minutes();

    $this->get('/martis')->assertRedirect('/martis/sso/azure/redirect');
    expect(Auth::guard()->check())->toBeFalse();
});

it('clears the origin cookie when it ends a session', function () {
    ssoRevRemember();
    [, $recaller, $marker] = ssoRevSignIn();

    // Revoked, not aged out: a limit and a remember cookie cannot share a provider.
    SsoSession::revokeUser((string) ssoRevUserId());
    $this->travel(2)->seconds();

    $ended = ssoRevBrowser($recaller, $marker)->getJson('/martis/api/tools')->assertUnauthorized();
    expect(ssoRevCookie($ended, SsoSession::COOKIE)?->isCleared())->toBeTrue();
});

it('leaves a younger SSO session untouched', function () {
    config()->set('martis.auth.sso.providers.azure.max_session_minutes', 60);
    ssoRevSignIn();

    $this->travel(59)->minutes();

    $this->getJson('/martis/api/tools')->assertOk();
    expect(Auth::guard()->check())->toBeTrue();
});

it('ends the session on the minute the limit names', function () {
    config()->set('martis.auth.sso.providers.azure.max_session_minutes', 60);
    ssoRevSignIn();

    $this->travel(60)->minutes();

    $this->getJson('/martis/api/tools')->assertUnauthorized()->assertJsonPath('sso_reauth_url', '/martis/sso/azure/redirect');
});

it('counts an SSO session that has the origin and no sign-in time (opened before the upgrade) as expired', function () {
    config()->set('martis.auth.sso.providers.azure.max_session_minutes', 60);
    ssoRevSignIn();
    session()->forget(SsoSession::SESSION_AT);

    $this->getJson('/martis/api/tools')->assertUnauthorized()->assertJsonPath('sso_reauth_url', '/martis/sso/azure/redirect');
});

it('ends nothing while max_session_minutes is unset, however old the session is', function () {
    ssoRevSignIn();

    $this->travel(30)->days();

    $this->getJson('/martis/api/tools')->assertOk();
});

it('refuses a max_session_minutes that is not a positive whole number, naming the key', function (mixed $value) {
    config()->set('martis.auth.sso.providers.azure.max_session_minutes', $value);
    ssoRevSignIn();
    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/martis/api/tools'))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.providers.azure.max_session_minutes');
})->with(['zero' => [0], 'negative' => [-5], 'text' => ['soon'], 'array' => [[60]]]);

it('reads max_session_minutes from an environment string too', function () {
    config()->set('martis.auth.sso.providers.azure.max_session_minutes', '60');
    ssoRevSignIn();
    $this->travel(61)->minutes();

    $this->getJson('/martis/api/tools')->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// A session that SSO did not open is never touched
// ---------------------------------------------------------------------------

it('never touches a password-opened session, whatever is configured', function () {
    config()->set('martis.auth.sso.providers.azure.max_session_minutes', 1);
    config()->set('martis.auth.sso.providers.azure.revalidate_every_minutes', 1);
    SsoRevProvider::$answer = false;

    $user = ssoRevPasswordUser();
    $this->postJson('/martis/api/auth/login', ['email' => 'p@example.com', 'password' => 'Password-Pass-1', 'keep_signed_in' => true])->assertOk();

    $this->travel(3)->days();
    MartisSso::revokeFor($user->getKey());
    $this->travel(2)->seconds();

    $this->getJson('/martis/api/tools')->assertOk();
    expect(SsoRevProvider::$calls)->toBe([]);
});

it('never touches the password session of a user who also has an SSO sign-in elsewhere', function () {
    config()->set('martis.auth.sso.providers.azure.max_session_minutes', 1);
    ssoRevSignIn();
    $this->postJson('/martis/api/auth/logout')->assertOk();
    ssoRevExpireSession();

    $user = SsoRevUser::query()->where('email', 'sso@example.com')->firstOrFail();
    $user->forceFill(['password' => Hash::make('Password-Pass-1')])->save();
    $this->postJson('/martis/api/auth/login', ['email' => 'sso@example.com', 'password' => 'Password-Pass-1'])->assertOk();

    $this->travel(2)->hours();
    $this->getJson('/martis/api/tools')->assertOk();
});

// ---------------------------------------------------------------------------
// 3. Revocation
// ---------------------------------------------------------------------------

it('ends the active SSO session of a revoked user, on every session driver, with no age limit set', function () {
    ssoRevSignIn();
    $this->getJson('/martis/api/tools')->assertOk();

    MartisSso::revokeFor(ssoRevUserId());

    $this->getJson('/martis/api/tools')
        ->assertUnauthorized()
        ->assertJsonPath('sso_reauth_url', '/martis/sso/azure/redirect');
    expect(Auth::guard()->check())->toBeFalse();
});

it('accepts the id as a string', function () {
    ssoRevSignIn();

    MartisSso::revokeFor((string) ssoRevUserId());

    $this->getJson('/martis/api/tools')->assertUnauthorized();
});

it('leaves the sessions of other users alone', function () {
    ssoRevSignIn();

    MartisSso::revokeFor(ssoRevUserId() + 100);

    $this->getJson('/martis/api/tools')->assertOk();
});

it('kills the remember cookie and the origin cookie of the revoked user', function () {
    ssoRevRemember();
    [, $recaller, $marker] = ssoRevSignIn();
    $before = SsoRevUser::query()->find(ssoRevUserId())->getRememberToken();

    MartisSso::revokeFor(ssoRevUserId());

    expect(SsoRevUser::query()->find(ssoRevUserId())->getRememberToken())->not->toBe($before);

    ssoRevExpireSession();
    ssoRevBrowser($recaller, $marker)->getJson('/martis/api/tools')->assertUnauthorized()->assertJsonMissingPath('sso_reauth_url');
    expect(Auth::guard()->check())->toBeFalse();
});

it('drops every SSO-origin nonce of the revoked user', function () {
    ssoRevRemember();
    [, , $marker] = ssoRevSignIn();
    $userId = ssoRevUserId();
    $user = SsoRevUser::query()->findOrFail($userId);

    // Control: the cookie of the sign-in gives a session of this user its origin back.
    ssoRevExpireSession();
    ssoRevBrowser($marker)->actingAs($user)->getJson('/martis/api/tools')->assertOk();
    expect(session(SsoSession::SESSION_KEY))->toBe('azure');

    MartisSso::revokeFor($userId);

    ssoRevExpireSession();
    ssoRevBrowser($marker)->actingAs($user)->getJson('/martis/api/tools')->assertOk();
    expect(session(SsoSession::SESSION_KEY))->toBeNull();
});

it('lets a sign-in after the revocation work normally', function () {
    ssoRevSignIn();
    MartisSso::revokeFor(ssoRevUserId());
    $this->getJson('/martis/api/tools')->assertUnauthorized();

    $this->travel(2)->seconds();
    ssoRevSignIn();

    $this->getJson('/martis/api/tools')->assertOk();
    $this->travel(30)->minutes();
    $this->getJson('/martis/api/tools')->assertOk();
});

it('applies to an impersonating operator: the operator is the one who signed in through SSO', function () {
    ssoRevSignIn();
    $operatorId = ssoRevUserId();
    $target = ssoRevPasswordUser();

    session()->put(config('martis.impersonation.session_key', 'martis.impersonation'), [
        'original' => $operatorId,
        'target' => $target->getKey(),
        'started_at' => now()->toIso8601String(),
        'two_factor_passed' => false,
    ]);
    Auth::guard()->login($target);
    $this->getJson('/martis/api/tools')->assertOk();

    // Revoking the target does not end the operator's session; revoking the operator does.
    MartisSso::revokeFor($target->getKey());
    $this->getJson('/martis/api/tools')->assertOk();

    MartisSso::revokeFor($operatorId);
    $this->getJson('/martis/api/tools')->assertUnauthorized();
});

it('deletes the session rows of the revoked user on the database driver, password sessions included', function () {
    Schema::dropIfExists('sessions');
    Schema::create('sessions', function ($t) {
        $t->string('id')->primary();
        $t->foreignId('user_id')->nullable()->index();
        $t->string('ip_address', 45)->nullable();
        $t->text('user_agent')->nullable();
        $t->longText('payload');
        $t->integer('last_activity')->index();
    });
    config()->set('session.driver', 'database');
    config()->set('session.table', 'sessions');

    ssoRevSignIn();
    $this->getJson('/martis/api/tools')->assertOk();
    $userId = ssoRevUserId();
    $other = ssoRevPasswordUser();

    DB::table('sessions')->insert([
        ['id' => str_repeat('p', 40), 'user_id' => $userId, 'ip_address' => '10.0.0.1', 'user_agent' => 'password device', 'payload' => 'x', 'last_activity' => now()->getTimestamp()],
        ['id' => str_repeat('o', 40), 'user_id' => $other->getKey(), 'ip_address' => '10.0.0.2', 'user_agent' => 'someone else', 'payload' => 'y', 'last_activity' => now()->getTimestamp()],
    ]);
    expect(DB::table('sessions')->where('user_id', $userId)->count())->toBeGreaterThanOrEqual(2);

    MartisSso::revokeFor($userId);

    expect(DB::table('sessions')->where('user_id', $userId)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $other->getKey())->count())->toBe(1);
});

it('leaves the session rows alone when they cannot be attributed to a user (guards of two user tables)', function () {
    Schema::dropIfExists('sessions');
    Schema::create('sessions', function ($t) {
        $t->string('id')->primary();
        $t->foreignId('user_id')->nullable()->index();
        $t->string('ip_address', 45)->nullable();
        $t->text('user_agent')->nullable();
        $t->longText('payload');
        $t->integer('last_activity')->index();
    });
    Schema::dropIfExists('sso_rev_shop_users');
    Schema::create('sso_rev_shop_users', function ($t) {
        $t->id();
    });
    config()->set('session.driver', 'database');
    config()->set('session.table', 'sessions');
    config()->set('auth.guards.shop', ['driver' => 'session', 'provider' => 'shop_users']);
    config()->set('auth.providers.shop_users', ['driver' => 'eloquent', 'model' => SsoRevShopUser::class]);

    ssoRevSignIn();
    $userId = ssoRevUserId();
    DB::table('sessions')->insert(['id' => str_repeat('s', 40), 'user_id' => $userId, 'ip_address' => '10.0.0.3', 'user_agent' => 'a shop user with the same id', 'payload' => 'z', 'last_activity' => now()->getTimestamp()]);

    MartisSso::revokeFor($userId);

    expect(DB::table('sessions')->where('id', str_repeat('s', 40))->count())->toBe(1);
    // The SSO session itself still ends, through the revocation time.
    $this->getJson('/martis/api/tools')->assertUnauthorized();
});

it('does not touch session rows on a driver that is not the database', function () {
    Schema::dropIfExists('sessions');
    Schema::create('sessions', function ($t) {
        $t->string('id')->primary();
        $t->foreignId('user_id')->nullable()->index();
        $t->text('payload');
        $t->integer('last_activity');
    });
    ssoRevSignIn();
    $userId = ssoRevUserId();
    DB::table('sessions')->insert(['id' => str_repeat('f', 40), 'user_id' => $userId, 'payload' => 'x', 'last_activity' => now()->getTimestamp()]);

    MartisSso::revokeFor($userId);

    expect(DB::table('sessions')->where('user_id', $userId)->count())->toBe(1);
});

it('treats a cache failure while reading the revocation as a revocation, and reports it', function () {
    Exceptions::fake();
    Cache::extend('sso-rev-failing', fn () => Cache::repository(new SsoRevFailingStore));
    config()->set('cache.stores.sso_rev_failing', ['driver' => 'sso-rev-failing']);
    config()->set('cache.default', 'sso_rev_failing');

    ssoRevSignIn();
    SsoRevFailingStore::$failReads = true;

    $this->getJson('/martis/api/tools')
        ->assertUnauthorized()
        ->assertJsonPath('sso_reauth_url', '/martis/sso/azure/redirect');
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'cache read failed');
});

it('throws when the revocation cannot be written', function () {
    Cache::extend('sso-rev-failing', fn () => Cache::repository(new SsoRevFailingStore));
    config()->set('cache.stores.sso_rev_failing', ['driver' => 'sso-rev-failing']);
    config()->set('cache.default', 'sso_rev_failing');
    SsoRevFailingStore::$failWrites = true;

    expect(fn () => MartisSso::revokeFor(7))->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// 4. The revalidation hook
// ---------------------------------------------------------------------------

it('ends the session when stillValid() says the person is gone', function () {
    config()->set('martis.auth.sso.providers.azure.revalidate_every_minutes', 10);
    ssoRevSignIn();
    SsoRevProvider::$answer = false;

    $this->travel(11)->minutes();

    $this->getJson('/martis/api/tools')
        ->assertUnauthorized()
        ->assertJsonPath('sso_reauth_url', '/martis/sso/azure/redirect');
    expect(Auth::guard()->check())->toBeFalse();
});

it('keeps the session when stillValid() says yes, and hands it the user and the external id', function () {
    config()->set('martis.auth.sso.providers.azure.revalidate_every_minutes', 10);
    ssoRevSignIn();

    $this->travel(11)->minutes();
    $this->getJson('/martis/api/tools')->assertOk();

    expect(SsoRevProvider::$calls)->toHaveCount(1)
        ->and(SsoRevProvider::$calls[0][0]->getAuthIdentifier())->toBe(ssoRevUserId())
        ->and(SsoRevProvider::$calls[0][1])->toBe('azure-sso');
});

it('asks at most once per interval per session', function () {
    config()->set('martis.auth.sso.providers.azure.revalidate_every_minutes', 10);
    ssoRevSignIn();

    // The sign-in itself is the first verification: nothing is asked within the interval.
    $this->getJson('/martis/api/tools')->assertOk();
    $this->travel(9)->minutes();
    $this->getJson('/martis/api/tools')->assertOk();
    expect(SsoRevProvider::$calls)->toHaveCount(0);

    $this->travel(2)->minutes();
    $this->getJson('/martis/api/tools')->assertOk();
    $this->getJson('/martis/api/tools')->assertOk();
    expect(SsoRevProvider::$calls)->toHaveCount(1);

    $this->travel(10)->minutes();
    $this->getJson('/martis/api/tools')->assertOk();
    expect(SsoRevProvider::$calls)->toHaveCount(2);
});

it('reports a throwing stillValid(), keeps the session and asks again on the next request', function () {
    Exceptions::fake();
    config()->set('martis.auth.sso.providers.azure.revalidate_every_minutes', 10);
    ssoRevSignIn();
    SsoRevProvider::$answer = new RuntimeException('the IdP is down');

    $this->travel(11)->minutes();
    $this->getJson('/martis/api/tools')->assertOk();
    $this->getJson('/martis/api/tools')->assertOk();

    expect(SsoRevProvider::$calls)->toHaveCount(2);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'the IdP is down');

    // The outage ends: the next answer counts, and the interval starts again.
    SsoRevProvider::$answer = true;
    $this->getJson('/martis/api/tools')->assertOk();
    $this->getJson('/martis/api/tools')->assertOk();
    expect(SsoRevProvider::$calls)->toHaveCount(3);
});

it('keeps asking through an outage but max_session_minutes is still the hard bound', function () {
    Exceptions::fake();
    config()->set('martis.auth.sso.providers.azure.revalidate_every_minutes', 10);
    config()->set('martis.auth.sso.providers.azure.max_session_minutes', 120);
    ssoRevSignIn();
    SsoRevProvider::$answer = new RuntimeException('the IdP is down');

    $this->travel(60)->minutes();
    $this->getJson('/martis/api/tools')->assertOk();
    $this->travel(61)->minutes();

    $this->getJson('/martis/api/tools')->assertUnauthorized()->assertJsonPath('sso_reauth_url', '/martis/sso/azure/redirect');
});

it('never calls stillValid() while revalidate_every_minutes is unset', function () {
    ssoRevSignIn();
    SsoRevProvider::$answer = false;

    $this->travel(5)->days();

    $this->getJson('/martis/api/tools')->assertOk();
    expect(SsoRevProvider::$calls)->toBe([]);
});

it('throws, naming the key, when revalidate_every_minutes is set on a provider without the contract', function () {
    $this->app->instance(AzureProvider::class, new SsoRevPlainProvider);
    config()->set('martis.auth.sso.providers.azure.revalidate_every_minutes', 10);
    ssoRevSignIn();
    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/martis/api/tools'))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.providers.azure.revalidate_every_minutes');
});

it('refuses a revalidate_every_minutes that is not a positive whole number, naming the key', function (mixed $value) {
    config()->set('martis.auth.sso.providers.azure.revalidate_every_minutes', $value);
    ssoRevSignIn();
    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/martis/api/tools'))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.providers.azure.revalidate_every_minutes');
})->with(['zero' => [0], 'text' => ['often']]);

// ---------------------------------------------------------------------------
// Where the middleware sits
// ---------------------------------------------------------------------------

it('runs in the authenticated stack, right after the guard and before the gates', function () {
    $authenticated = RouteMiddleware::authenticated();
    $api = RouteMiddleware::api();

    expect($authenticated)->toBe(['martis.auth', 'martis.sso.session', 'martis.impersonation.duration'])
        ->and(array_search('martis.sso.session', $api, true))->toBeLessThan(array_search('martis.2fa', $api, true))
        ->and(array_search('martis.sso.session', $api, true))->toBeLessThan(array_search('martis.password.changed', $api, true));
});

// ---------------------------------------------------------------------------
// 5. logout_url as a class
// ---------------------------------------------------------------------------

it('asks an invokable class for the logout URL, before the local logout, with the post-logout target', function () {
    config()->set('martis.auth.sso.providers.azure.logout_url', SsoRevLogoutUrl::class);
    ssoRevSignIn();

    $this->postJson('/martis/api/auth/logout')
        ->assertOk()
        ->assertJsonPath('logout_url', 'https://idp.example/end-session');

    expect(SsoRevLogoutUrl::$calls)->toHaveCount(1)
        ->and(SsoRevLogoutUrl::$calls[0][1]?->getAuthIdentifier())->toBe(ssoRevUserId())
        ->and(SsoRevLogoutUrl::$calls[0][2])->toBe(url('/martis/login'));
    expect(Auth::guard()->check())->toBeFalse();
});

it('redirects a page logout through the URL the class returns', function () {
    config()->set('martis.auth.sso.providers.azure.logout_url', SsoRevLogoutUrl::class);
    ssoRevSignIn();

    $this->post('/martis/api/auth/logout')->assertRedirect('https://idp.example/end-session');
});

it('signs out locally only when the class returns null', function () {
    config()->set('martis.auth.sso.providers.azure.logout_url', SsoRevLogoutUrl::class);
    SsoRevLogoutUrl::$answer = null;
    ssoRevSignIn();

    $this->postJson('/martis/api/auth/logout')
        ->assertOk()
        ->assertJsonMissingPath('logout_url');
    expect(Auth::guard()->check())->toBeFalse();
});

it('does not ask the class for a session that SSO did not open', function () {
    config()->set('martis.auth.sso.providers.azure.logout_url', SsoRevLogoutUrl::class);
    ssoRevPasswordUser();
    $this->postJson('/martis/api/auth/login', ['email' => 'p@example.com', 'password' => 'Password-Pass-1'])->assertOk();

    $this->postJson('/martis/api/auth/logout')->assertOk()->assertJsonMissingPath('logout_url');
    expect(SsoRevLogoutUrl::$calls)->toBe([]);
});

it('keeps the placeholder replacement of a plain URL', function () {
    config()->set('martis.auth.sso.providers.azure.logout_url', 'https://idp.example/logout?post={post_logout_redirect_uri}');
    ssoRevSignIn();

    $this->postJson('/martis/api/auth/logout')
        ->assertOk()
        ->assertJsonPath('logout_url', 'https://idp.example/logout?post='.rawurlencode(url('/martis/login')));
});

it('throws, naming the key, when logout_url names a class that is not invokable', function () {
    config()->set('martis.auth.sso.providers.azure.logout_url', SsoRevNotInvokable::class);
    ssoRevSignIn();
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/martis/api/auth/logout'))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.providers.azure.logout_url');
});

it('treats an empty string from the class as no federated logout', function () {
    config()->set('martis.auth.sso.providers.azure.logout_url', SsoRevLogoutUrl::class);
    SsoRevLogoutUrl::$answer = '';
    ssoRevSignIn();

    $this->postJson('/martis/api/auth/logout')->assertOk()->assertJsonMissingPath('logout_url');
});

it('throws, naming the key, when the class returns something that is not a string or null', function () {
    config()->set('martis.auth.sso.providers.azure.logout_url', SsoRevLooseLogoutUrl::class);
    ssoRevSignIn();
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/martis/api/auth/logout'))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.providers.azure.logout_url');
});

// ---------------------------------------------------------------------------
// The provider name of the URL is the canonical one (review H2)
// ---------------------------------------------------------------------------

it('answers 404 to a provider name in another letter case, on the callback and the redirect, and signs nobody in', function (string $path) {
    config()->set('martis.auth.sso.providers.azure.remember', false);
    config()->set('martis.auth.sso.providers.azure.max_session_minutes', 60);
    MartisSso::resolveRolesUsing(fn (array $external, $user, string $provider) => SsoRevRole::query()->get());

    $this->get('/martis/sso/'.$path)->assertNotFound();

    expect(auth()->check())->toBeFalse()
        ->and(session(SsoSession::SESSION_KEY))->toBeNull();
})->with([
    'callback, upper case' => ['AZURE/callback'],
    'callback, mixed case' => ['Azure/callback'],
    'redirect, upper case' => ['AZURE/redirect'],
    'redirect, mixed case' => ['Azure/redirect'],
]);

it('still ends the session of the canonical path by max_session_minutes (control of the letter-case refusal)', function () {
    config()->set('martis.auth.sso.providers.azure.remember', false);
    config()->set('martis.auth.sso.providers.azure.max_session_minutes', 60);
    MartisSso::resolveRolesUsing(fn (array $external, $user, string $provider) => SsoRevRole::query()->get());

    $this->get('/martis/sso/azure/callback')->assertRedirect();
    expect(session(SsoSession::SESSION_KEY))->toBe('azure');

    $this->travel(61)->minutes();
    $this->getJson('/martis/api/tools')->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// A remember cookie and a session limit cannot share a provider (review H1)
// ---------------------------------------------------------------------------

it('refuses a provider that remembers its users and sets a session limit, at sign-in, naming both keys', function (string $limit) {
    ssoRevRemember();
    config()->set('martis.auth.sso.providers.azure.'.$limit, 30);
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/martis/sso/azure/callback'))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.providers.azure.remember');
    expect(fn () => $this->get('/martis/sso/azure/callback'))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.providers.azure.'.$limit);

    expect(auth()->check())->toBeFalse()
        ->and(SsoRevUser::query()->count())->toBe(0);
})->with(['max_session_minutes', 'revalidate_every_minutes']);

it('refuses it on every request of an SSO session too, naming both keys', function (string $limit) {
    ssoRevRemember();
    ssoRevSignIn();
    config()->set('martis.auth.sso.providers.azure.'.$limit, 30);
    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/martis/api/tools'))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.providers.azure.'.$limit);
    expect(fn () => $this->getJson('/martis/api/tools'))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.providers.azure.remember');
})->with(['max_session_minutes', 'revalidate_every_minutes']);

it('accepts a remember cookie with no limit, a limit with no remember cookie, and an empty limit from the environment', function () {
    ssoRevRemember();
    config()->set('martis.auth.sso.providers.azure.max_session_minutes', '');
    config()->set('martis.auth.sso.providers.azure.revalidate_every_minutes', null);
    ssoRevSignIn();
    $this->getJson('/martis/api/tools')->assertOk();

    $this->postJson('/martis/api/auth/logout')->assertOk();
    config()->set('martis.auth.sso.providers.azure.remember', false);
    config()->set('martis.auth.sso.providers.azure.max_session_minutes', 60);
    ssoRevSignIn();
    $this->getJson('/martis/api/tools')->assertOk();
});

it('keeps a remembered session revocable, and revocation still ends the sessions of a user who has the cookies', function () {
    ssoRevRemember();
    [, $recaller, $marker] = ssoRevSignIn();

    MartisSso::revokeFor(ssoRevUserId());

    ssoRevExpireSession();
    ssoRevBrowser($recaller, $marker)->getJson('/martis/api/tools')->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// A logout_url class that throws never blocks the local sign-out (review M2)
// ---------------------------------------------------------------------------

it('signs out locally, without a federated URL, and reports it, when the logout_url class throws', function () {
    Exceptions::fake();
    config()->set('martis.auth.sso.providers.azure.logout_url', SsoRevThrowingLogoutUrl::class);
    ssoRevSignIn();

    $this->postJson('/martis/api/auth/logout')
        ->assertOk()
        ->assertJsonMissingPath('logout_url');

    expect(Auth::guard()->check())->toBeFalse()
        ->and(session(SsoSession::SESSION_KEY))->toBeNull();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'IdP discovery timed out');
    $this->getJson('/martis/api/tools')->assertUnauthorized();
});

it('redirects a page logout to the login page when the logout_url class throws', function () {
    Exceptions::fake();
    config()->set('martis.auth.sso.providers.azure.logout_url', SsoRevThrowingLogoutUrl::class);
    ssoRevSignIn();

    $this->post('/martis/api/auth/logout')->assertRedirect(route('martis.login'));

    expect(Auth::guard()->check())->toBeFalse();
});
