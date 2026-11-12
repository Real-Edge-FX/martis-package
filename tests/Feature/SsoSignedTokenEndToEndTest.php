<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
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
use Martis\Sso\Facades\MartisSso;
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

    expect(auth()->check())->toBeFalse();
    Event::assertDispatched(SsoFailed::class);
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
