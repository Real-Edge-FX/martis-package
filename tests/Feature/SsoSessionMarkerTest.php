<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Martis\Sso\Facades\MartisSso;
use Martis\Sso\Providers\AzureProvider;
use Martis\Sso\SsoIdentity;
use Martis\Sso\SsoManager;
use Martis\Sso\SsoSession;
use Symfony\Component\HttpFoundation\Cookie;

// ===========================================================================
// The SSO origin of a session (Martis\Sso\SsoSession, v2.3.0). A provider
// that opts in to remember-me (`remember`, v2.4.0) signs in with it, so the
// session the SSO callback opens can end
// (SESSION_LIFETIME) while the remember cookie signs the same user back in,
// into a new session. The origin must survive that re-login: the forced
// password change gate never holds an SSO session (the user knows no
// password) and the sign-out goes through the IdP's federated logout. Every
// other sign-in, and the sign-out, drops it.
// ===========================================================================

class SsoMarkerUser extends User
{
    protected $table = 'users';

    protected $guarded = [];

    protected $casts = ['must_change_password' => 'boolean'];
}

class SsoMarkerRole extends Model
{
    protected $table = 'sso_marker_roles';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
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
    Schema::dropIfExists('sso_marker_roles');
    Schema::create('sso_marker_roles', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('azure_group_name')->nullable();
    });
    SsoMarkerRole::create(['name' => 'admin', 'azure_group_name' => 'ADMINS']);

    config([
        'auth.providers.users.model' => SsoMarkerUser::class,
        'martis.auth.password_change.enabled' => true,
        'martis.auth.sso.enabled' => true,
    ]);

    // The SSO routes register only when SSO is on at boot.
    $this->app['router']->setRoutes(new RouteCollection);
    require __DIR__.'/../../routes/martis.php';

    config()->set('martis.auth.sso.providers.azure', [
        'enabled' => true,
        'driver' => 'azure',
        'role_strategy' => 'column',
        'role_column' => 'azure_group_name',
        'role_model' => SsoMarkerRole::class,
        'auto_create_user' => true,
        'identity_match_attribute' => 'email',
        'sync_user_attributes' => ['name', 'email'],
        'sync_roles' => true,
        'permission_adapter' => 'callable',
        'on_no_role_match' => 'deny',
        'logout_url' => 'https://idp.example/logout',
        // The origin only has to survive a remember-me re-login when the provider opts in to one.
        'remember' => true,
    ]);

    $this->app->make(SsoManager::class)->flushHooksForTesting();
    MartisSso::syncRolesUsing(function (User $user, Collection $roles) {});

    // An SSO-provisioned user: a random password the user never knew, flagged.
    MartisSso::resolveUserUsing(fn (SsoIdentity $id, string $provider) => SsoMarkerUser::query()->updateOrCreate(
        ['email' => $id->email],
        ['name' => $id->name, 'password' => Hash::make(Str::random(40)), 'must_change_password' => true],
    ));
    $identity = new SsoIdentity(provider: 'azure', externalId: 'azure-sso', email: 'sso@example.com', name: 'Sso', externalRoles: ['ADMINS']);
    $this->app->instance(AzureProvider::class, new class($identity) extends AzureProvider
    {
        public function __construct(private SsoIdentity $stub) {}

        public function resolveIdentity(Request $request): SsoIdentity
        {
            return $this->stub;
        }

        public function redirect(Request $request): RedirectResponse
        {
            return redirect('/');
        }
    });
});

afterEach(function () {
    Schema::dropIfExists('sso_marker_roles');
    $this->app->make(SsoManager::class)->flushHooksForTesting();
});

function ssoMarkerCookie(TestResponse $response, string $prefix): ?Cookie
{
    return collect($response->headers->getCookies())->first(fn (Cookie $cookie) => str_starts_with($cookie->getName(), $prefix));
}

/**
 * The session ends (SESSION_LIFETIME); the browser keeps its cookies. The
 * test app keeps the cookie jar between requests and Laravel never empties
 * its queue, so it is flushed too: each answer then shows only its own.
 */
function ssoMarkerExpireSession(): void
{
    test()->flushSession();
    Auth::forgetGuards();
    app('cookie')->flushQueuedCookies();
}

/** A request carrying the given cookies as the browser holds them (already encrypted). */
function ssoMarkerBrowser(Cookie ...$cookies): mixed
{
    $request = test()->withCredentials();
    foreach ($cookies as $cookie) {
        $request = $request->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    return $request;
}

it('keeps an SSO session out of the gate after the remember cookie signs the user back in', function () {
    $callback = $this->get('/martis/sso/azure/callback')->assertRedirect();
    $this->getJson('/martis/api/tools')->assertOk(); // the SSO session itself

    $recaller = ssoMarkerCookie($callback, 'remember_');
    $marker = ssoMarkerCookie($callback, SsoSession::COOKIE);
    expect($recaller)->not->toBeNull()->and($marker)->not->toBeNull();

    ssoMarkerExpireSession();

    ssoMarkerBrowser($recaller, $marker)->getJson('/martis/api/tools')->assertOk();
    expect(Auth::guard()->viaRemember())->toBeTrue()
        ->and(session(SsoSession::SESSION_KEY))->toBe('azure');
    ssoMarkerBrowser($recaller, $marker)->getJson('/martis/api/auth/user')
        ->assertJsonMissing(['password_change_pending' => true]);
});

it('sends a remember-me re-login of an SSO session through the federated logout', function () {
    $callback = $this->get('/martis/sso/azure/callback');
    $recaller = ssoMarkerCookie($callback, 'remember_');
    $marker = ssoMarkerCookie($callback, SsoSession::COOKIE);
    ssoMarkerExpireSession();

    $logout = ssoMarkerBrowser($recaller, $marker)->postJson('/martis/api/auth/logout')
        ->assertOk()
        ->assertJsonPath('logout_url', 'https://idp.example/logout');

    expect(ssoMarkerCookie($logout, SsoSession::COOKIE)?->isCleared())->toBeTrue();
});

it('holds a flagged password user the remember cookie signs back in (control)', function () {
    SsoMarkerUser::query()->create(['name' => 'P', 'email' => 'p@example.com', 'password' => Hash::make('Temporary-Pass-1'), 'must_change_password' => true]);

    $login = $this->postJson('/martis/api/auth/login', ['email' => 'p@example.com', 'password' => 'Temporary-Pass-1', 'keep_signed_in' => true])
        ->assertJson(['password_change_required' => true]);
    $recaller = ssoMarkerCookie($login, 'remember_');
    expect($recaller)->not->toBeNull();
    ssoMarkerExpireSession();

    ssoMarkerBrowser($recaller)->getJson('/martis/api/tools')->assertStatus(409);
    expect(Auth::guard()->viaRemember())->toBeTrue();
});

it('ignores an SSO cookie that names another user', function () {
    $user = SsoMarkerUser::query()->create(['name' => 'P', 'email' => 'p@example.com', 'password' => Hash::make('Temporary-Pass-1'), 'must_change_password' => true]);
    $login = $this->postJson('/martis/api/auth/login', ['email' => 'p@example.com', 'password' => 'Temporary-Pass-1', 'keep_signed_in' => true]);
    $recaller = ssoMarkerCookie($login, 'remember_');
    ssoMarkerExpireSession();

    $this->withCredentials()
        ->withUnencryptedCookie($recaller->getName(), $recaller->getValue())
        ->withCookie(SsoSession::COOKIE, Crypt::encryptString(($user->getKey() + 1).'|azure'))
        ->getJson('/martis/api/tools')
        ->assertStatus(409);
});

it('ignores an SSO cookie Martis did not sign', function () {
    $user = SsoMarkerUser::query()->create(['name' => 'P', 'email' => 'p@example.com', 'password' => Hash::make('Temporary-Pass-1'), 'must_change_password' => true]);
    $login = $this->postJson('/martis/api/auth/login', ['email' => 'p@example.com', 'password' => 'Temporary-Pass-1', 'keep_signed_in' => true]);
    $recaller = ssoMarkerCookie($login, 'remember_');
    ssoMarkerExpireSession();

    // The app's EncryptCookies encrypts it in transit, but the value inside is
    // not one Martis encrypted: a browser cannot mint an SSO origin.
    $this->withCredentials()
        ->withUnencryptedCookie($recaller->getName(), $recaller->getValue())
        ->withCookie(SsoSession::COOKIE, $user->getKey().'|azure')
        ->getJson('/martis/api/tools')
        ->assertStatus(409);
});

it('drops the SSO cookie on every other sign-in and on both sign-outs', function () {
    SsoMarkerUser::query()->create(['name' => 'P', 'email' => 'p@example.com', 'password' => Hash::make('Temporary-Pass-1')]);
    $marker = ssoMarkerCookie($this->get('/martis/sso/azure/callback'), SsoSession::COOKIE);
    expect($marker)->not->toBeNull();

    $cleared = function (TestResponse $response): bool {
        return ssoMarkerCookie($response, SsoSession::COOKIE)?->isCleared() === true;
    };

    ssoMarkerExpireSession();
    expect($cleared(ssoMarkerBrowser($marker)->postJson('/martis/api/auth/login', ['email' => 'p@example.com', 'password' => 'Temporary-Pass-1'])))->toBeTrue();

    ssoMarkerExpireSession();
    expect($cleared(ssoMarkerBrowser($marker)->post('/martis/login', ['email' => 'p@example.com', 'password' => 'Temporary-Pass-1'])))->toBeTrue();

    ssoMarkerExpireSession();
    expect($cleared(ssoMarkerBrowser($marker)->post('/martis/logout')))->toBeTrue();

    ssoMarkerExpireSession();
    expect($cleared(ssoMarkerBrowser($marker)->postJson('/martis/api/auth/logout')))->toBeTrue();
});

/** The cookie as Martis writes it, for a user, a provider, a nonce and an issue time. */
function ssoMarkerForgedCookie(int|string $userId, string $nonce, ?int $issuedAt = null, string $provider = 'azure'): string
{
    return Crypt::encryptString((string) json_encode(['u' => (string) $userId, 'p' => $provider, 'n' => $nonce, 'i' => $issuedAt ?? time()]));
}

/**
 * The claims of the origin cookie a response set: the app's EncryptCookies
 * encrypted it again, behind the prefix that ties it to the cookie's name.
 */
function ssoMarkerClaims(Cookie $cookie): array
{
    $outer = Crypt::decryptString($cookie->getValue());

    return json_decode(Crypt::decryptString(substr($outer, (int) strpos($outer, '|') + 1)), true);
}

it('binds the cookie to the user, the provider, an issue time and a nonce the server holds', function () {
    $callback = $this->get('/martis/sso/azure/callback');
    $claims = ssoMarkerClaims(ssoMarkerCookie($callback, SsoSession::COOKIE));

    expect($claims)->toHaveKeys(['u', 'p', 'n', 'i'])
        ->and($claims['p'])->toBe('azure')
        ->and($claims['i'])->toBeInt()->toBeGreaterThan(time() - 60)
        ->and(strlen($claims['n']))->toBeGreaterThanOrEqual(32);
});

it('stops a cookie kept from an SSO sign-in once the user signs in with a password (F064)', function () {
    $callback = $this->get('/martis/sso/azure/callback');
    $recaller = ssoMarkerCookie($callback, 'remember_');
    $saved = ssoMarkerCookie($callback, SsoSession::COOKIE);
    $user = SsoMarkerUser::query()->where('email', 'sso@example.com')->firstOrFail();
    $user->forceFill(['password' => Hash::make('Temporary-Pass-1'), 'must_change_password' => true])->save();

    // The operator flags the account; the user signs in with the password.
    ssoMarkerExpireSession();
    $login = $this->postJson('/martis/api/auth/login', ['email' => 'sso@example.com', 'password' => 'Temporary-Pass-1', 'keep_signed_in' => true])
        ->assertJson(['password_change_required' => true]);
    $passwordRecaller = ssoMarkerCookie($login, 'remember_');
    ssoMarkerExpireSession();

    // Re-attaching the saved cookie, as the user did: the origin is gone, the gate holds.
    ssoMarkerBrowser($passwordRecaller, $saved)->getJson('/martis/api/tools')->assertStatus(409);
    expect(session(SsoSession::SESSION_KEY))->toBeNull();
});

it('keeps the cookie of each device valid, and the sign-out drops only the nonce of its own browser', function () {
    $first = ssoMarkerCookie($this->get('/martis/sso/azure/callback'), SsoSession::COOKIE);
    ssoMarkerExpireSession();
    $second = ssoMarkerCookie($this->get('/martis/sso/azure/callback'), SsoSession::COOKIE);
    ssoMarkerExpireSession();
    $user = SsoMarkerUser::query()->where('email', 'sso@example.com')->firstOrFail();

    // The flagged user is let through while a cookie of theirs is valid: two
    // devices, two nonces, the second sign-in did not replace the first.
    $opensWith = function (Cookie $marker) use ($user): int {
        // A fresh session each time: the origin is written back to the session it opens.
        ssoMarkerExpireSession();

        return ssoMarkerBrowser($marker)->actingAs($user)->getJson('/martis/api/tools')->getStatusCode();
    };
    expect($opensWith($first))->toBe(200)->and($opensWith($second))->toBe(200);

    Auth::forgetGuards();
    ssoMarkerBrowser($second)->actingAs($user)->postJson('/martis/api/auth/logout')->assertOk();

    expect($opensWith($first))->toBe(200)->and($opensWith($second))->toBe(409);
});

it('refuses a cookie whose nonce the server does not hold', function () {
    $callback = $this->get('/martis/sso/azure/callback');
    $recaller = ssoMarkerCookie($callback, 'remember_');
    $user = SsoMarkerUser::query()->where('email', 'sso@example.com')->firstOrFail();
    ssoMarkerExpireSession();

    $this->withCredentials()
        ->withUnencryptedCookie($recaller->getName(), $recaller->getValue())
        ->withCookie(SsoSession::COOKIE, ssoMarkerForgedCookie($user->getKey(), 'a-nonce-the-server-never-issued'))
        ->getJson('/martis/api/tools')
        ->assertStatus(409);
    expect(session(SsoSession::SESSION_KEY))->toBeNull();
});

it('refuses a cookie older than the remember lifetime, and one from the future', function (int $issuedAgoSeconds) {
    $callback = $this->get('/martis/sso/azure/callback');
    $recaller = ssoMarkerCookie($callback, 'remember_');
    $claims = ssoMarkerClaims(ssoMarkerCookie($callback, SsoSession::COOKIE));
    ssoMarkerExpireSession();

    $this->withCredentials()
        ->withUnencryptedCookie($recaller->getName(), $recaller->getValue())
        ->withCookie(SsoSession::COOKIE, ssoMarkerForgedCookie($claims['u'], $claims['n'], time() - $issuedAgoSeconds))
        ->getJson('/martis/api/tools')
        ->assertStatus(409);
})->with([
    'a second past the lifetime' => [576000 * 60 + 5],
    'issued in the future' => [-3600],
]);

it('refuses the cookie of an SSO sign-in once the server forgot the nonces (cache flushed)', function () {
    $callback = $this->get('/martis/sso/azure/callback');
    $recaller = ssoMarkerCookie($callback, 'remember_');
    $marker = ssoMarkerCookie($callback, SsoSession::COOKIE);
    ssoMarkerExpireSession();
    Cache::flush();

    ssoMarkerBrowser($recaller, $marker)->getJson('/martis/api/tools')->assertStatus(409);
});

it('refuses a cookie with the old id|provider shape', function () {
    $callback = $this->get('/martis/sso/azure/callback');
    $recaller = ssoMarkerCookie($callback, 'remember_');
    $user = SsoMarkerUser::query()->where('email', 'sso@example.com')->firstOrFail();
    ssoMarkerExpireSession();

    $this->withCredentials()
        ->withUnencryptedCookie($recaller->getName(), $recaller->getValue())
        ->withCookie(SsoSession::COOKIE, Crypt::encryptString($user->getKey().'|azure'))
        ->getJson('/martis/api/tools')
        ->assertStatus(409);
});

// ---------------------------------------------------------------------------
// F056: the remember-me cookie is the provider's opt-in
// ---------------------------------------------------------------------------

it('signs in without the remember-me cookie unless the provider opts in', function () {
    config()->set('martis.auth.sso.providers.azure.remember', false);

    $callback = $this->get('/martis/sso/azure/callback')->assertRedirect();

    expect(ssoMarkerCookie($callback, 'remember_'))->toBeNull()
        ->and(ssoMarkerCookie($callback, SsoSession::COOKIE))->toBeNull()
        ->and(session(SsoSession::SESSION_KEY))->toBe('azure');
    $this->getJson('/martis/api/tools')->assertOk();
    expect(SsoMarkerUser::query()->where('email', 'sso@example.com')->value('remember_token'))->toBeNull();
});

it('does not remember an SSO sign-in when the provider sets nothing', function () {
    $config = config('martis.auth.sso.providers.azure');
    unset($config['remember']);
    config()->set('martis.auth.sso.providers.azure', $config);

    $callback = $this->get('/martis/sso/azure/callback')->assertRedirect();

    expect(ssoMarkerCookie($callback, 'remember_'))->toBeNull();
});

it('lets the session end the access when remember-me is off: no cookie signs the user back in', function () {
    config()->set('martis.auth.sso.providers.azure.remember', false);
    $callback = $this->get('/martis/sso/azure/callback');
    expect(ssoMarkerCookie($callback, 'remember_'))->toBeNull();

    ssoMarkerExpireSession();

    $this->getJson('/martis/api/tools')->assertUnauthorized();
});

it('remembers an SSO sign-in when the provider opts in', function () {
    config()->set('martis.auth.sso.providers.azure.remember', true);

    $callback = $this->get('/martis/sso/azure/callback')->assertRedirect();

    expect(ssoMarkerCookie($callback, 'remember_'))->not->toBeNull()
        ->and(ssoMarkerCookie($callback, SsoSession::COOKIE))->not->toBeNull();
});
