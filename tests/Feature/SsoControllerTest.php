<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Martis\Sso\Facades\MartisSso;
use Martis\Sso\Providers\AzureProvider;
use Martis\Sso\SsoIdentity;
use Martis\Sso\SsoManager;

class SsoTestUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class SsoTestRoleModel extends Model
{
    protected $table = 'sso_demo_roles';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    } elseif (! Schema::hasColumn('users', 'remember_token')) {
        Schema::table('users', function ($table) {
            $table->rememberToken();
        });
    }

    Schema::dropIfExists('sso_demo_roles');
    Schema::create('sso_demo_roles', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('azure_group_name')->nullable();
    });

    SsoTestRoleModel::create(['name' => 'admin', 'azure_group_name' => 'PMI ADMIN']);
    SsoTestRoleModel::create(['name' => 'editor', 'azure_group_name' => 'PMI EDITOR']);

    config()->set('martis.auth.sso.enabled', true);

    // Routes are loaded once at app boot and gate registration on
    // `martis.auth.sso.enabled`. Force a reload after toggling the
    // config so the SSO endpoints actually exist for these tests.
    /** @var Router $router */
    $router = $this->app['router'];
    $fresh = new RouteCollection;
    $router->setRoutes($fresh);
    require __DIR__.'/../../routes/martis.php';

    config()->set('martis.auth.sso.providers.azure', [
        'enabled' => true,
        'driver' => 'azure',
        'role_strategy' => 'column',
        'role_column' => 'azure_group_name',
        'role_model' => SsoTestRoleModel::class,
        'auto_create_user' => true,
        'identity_match_attribute' => 'email',
        'sync_user_attributes' => ['name', 'email'],
        'sync_roles' => true,
        'permission_adapter' => 'callable',     // intercept role sync from tests
        'on_no_role_match' => 'deny',
    ]);

    /** @var SsoManager $manager */
    $manager = $this->app->make(SsoManager::class);
    $manager->flushHooksForTesting();

    // Tests need to know which roles got synced. We capture them via
    // the callable adapter rather than running a real Spatie pivot.
    $this->capturedRoles = collect();
    MartisSso::syncRolesUsing(function (User $user, Collection $roles) {
        $this->capturedRoles = $roles;
    });
});

afterEach(function () {
    Schema::dropIfExists('sso_demo_roles');
    /** @var SsoManager $manager */
    $manager = $this->app->make(SsoManager::class);
    $manager->flushHooksForTesting();
});

// -----------------------------------------------------------------------------
// Identity stub — bypass real Socialite by overriding resolveUserUsing.
// -----------------------------------------------------------------------------

function stubIdentity(string $email, array $externalRoles = []): SsoIdentity
{
    return new SsoIdentity(
        provider: 'azure',
        externalId: 'azure-'.md5($email),
        email: $email,
        name: ucfirst(explode('@', $email)[0]),
        externalRoles: $externalRoles,
    );
}

it('callback creates a new user on first SSO login', function () {
    $identity = stubIdentity('newuser@example.com', ['PMI ADMIN']);

    // Inject the identity by overriding the resolver hook with a closure
    // that returns the user we want — bypasses both the network round-
    // trip AND the real provider's implementation.
    MartisSso::resolveUserUsing(function (SsoIdentity $id, string $provider) {
        return SsoTestUser::query()->updateOrCreate(
            ['email' => $id->email],
            ['name' => $id->name, 'password' => bcrypt('x')],
        );
    });

    // Stub the provider entirely so resolveIdentity returns our identity.
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

    $response = $this->get('/martis/sso/azure/callback');

    $response->assertRedirect();
    expect(SsoTestUser::query()->where('email', 'newuser@example.com')->exists())->toBeTrue();
    expect(auth()->check())->toBeTrue();
});

it('callback updates existing user attributes from the SSO identity', function () {
    SsoTestUser::query()->create(['name' => 'Old Name', 'email' => 'existing@example.com', 'password' => bcrypt('x')]);

    $identity = stubIdentity('existing@example.com', ['PMI ADMIN']);

    MartisSso::resolveUserUsing(function (SsoIdentity $id) {
        $u = SsoTestUser::query()->where('email', $id->email)->first();
        $u->forceFill(['name' => $id->name])->save();

        return $u;
    });

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

    $this->get('/martis/sso/azure/callback');

    $user = SsoTestUser::query()->where('email', 'existing@example.com')->first();
    expect($user->name)->toBe('Existing');
});

it('callback maps external roles to local roles via column strategy', function () {
    $identity = stubIdentity('a@example.com', ['PMI ADMIN', 'PMI EDITOR', 'UNKNOWN']);

    MartisSso::resolveUserUsing(fn (SsoIdentity $id) => SsoTestUser::query()->updateOrCreate(['email' => $id->email], ['name' => $id->name, 'password' => bcrypt('x')]));
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

    $this->get('/martis/sso/azure/callback');

    expect($this->capturedRoles->pluck('name')->sort()->values()->all())->toEqual(['admin', 'editor']);
});

it('callback denies login when user has no matching role and on_no_role_match is deny', function () {
    config()->set('martis.auth.sso.providers.azure.on_no_role_match', 'deny');

    $identity = stubIdentity('nomatch@example.com', ['UNKNOWN_ROLE']);

    MartisSso::resolveUserUsing(fn (SsoIdentity $id) => SsoTestUser::query()->updateOrCreate(['email' => $id->email], ['name' => $id->name, 'password' => bcrypt('x')]));
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

    $response = $this->get('/martis/sso/azure/callback');

    $response->assertRedirect('/martis/login');
    expect(auth()->check())->toBeFalse();
});

it('callback denies login when on_no_role_match is callable but no callback yields a response', function () {
    // Regression: the controller fired the no-role-match hook for EVERY
    // strategy and, when 'callable' + a null hook result, fell through to the
    // 'guest' path — logging the user in with no roles. 'callable' with no
    // registered callback (null return) must deny.
    config()->set('martis.auth.sso.providers.azure.on_no_role_match', 'callable');

    $identity = stubIdentity('callable-null@example.com', ['UNKNOWN_ROLE']);

    MartisSso::resolveUserUsing(fn (SsoIdentity $id) => SsoTestUser::query()->updateOrCreate(['email' => $id->email], ['name' => $id->name, 'password' => bcrypt('x')]));
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

    $response = $this->get('/martis/sso/azure/callback');

    $response->assertRedirect('/martis/login');
    expect(auth()->check())->toBeFalse();
});

it('callback allows guest login when on_no_role_match is guest', function () {
    config()->set('martis.auth.sso.providers.azure.on_no_role_match', 'guest');

    $identity = stubIdentity('guest@example.com', ['UNKNOWN']);

    MartisSso::resolveUserUsing(fn (SsoIdentity $id) => SsoTestUser::query()->updateOrCreate(['email' => $id->email], ['name' => $id->name, 'password' => bcrypt('x')]));
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

    $response = $this->get('/martis/sso/azure/callback');

    $response->assertStatus(302);
    expect(auth()->check())->toBeTrue();
});

it('redirect to a disabled provider bounces back to the login page', function () {
    config()->set('martis.auth.sso.providers.azure.enabled', false);

    $response = $this->get('/martis/sso/azure/redirect');
    $response->assertRedirect('/martis/login');
});

it('afterLogin hook fires once after a successful flow', function () {
    $fired = 0;
    MartisSso::afterLogin(function () use (&$fired) {
        $fired++;
    });

    $identity = stubIdentity('hook@example.com', ['PMI ADMIN']);
    MartisSso::resolveUserUsing(fn (SsoIdentity $id) => SsoTestUser::query()->updateOrCreate(['email' => $id->email], ['name' => $id->name, 'password' => bcrypt('x')]));
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

    $this->get('/martis/sso/azure/callback');
    expect($fired)->toBe(1);
});

it('callback stashes the provider name in the session for federated logout', function () {
    $identity = stubIdentity('flogout@example.com', ['PMI ADMIN']);

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

    $this->get('/martis/sso/azure/callback');

    expect(session('martis_sso_provider'))->toBe('azure');
});

it('logout redirects through the IdP logout URL when the provider declared one', function () {
    config()->set('martis.auth.sso.providers.azure.logout_url', 'https://login.microsoftonline.com/T/oauth2/v2.0/logout?post_logout_redirect_uri={post_logout_redirect_uri}');

    $user = SsoTestUser::query()->create(['name' => 'F', 'email' => 'f@example.com', 'password' => bcrypt('x')]);
    $this->actingAs($user)
        ->withSession(['martis_sso_provider' => 'azure', 'martis_two_factor_passed' => true]);

    $response = $this->post('/martis/api/auth/logout');

    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');
    expect($location)->toStartWith('https://login.microsoftonline.com/T/oauth2/v2.0/logout?post_logout_redirect_uri=');
    // post-logout target points back at the Martis login page.
    expect(rawurldecode($location))->toContain('/martis/login');
});

it('logout falls through to the local login page when no IdP logout_url is set', function () {
    config()->set('martis.auth.sso.providers.azure.logout_url', null);

    $user = SsoTestUser::query()->create(['name' => 'G', 'email' => 'g@example.com', 'password' => bcrypt('x')]);
    $this->actingAs($user)
        ->withSession(['martis_sso_provider' => 'azure', 'martis_two_factor_passed' => true]);

    $response = $this->post('/martis/api/auth/logout');

    $response->assertRedirect();
    expect((string) $response->headers->get('Location'))->toContain('/martis/login');
    expect((string) $response->headers->get('Location'))->not->toContain('login.microsoftonline.com');
});

it('logout JSON envelope includes the IdP logout_url when present', function () {
    config()->set('martis.auth.sso.providers.azure.logout_url', 'https://example.com/logout');

    $user = SsoTestUser::query()->create(['name' => 'H', 'email' => 'h@example.com', 'password' => bcrypt('x')]);
    $this->actingAs($user)
        ->withSession(['martis_sso_provider' => 'azure', 'martis_two_factor_passed' => true]);

    $response = $this->postJson('/martis/api/auth/logout');

    $response->assertOk()
        ->assertJsonFragment(['logout_url' => 'https://example.com/logout', 'message' => 'Logged out']);
});

it('password login wipes the stale martis_sso_provider marker', function () {
    SsoTestUser::query()->create([
        'name' => 'I',
        'email' => 'i@example.com',
        'password' => bcrypt('s3cret-password'),
    ]);

    $this->withSession(['martis_sso_provider' => 'azure'])
        ->postJson('/martis/api/auth/login', ['email' => 'i@example.com', 'password' => 's3cret-password'])
        ->assertOk();

    expect(session('martis_sso_provider'))->toBeNull();
});

it('routes are NOT registered when sso master switch is off', function () {
    /** @var Router $router */
    $router = $this->app['router'];
    config()->set('martis.auth.sso.enabled', false);

    $fresh = new RouteCollection;
    $router->setRoutes($fresh);
    require __DIR__.'/../../routes/martis.php';

    $names = collect($router->getRoutes()->getRoutesByName())->keys()
        ->filter(fn (string $n) => str_starts_with($n, 'martis.sso'));

    expect($names)->toBeEmpty();
});
