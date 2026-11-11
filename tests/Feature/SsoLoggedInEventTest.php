<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Martis\Events\SsoLoggedIn;
use Martis\Sso\Facades\MartisSso;
use Martis\Sso\Providers\AzureProvider;
use Martis\Sso\SsoIdentity;
use Martis\Sso\SsoManager;

class SsoLoggedInUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

beforeEach(function () {
    Schema::dropIfExists('users');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });

    config()->set('auth.providers.users.model', SsoLoggedInUser::class);
    config()->set('martis.auth.sso.enabled', true);
    $this->app['router']->setRoutes(new RouteCollection);
    require __DIR__.'/../../routes/martis.php';

    config()->set('martis.auth.sso.providers.azure', [
        'enabled' => true,
        'driver' => 'azure',
        'role_strategy' => 'column',
        'role_column' => 'azure_group_name',
        'auto_create_user' => true,
        'identity_match_attribute' => 'email',
        'sync_user_attributes' => ['name', 'email'],
        'sync_roles' => false,
        'on_no_role_match' => 'guest',
    ]);

    $this->app->make(SsoManager::class)->flushHooksForTesting();

    $identity = new SsoIdentity('azure', 'azure-1', 'logged@example.com', 'Logged In');
    $this->identity = $identity;
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

afterEach(fn () => $this->app->make(SsoManager::class)->flushHooksForTesting());

it('dispatches SsoLoggedIn exactly once per successful sign-in, with user, identity and provider', function () {
    Event::fake([SsoLoggedIn::class]);

    $this->get('/martis/sso/azure/callback')->assertRedirect();

    Event::assertDispatchedTimes(SsoLoggedIn::class, 1);
    Event::assertDispatched(SsoLoggedIn::class, fn (SsoLoggedIn $e) => $e->user->getAuthIdentifier() === auth()->id()
        && $e->identity === $this->identity
        && $e->provider === 'azure');
});

it('dispatches SsoLoggedIn after the user is logged in and the SSO session marker is set', function () {
    $observed = [];
    Event::listen(SsoLoggedIn::class, function () use (&$observed) {
        $observed = ['authenticated' => auth()->check(), 'provider' => session('martis_sso_provider')];
    });

    $this->get('/martis/sso/azure/callback');

    expect($observed)->toBe(['authenticated' => true, 'provider' => 'azure']);
});

it('runs the afterLogin hooks before the sign-in and SsoLoggedIn after it', function () {
    $trail = [];
    MartisSso::afterLogin(function () use (&$trail) {
        $trail[] = 'afterLogin:'.(auth()->check() ? 'in' : 'out');
    });
    Event::listen(SsoLoggedIn::class, function () use (&$trail) {
        $trail[] = 'event:'.(auth()->check() ? 'in' : 'out');
    });

    $this->get('/martis/sso/azure/callback');

    expect($trail)->toBe(['afterLogin:out', 'event:in']);
});

it('does not dispatch SsoLoggedIn when the sign-in is refused', function () {
    Event::fake([SsoLoggedIn::class]);
    config()->set('martis.auth.sso.providers.azure.on_no_role_match', 'deny');

    $this->get('/martis/sso/azure/callback')->assertRedirect(route('martis.login'));

    Event::assertNotDispatched(SsoLoggedIn::class);
});
