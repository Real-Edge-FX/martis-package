<?php

declare(strict_types=1);

use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Auth\TwoFactorPass;
use Martis\Events\SsoFailed;
use Martis\Events\SsoLoggedIn;
use Martis\Http\Controllers\SsoController;
use Martis\Http\Middleware\SpendSignedToken;
use Martis\Sso\Facades\MartisSso;
use Martis\Sso\Providers\SignedTokenProvider;
use Martis\Sso\SsoManager;
use Psr\Log\LoggerInterface;

require_once __DIR__.'/../Support/signed-token.php';

// One app plays both panels: the issuer route of "panel A" and the signed-token
// provider of "panel B" (named panel_a there, after the app it signs in through).

class StE2eUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

/** The model of a panel that asks for email verification. */
class StE2eVerifyingUser extends StE2eUser implements MustVerifyEmail
{
    use MustVerifyEmailTrait;
}

class StE2eRole extends Model
{
    protected $table = 'st_e2e_roles';

    protected $guarded = [];

    public $timestamps = false;
}

class StE2eClaims
{
    public function __invoke(Authenticatable $user, string $audience): array
    {
        return ['email' => 'remote.person@b.example', 'name' => 'Remote Person', 'roles' => ['PMI ADMIN']];
    }
}

beforeEach(function () {
    Schema::dropIfExists('users');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->string('panel_a_external_id')->nullable()->unique();
        $table->rememberToken();
        $table->timestamps();
    });
    Schema::dropIfExists('st_e2e_roles');
    Schema::create('st_e2e_roles', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('panel_a_group_name')->nullable();
    });
    StE2eRole::create(['name' => 'admin', 'panel_a_group_name' => 'PMI ADMIN']);
    StE2eRole::create(['name' => 'editor', 'panel_a_group_name' => 'PMI EDITOR']);

    config(['auth.providers.users.model' => StE2eUser::class]);

    $this->keys = stKeys();
    $callback = url('/martis/sso/panel_a/callback');

    config(['martis.auth.sso.issuer' => [
        'enabled' => true,
        'issuer' => 'https://a.example',
        'private_key' => $this->keys['secret_b64'],
        'ttl_seconds' => 60,
        'claims' => StE2eClaims::class,
        'audiences' => ['panel-b' => ['callback_url' => $callback]],
    ]]);

    stConsumer([$this->keys['public_b64']], 'panel_a', [
        'authorize_url' => url('/martis/sso/issue/panel-b'),
        'audience' => 'panel-b',
        'role_column' => 'panel_a_group_name',
        'role_model' => StE2eRole::class,
        'identity_external_id_column' => 'panel_a_external_id',
    ]);

    // Routes are loaded at boot and the SSO ones depend on the master switch.
    /** @var Router $router */
    $router = $this->app['router'];
    $router->setRoutes(new RouteCollection);
    require __DIR__.'/../../routes/martis.php';
    $router->getRoutes()->refreshNameLookups();

    $this->app->make(SsoManager::class)->flushHooksForTesting();
    $this->synced = collect();
    MartisSso::syncRolesUsing(function (User $user, Collection $roles) {
        $this->synced = $roles;
    });
    Cache::flush();

    // The person who signs in at panel A.
    $this->issuerUser = StE2eUser::query()->create([
        'name' => 'Ada Admin', 'email' => 'ada@a.example', 'password' => Hash::make('x'), 'email_verified_at' => now(),
    ]);
});

afterEach(function () {
    Schema::dropIfExists('st_e2e_roles');
    $this->app->make(SsoManager::class)->flushHooksForTesting();
});

/** Panel B's login button, panel A's issuer, panel B's callback: the three redirects. */
function stRunFlow(): TestResponse
{
    $test = test();

    $start = $test->get('/martis/sso/panel_a/redirect');
    $start->assertRedirect();
    $toIssuer = (string) $start->headers->get('Location');
    expect($toIssuer)->toStartWith(url('/martis/sso/issue/panel-b').'?');

    // The browser follows to panel A, where Ada is signed in.
    $issue = $test->actingAs($test->issuerUser)
        ->withSession([TwoFactorPass::SESSION_KEY => (string) $test->issuerUser->getKey()])
        ->get($toIssuer);
    $issue->assertStatus(302);
    $toCallback = (string) $issue->headers->get('Location');
    expect($toCallback)->toStartWith(url('/martis/sso/panel_a/callback').'?token=');

    // And back to panel B with the token.
    return $test->get($toCallback);
}

it('signs a person in through the other panel: user created by external_id, roles mapped', function () {
    Event::fake([SsoLoggedIn::class]);

    $response = stRunFlow();

    $response->assertRedirect();
    expect(session('errors'))->toBeNull();

    $local = StE2eUser::query()->where('panel_a_external_id', (string) $this->issuerUser->getKey())->first();
    expect($local)->not->toBeNull()
        ->and($local->getKey())->not->toBe($this->issuerUser->getKey())
        ->and($local->email)->toBe('remote.person@b.example')
        ->and($local->name)->toBe('Remote Person')
        ->and(auth()->id())->toBe($local->getKey())
        ->and($this->synced->pluck('name')->all())->toBe(['admin']);

    Event::assertDispatched(SsoLoggedIn::class, fn (SsoLoggedIn $e) => $e->provider === 'panel_a'
        && $e->identity->externalId === (string) $this->issuerUser->getKey());
});

it('links the same person to the same local user on the second sign-in', function () {
    stRunFlow()->assertRedirect();
    $first = auth()->id();

    stRunFlow()->assertRedirect();

    expect(StE2eUser::query()->count())->toBe(2) // Ada (panel A's own row) and the one panel B created
        ->and(auth()->id())->toBe($first);
});

it('fires SsoFailed with the reason of a tampered token and logs no token or key material', function () {
    Event::fake([SsoFailed::class]);
    $logged = new ArrayObject;
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []) use ($logged) {
        $logged[] = ['message' => $message, 'context' => $context];
    });
    $logger->shouldReceive('info')->andReturnNull();
    Log::shouldReceive('channel')->andReturn($logger);

    $start = $this->get('/martis/sso/panel_a/redirect');
    $toIssuer = (string) $start->headers->get('Location');
    $issue = $this->actingAs($this->issuerUser)
        ->withSession([TwoFactorPass::SESSION_KEY => (string) $this->issuerUser->getKey()])
        ->get($toIssuer);
    $location = (string) $issue->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $token = $query['token'];
    // Flip a character inside the payload.
    $i = 20;
    $tampered = substr($token, 0, $i).($token[$i] === 'A' ? 'B' : 'A').substr($token, $i + 1);

    auth()->logout();
    $response = $this->get(url('/martis/sso/panel_a/callback').'?'.http_build_query(['token' => $tampered, 'state' => $query['state']]));

    $response->assertRedirect(route('martis.login'));
    expect(auth()->check())->toBeFalse();
    Event::assertDispatched(SsoFailed::class, fn (SsoFailed $e) => $e->provider === 'panel_a' && $e->reason === 'bad_signature');

    expect($logged)->toHaveCount(1)
        ->and($logged[0]['context']['reason'])->toBe('bad_signature');
    $context = json_encode($logged[0], JSON_THROW_ON_ERROR);
    foreach ([$token, $tampered, $this->keys['public_b64'], $this->keys['secret_b64'], bin2hex($this->keys['secret']), substr($token, 12, 40)] as $secret) {
        expect($context)->not->toContain($secret);
    }
});

it('refuses the same callback URL a second time', function () {
    $start = $this->get('/martis/sso/panel_a/redirect');
    $issue = $this->actingAs($this->issuerUser)
        ->withSession([TwoFactorPass::SESSION_KEY => (string) $this->issuerUser->getKey()])
        ->get((string) $start->headers->get('Location'));
    $callback = (string) $issue->headers->get('Location');

    $this->get($callback)->assertRedirect();
    expect(auth()->check())->toBeTrue();

    auth()->logout();
    Event::fake([SsoFailed::class]);
    $this->get($callback)->assertRedirect(route('martis.login'));

    // The jti is used up before the state is looked at (v2.22.0), so it is the jti that refuses here.
    expect(auth()->check())->toBeFalse();
    Event::assertDispatched(SsoFailed::class, fn (SsoFailed $e) => $e->reason === 'replayed');
});

it('refuses the same callback URL on the jti when the state is back in the session', function () {
    $start = $this->get('/martis/sso/panel_a/redirect');
    $toIssuer = (string) $start->headers->get('Location');
    $stateEntry = session('martis_sso_state.panel_a');
    $issue = $this->actingAs($this->issuerUser)
        ->withSession([TwoFactorPass::SESSION_KEY => (string) $this->issuerUser->getKey()])
        ->get($toIssuer);
    $callback = (string) $issue->headers->get('Location');

    $this->get($callback)->assertRedirect();
    expect(auth()->check())->toBeTrue();

    // Two tabs, a session restore: the state is reproduced. The one-time store is the second lock.
    auth()->logout();
    session()->put('martis_sso_state.panel_a', $stateEntry);
    Event::fake([SsoFailed::class]);
    $this->get($callback)->assertRedirect(route('martis.login'));

    expect(auth()->check())->toBeFalse();
    Event::assertDispatched(SsoFailed::class, fn (SsoFailed $e) => $e->reason === 'replayed');
});

it('refuses a token minted for a browser session other than the one that started the flow', function () {
    // The attacker starts a flow in their own browser and has the victim open the issue URL.
    $start = $this->get('/martis/sso/panel_a/redirect');
    $toIssuer = (string) $start->headers->get('Location');
    parse_str((string) parse_url($toIssuer, PHP_URL_QUERY), $query);

    $issue = $this->actingAs($this->issuerUser)
        ->withSession([TwoFactorPass::SESSION_KEY => (string) $this->issuerUser->getKey()])
        ->get($toIssuer);
    $callback = (string) $issue->headers->get('Location');

    // The victim's browser has no state of its own for panel_a: a fresh session.
    $this->flushSession();
    auth()->logout();
    Event::fake([SsoFailed::class]);

    $this->get($callback)->assertRedirect(route('martis.login'));

    expect(auth()->check())->toBeFalse();
    Event::assertDispatched(SsoFailed::class, fn (SsoFailed $e) => $e->reason === 'state');
});

it('does not let the attacker replay, in their own browser, a token the victim browser failed on the state', function () {
    // The attacker starts the flow in their browser and has the signed-in victim open the issue URL.
    $start = $this->get('/martis/sso/panel_a/redirect');
    $toIssuer = (string) $start->headers->get('Location');
    $attackerSession = session()->all();

    $issue = $this->actingAs($this->issuerUser)
        ->withSession([TwoFactorPass::SESSION_KEY => (string) $this->issuerUser->getKey()])
        ->get($toIssuer);
    $callback = (string) $issue->headers->get('Location');

    // The callback lands in the victim's browser: no state of its own, so it fails there.
    $this->flushSession();
    auth()->logout();
    Event::fake([SsoFailed::class]);
    $this->get($callback)->assertRedirect(route('martis.login'));
    Event::assertDispatched(SsoFailed::class, fn (SsoFailed $e) => $e->reason === 'state');
    expect(auth()->check())->toBeFalse();

    // The URL leaks (an access log) and is opened in the attacker's browser, which holds the matching state.
    $this->flushSession();
    $this->withSession($attackerSession);
    Event::fake([SsoFailed::class]);
    $this->get($callback)->assertRedirect(route('martis.login'));

    expect(auth()->check())->toBeFalse();
    Event::assertDispatched(SsoFailed::class, fn (SsoFailed $e) => $e->reason === 'replayed');
});

it('uses the token up even when the victim callback is throttled (429): the replay from another address is refused', function () {
    // The attacker starts the flow in their browser and lures the signed-in victim to the issue URL.
    $start = $this->get('/martis/sso/panel_a/redirect');
    $toIssuer = (string) $start->headers->get('Location');
    $attackerSession = session()->all();
    $issue = $this->actingAs($this->issuerUser)
        ->withSession([TwoFactorPass::SESSION_KEY => (string) $this->issuerUser->getKey()])
        ->get($toIssuer);
    $callback = (string) $issue->headers->get('Location');

    // The lured page first fires callbacks at the victim's address until its throttle bucket is full.
    $this->flushSession();
    auth()->logout();
    for ($i = 0; $i < 20; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get(url('/martis/sso/panel_a/callback').'?token=x');
    }
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get($callback)->assertStatus(429);

    // The attacker, from another address, holds the state and replays the leaked URL: the token is used up.
    $this->flushSession();
    $this->withSession($attackerSession);
    Event::fake([SsoFailed::class]);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get($callback)->assertRedirect(route('martis.login'));

    expect(auth()->check())->toBeFalse();
    Event::assertDispatched(SsoFailed::class, fn (SsoFailed $e) => $e->reason === 'replayed');
});

it('keeps the callback rate limit of every provider, after the one-time check', function () {
    $middleware = app('router')->getRoutes()->getByName('martis.sso.callback')->gatherMiddleware();

    expect($middleware)->toContain(SpendSignedToken::class.':20,1');

    // The limit still answers 429 on the 21st request of an address, for any provider name.
    for ($i = 0; $i < 20; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->get('/martis/sso/nobody/callback')->assertRedirect();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->get('/martis/sso/nobody/callback')->assertStatus(429);
});

it('answers 405 to a HEAD request on the callback and does not spend the token', function () {
    $start = $this->get('/martis/sso/panel_a/redirect');
    $toIssuer = (string) $start->headers->get('Location');
    $mine = session()->all();
    $issue = $this->actingAs($this->issuerUser)
        ->withSession([TwoFactorPass::SESSION_KEY => (string) $this->issuerUser->getKey()])
        ->get($toIssuer);
    $callback = (string) $issue->headers->get('Location');
    auth()->logout();
    $this->flushSession();

    // A link scanner or a gateway that probes with HEAD.
    $this->call('HEAD', $callback)->assertStatus(405)->assertHeader('Allow', 'GET');

    // The browser then arrives and signs in: nothing was read or recorded.
    $this->flushSession();
    $this->withSession($mine);
    $this->get($callback)->assertRedirect();
    expect(auth()->check())->toBeTrue();
});

it('does not read a provider name in another letter case, so the token is not spent by it', function () {
    $start = $this->get('/martis/sso/panel_a/redirect');
    $issue = $this->actingAs($this->issuerUser)
        ->withSession([TwoFactorPass::SESSION_KEY => (string) $this->issuerUser->getKey()])
        ->get((string) $start->headers->get('Location'));
    $lower = (string) $issue->headers->get('Location');
    $upper = str_replace('/sso/panel_a/callback', '/sso/PANEL_A/callback', $lower);
    auth()->logout();

    $this->get($upper)->assertNotFound();
    $this->get($lower)->assertRedirect();

    expect(auth()->check())->toBeTrue();
});

it('throws a configuration error of the provider block on the callback, on both routes, and refuses nobody for it', function () {
    $start = $this->get('/martis/sso/panel_a/redirect');
    $issue = $this->actingAs($this->issuerUser)
        ->withSession([TwoFactorPass::SESSION_KEY => (string) $this->issuerUser->getKey()])
        ->get((string) $start->headers->get('Location'));
    $callback = (string) $issue->headers->get('Location');
    auth()->logout();
    config(['martis.auth.sso.providers.panel_a.environment_claim' => 'deployment']);
    Event::fake([SsoFailed::class]);

    // Through the route: SpendSignedToken ran first.
    expect(fn () => $this->withoutExceptionHandling()->get($callback))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.providers.panel_a.environment_claim');
    $this->withExceptionHandling()->get($callback)->assertStatus(500);

    // The provider alone (no middleware): the controller answers the same.
    $request = Request::create($callback);
    $request->setLaravelSession(session()->driver());
    expect(fn () => app(SsoController::class)->callback($request, 'panel_a'))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.providers.panel_a.environment_claim');

    Event::assertNotDispatched(SsoFailed::class);
});

it('leaves the callback of another provider alone', function () {
    config(['martis.auth.sso.providers.azure' => ['enabled' => true, 'driver' => 'azure']]);

    expect(app(SsoManager::class)->driver('azure'))->not->toBeInstanceOf(SignedTokenProvider::class);
    $this->get('/martis/sso/azure/callback?token=whatever')->assertRedirect(route('martis.login'));
});

it('answers 404 to a callback path in another letter case, so an account is never adopted under defaults', function () {
    // A local row of panel B that holds the address the issuer signs. The provider is
    // configured to match by external_id, which refuses this row.
    $victim = StE2eUser::query()->create([
        'name' => 'Victim', 'email' => 'remote.person@b.example', 'password' => Hash::make('x'), 'email_verified_at' => now(),
    ]);
    MartisSso::resolveRolesUsing(fn (array $external, $user, string $provider) => StE2eRole::query()->whereIn('panel_a_group_name', $external)->get());

    $start = $this->get('/martis/sso/panel_a/redirect');
    $issue = $this->actingAs($this->issuerUser)
        ->withSession([TwoFactorPass::SESSION_KEY => (string) $this->issuerUser->getKey()])
        ->get((string) $start->headers->get('Location'));
    auth()->logout();
    $lower = (string) $issue->headers->get('Location');
    $upper = str_replace('/sso/panel_a/callback', '/sso/PANEL_A/callback', $lower);

    $this->get($upper)->assertNotFound();
    expect(auth()->check())->toBeFalse()
        ->and($victim->fresh()->panel_a_external_id)->toBeNull();

    // The canonical path of the same URL is the one that applies the provider's config: the row is refused.
    $this->get($lower)->assertRedirect(route('martis.login'));
    expect(auth()->check())->toBeFalse()
        ->and($victim->fresh()->panel_a_external_id)->toBeNull();
});

it('answers 404 to the redirect of a provider name in another letter case and starts no flow', function () {
    $this->get('/martis/sso/Panel_A/redirect')->assertNotFound();

    expect(session('martis_sso_state.panel_a'))->toBeNull()
        ->and(session('martis_sso_state.Panel_A'))->toBeNull();
});

// ---------------------------------------------------------------------------
// An email the issuing panel never verified (review M3)
// ---------------------------------------------------------------------------

it('does not adopt a local row by an email the issuing panel has not verified', function (string $strategy) {
    config([
        'auth.providers.users.model' => StE2eVerifyingUser::class,
        'martis.auth.sso.providers.panel_a.identity_match_attribute' => $strategy,
        'martis.auth.sso.providers.panel_a.link_by_email' => true,
    ]);
    $this->issuerUser->forceFill(['email_verified_at' => null])->save();
    $this->issuerUser = StE2eVerifyingUser::query()->findOrFail($this->issuerUser->getKey());
    $victim = StE2eUser::query()->create([
        'name' => 'Victim', 'email' => 'remote.person@b.example', 'password' => Hash::make('x'), 'email_verified_at' => now(),
    ]);
    Event::fake([SsoFailed::class]);

    stRunFlow()->assertRedirect(route('martis.login'));

    // The browser still holds the issuer's session of this one-app test: it is not signed in as the victim.
    expect(auth()->id())->not->toBe($victim->getKey())
        ->and($victim->fresh()->panel_a_external_id)->toBeNull();
    Event::assertDispatched(SsoFailed::class, fn (SsoFailed $e) => $e->reason === 'email_unverified');
})->with(['email', 'external_id']);

it('adopts the same row once the issuing panel has verified the email', function () {
    config([
        'auth.providers.users.model' => StE2eVerifyingUser::class,
        'martis.auth.sso.providers.panel_a.identity_match_attribute' => 'email',
    ]);
    $this->issuerUser = StE2eVerifyingUser::query()->findOrFail($this->issuerUser->getKey());
    $victim = StE2eUser::query()->create([
        'name' => 'Remote', 'email' => 'remote.person@b.example', 'password' => Hash::make('x'), 'email_verified_at' => now(),
    ]);

    stRunFlow()->assertRedirect();

    expect(auth()->id())->toBe($victim->getKey());
});

it('creates a new user from an unverified email at the issuing panel: only adopting is refused', function () {
    config(['auth.providers.users.model' => StE2eVerifyingUser::class]);
    $this->issuerUser->forceFill(['email_verified_at' => null])->save();
    $this->issuerUser = StE2eVerifyingUser::query()->findOrFail($this->issuerUser->getKey());

    stRunFlow()->assertRedirect();

    expect(auth()->check())->toBeTrue()
        ->and(auth()->user()->email)->toBe('remote.person@b.example');
});
