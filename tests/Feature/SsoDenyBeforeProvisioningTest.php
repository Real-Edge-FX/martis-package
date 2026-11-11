<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Martis\Events\SsoFailed;
use Martis\Sso\Facades\MartisSso;
use Martis\Sso\IdentityResolver;
use Martis\Sso\Providers\AzureProvider;
use Martis\Sso\SsoException;
use Martis\Sso\SsoIdentity;
use Martis\Sso\SsoManager;

class SsoDenyUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class SsoDenyRole extends Model
{
    protected $table = 'sso_deny_roles';

    protected $guarded = [];

    public $timestamps = false;
}

/** `role_callable` target: maps the external names to the roles table, reading the user it is given. */
final class SsoDenyRoleCallable
{
    /** @var list<bool> whether the user it saw already existed in the database */
    public static array $sawPersistedUser = [];

    /**
     * @param  array<int, string>  $externalRoles
     * @return Collection<int, SsoDenyRole>
     */
    public static function map(array $externalRoles, ?User $user, string $provider): Collection
    {
        self::$sawPersistedUser[] = $user !== null && $user->exists;

        return SsoDenyRole::query()->whereIn('azure_group_name', $externalRoles)->get();
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
        $table->rememberToken();
        $table->timestamps();
    });
    Schema::dropIfExists('sso_deny_roles');
    Schema::create('sso_deny_roles', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('azure_group_name')->nullable();
    });
    SsoDenyRole::create(['name' => 'admin', 'azure_group_name' => 'PMI ADMIN']);
    SsoDenyRoleCallable::$sawPersistedUser = [];

    config()->set('auth.providers.users.model', SsoDenyUser::class);
    config()->set('martis.auth.registration.enabled', false);
    config()->set('martis.auth.sso.enabled', true);
    $this->app['router']->setRoutes(new RouteCollection);
    require __DIR__.'/../../routes/martis.php';

    config()->set('martis.auth.sso.providers.azure', [
        'enabled' => true,
        'driver' => 'azure',
        'role_strategy' => 'column',
        'role_column' => 'azure_group_name',
        'role_model' => SsoDenyRole::class,
        'auto_create_user' => true,
        'identity_match_attribute' => 'email',
        'sync_user_attributes' => ['name', 'email'],
        'sync_roles' => true,
        'permission_adapter' => 'callable',
        'on_no_role_match' => 'deny',
    ]);

    $this->app->make(SsoManager::class)->flushHooksForTesting();

    $this->synced = new ArrayObject;
    MartisSso::syncRolesUsing(function (User $user, Collection $roles) {
        $this->synced[] = ['user' => $user->getAttribute('email'), 'roles' => $roles->pluck('name')->all()];
    });
});

afterEach(function () {
    Schema::dropIfExists('sso_deny_roles');
    $this->app->make(SsoManager::class)->flushHooksForTesting();
});

function ssoDenyIdentity(array $roles, string $email = 'person@example.com', string $name = 'Idp Name'): SsoIdentity
{
    return new SsoIdentity('azure', 'azure-'.md5($email), $email, $name, $roles);
}

function ssoDenyServe(object $app, SsoIdentity $identity): void
{
    $app->instance(AzureProvider::class, new class($identity) extends AzureProvider
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
}

function ssoDenyExisting(string $email = 'person@example.com'): SsoDenyUser
{
    return SsoDenyUser::query()->create(['name' => 'Local Name', 'email' => $email, 'password' => bcrypt('x')]);
}

/** Switch the provider to a role mapping that needs the user: the transaction path. */
function ssoDenyNeedsUser(string $how): void
{
    if ($how === 'callable strategy') {
        config()->set('martis.auth.sso.providers.azure.role_strategy', 'callable');
        config()->set('martis.auth.sso.providers.azure.role_callable', [SsoDenyRoleCallable::class, 'map']);

        return;
    }

    MartisSso::resolveRolesUsing(fn (array $external, ?User $user) => SsoDenyRoleCallable::map($external, $user, 'azure'));
}

// -----------------------------------------------------------------------------
// Pre-check path: the role mapping does not need the user.
// -----------------------------------------------------------------------------

it('pre-check: a denied identity creates no row', function () {
    ssoDenyServe($this->app, ssoDenyIdentity(['NOT A ROLE']));

    $this->get('/martis/sso/azure/callback')
        ->assertRedirect(route('martis.login'))
        ->assertSessionHasErrors(['sso' => __('martis::messages.sso_no_role_match')]);

    expect(SsoDenyUser::query()->count())->toBe(0)
        ->and(auth()->check())->toBeFalse()
        ->and($this->synced)->toHaveCount(0);
});

it('pre-check: a denied identity leaves the name and email of an existing row untouched', function () {
    $existing = ssoDenyExisting();
    ssoDenyServe($this->app, ssoDenyIdentity(['NOT A ROLE'], 'person@example.com', 'Rewritten By Idp'));

    $this->get('/martis/sso/azure/callback')->assertRedirect(route('martis.login'));

    expect($existing->refresh()->name)->toBe('Local Name');
});

it('pre-check: the identity resolver is not called at all on a deny', function () {
    $called = false;
    MartisSso::resolveUserUsing(function () use (&$called) {
        $called = true;

        return null;
    });
    ssoDenyServe($this->app, ssoDenyIdentity(['NOT A ROLE']));

    $this->get('/martis/sso/azure/callback')->assertRedirect(route('martis.login'));

    expect($called)->toBeFalse();
});

it('pre-check: the deny takes precedence over a missing user (no_role_match, not not_provisioned)', function () {
    Event::fake([SsoFailed::class]);
    config()->set('martis.auth.sso.providers.azure.auto_create_user', false);
    ssoDenyServe($this->app, ssoDenyIdentity(['NOT A ROLE']));

    $this->get('/martis/sso/azure/callback')
        ->assertSessionHasErrors(['sso' => __('martis::messages.sso_no_role_match')]);

    Event::assertDispatched(SsoFailed::class, fn (SsoFailed $e) => $e->reason === 'no_role_match');
});

it('pre-check: an allowed identity gets its user written and its roles synced', function () {
    ssoDenyServe($this->app, ssoDenyIdentity(['PMI ADMIN']));

    $this->get('/martis/sso/azure/callback')->assertRedirect();

    expect(SsoDenyUser::query()->where('email', 'person@example.com')->exists())->toBeTrue()
        ->and($this->synced->getArrayCopy())->toBe([['user' => 'person@example.com', 'roles' => ['admin']]])
        ->and(auth()->check())->toBeTrue();
});

it('pre-check: an allowed identity updates the name of an existing row', function () {
    $existing = ssoDenyExisting();
    ssoDenyServe($this->app, ssoDenyIdentity(['PMI ADMIN'], 'person@example.com', 'Fresh From Idp'));

    $this->get('/martis/sso/azure/callback')->assertRedirect();

    expect($existing->refresh()->name)->toBe('Fresh From Idp');
});

it('pre-check: the config strategy denies before provisioning too', function () {
    config()->set('martis.auth.sso.providers.azure.role_strategy', 'config');
    config()->set('martis.auth.sso.providers.azure.role_map', ['admin' => 'PMI ADMIN']);
    config()->set('martis.auth.sso.providers.azure.role_lookup_column', 'name');
    ssoDenyServe($this->app, ssoDenyIdentity(['NOT A ROLE']));

    $this->get('/martis/sso/azure/callback')->assertRedirect(route('martis.login'));

    expect(SsoDenyUser::query()->count())->toBe(0);
});

it('pre-check: on_no_role_match = guest is not a deny: the user is written and signed in', function () {
    config()->set('martis.auth.sso.providers.azure.on_no_role_match', 'guest');
    ssoDenyServe($this->app, ssoDenyIdentity(['NOT A ROLE']));

    $this->get('/martis/sso/azure/callback')->assertRedirect();

    expect(SsoDenyUser::query()->where('email', 'person@example.com')->exists())->toBeTrue()
        ->and(auth()->check())->toBeTrue();
});

it('pre-check: a no-role-match hook redirect is a deny: no row is written', function () {
    config()->set('martis.auth.sso.providers.azure.on_no_role_match', 'callable');
    MartisSso::onNoRoleMatchUsing(fn () => redirect('/somewhere-else'));
    ssoDenyServe($this->app, ssoDenyIdentity(['NOT A ROLE']));

    $this->get('/martis/sso/azure/callback')->assertRedirect('/somewhere-else');

    expect(SsoDenyUser::query()->count())->toBe(0)
        ->and(auth()->check())->toBeFalse();
});

it('pre-check: provision_on_deny = true restores write-then-deny', function () {
    config()->set('martis.auth.sso.providers.azure.provision_on_deny', true);
    $existing = ssoDenyExisting();
    ssoDenyServe($this->app, ssoDenyIdentity(['NOT A ROLE'], 'person@example.com', 'Rewritten By Idp'));
    $other = ssoDenyIdentity(['NOT A ROLE'], 'fresh@example.com');

    $this->get('/martis/sso/azure/callback')->assertRedirect(route('martis.login'));
    expect($existing->refresh()->name)->toBe('Rewritten By Idp')
        ->and(auth()->check())->toBeFalse();

    ssoDenyServe($this->app, $other);
    $this->get('/martis/sso/azure/callback')->assertRedirect(route('martis.login'));
    expect(SsoDenyUser::query()->where('email', 'fresh@example.com')->exists())->toBeTrue()
        ->and(auth()->check())->toBeFalse();
});

// -----------------------------------------------------------------------------
// Transaction path: the role mapping needs the user.
// -----------------------------------------------------------------------------

dataset('role mappings that need the user', ['callable strategy', 'resolveRolesUsing closure']);

it('transaction: a denied identity creates no row', function (string $how) {
    ssoDenyNeedsUser($how);
    ssoDenyServe($this->app, ssoDenyIdentity(['NOT A ROLE']));

    $this->get('/martis/sso/azure/callback')
        ->assertRedirect(route('martis.login'))
        ->assertSessionHasErrors(['sso' => __('martis::messages.sso_no_role_match')]);

    expect(SsoDenyUser::query()->count())->toBe(0)
        ->and(auth()->check())->toBeFalse()
        ->and($this->synced)->toHaveCount(0)
        // The mapper did see the freshly written, persisted user.
        ->and(SsoDenyRoleCallable::$sawPersistedUser)->toBe([true]);
})->with('role mappings that need the user');

it('transaction: a denied identity leaves the name of an existing row untouched', function (string $how) {
    ssoDenyNeedsUser($how);
    $existing = ssoDenyExisting();
    ssoDenyServe($this->app, ssoDenyIdentity(['NOT A ROLE'], 'person@example.com', 'Rewritten By Idp'));

    $this->get('/martis/sso/azure/callback')->assertRedirect(route('martis.login'));

    expect($existing->refresh()->name)->toBe('Local Name');
})->with('role mappings that need the user');

it('transaction: an allowed identity commits its row and gets its roles synced', function (string $how) {
    ssoDenyNeedsUser($how);
    ssoDenyServe($this->app, ssoDenyIdentity(['PMI ADMIN']));

    $this->get('/martis/sso/azure/callback')->assertRedirect();

    expect(SsoDenyUser::query()->where('email', 'person@example.com')->exists())->toBeTrue()
        ->and($this->synced->getArrayCopy())->toBe([['user' => 'person@example.com', 'roles' => ['admin']]])
        ->and(auth()->check())->toBeTrue();
})->with('role mappings that need the user');

it('transaction: on_no_role_match = guest commits the row', function (string $how) {
    ssoDenyNeedsUser($how);
    config()->set('martis.auth.sso.providers.azure.on_no_role_match', 'guest');
    ssoDenyServe($this->app, ssoDenyIdentity(['NOT A ROLE']));

    $this->get('/martis/sso/azure/callback')->assertRedirect();

    expect(SsoDenyUser::query()->where('email', 'person@example.com')->exists())->toBeTrue()
        ->and(auth()->check())->toBeTrue();
})->with('role mappings that need the user');

it('transaction: a no-role-match hook redirect rolls the row back and is returned', function (string $how) {
    ssoDenyNeedsUser($how);
    config()->set('martis.auth.sso.providers.azure.on_no_role_match', 'callable');
    MartisSso::onNoRoleMatchUsing(fn () => redirect('/somewhere-else'));
    ssoDenyServe($this->app, ssoDenyIdentity(['NOT A ROLE']));

    $this->get('/martis/sso/azure/callback')->assertRedirect('/somewhere-else');

    expect(SsoDenyUser::query()->count())->toBe(0)
        ->and(auth()->check())->toBeFalse();
})->with('role mappings that need the user');

it('transaction: a callable no-role-match hook that yields nothing denies and rolls back', function (string $how) {
    ssoDenyNeedsUser($how);
    config()->set('martis.auth.sso.providers.azure.on_no_role_match', 'callable');
    ssoDenyServe($this->app, ssoDenyIdentity(['NOT A ROLE']));

    $this->get('/martis/sso/azure/callback')
        ->assertRedirect(route('martis.login'))
        ->assertSessionHasErrors(['sso' => __('martis::messages.sso_no_role_match')]);

    expect(SsoDenyUser::query()->count())->toBe(0);
})->with('role mappings that need the user');

it('transaction: a null result from the user resolver denies and rolls back what the resolver wrote', function () {
    Event::fake([SsoFailed::class]);
    ssoDenyNeedsUser('resolveRolesUsing closure');
    MartisSso::resolveUserUsing(function (SsoIdentity $identity) {
        SsoDenyUser::query()->create(['name' => 'Half Done', 'email' => 'halfdone@example.com', 'password' => bcrypt('x')]);

        return null;
    });
    ssoDenyServe($this->app, ssoDenyIdentity(['PMI ADMIN']));

    $this->get('/martis/sso/azure/callback')
        ->assertSessionHasErrors(['sso' => __('martis::messages.sso_user_not_provisioned')]);

    expect(SsoDenyUser::query()->count())->toBe(0);
    Event::assertDispatched(SsoFailed::class, fn (SsoFailed $e) => $e->reason === 'not_provisioned');
});

it('transaction: an SsoException from the resolver rolls back and is reported with its reason', function () {
    Event::fake([SsoFailed::class]);
    ssoDenyNeedsUser('resolveRolesUsing closure');
    MartisSso::resolveUserUsing(function (SsoIdentity $identity) {
        SsoDenyUser::query()->create(['name' => 'Half Done', 'email' => 'halfdone@example.com', 'password' => bcrypt('x')]);

        throw new SsoException('nope', 'custom_refusal');
    });
    ssoDenyServe($this->app, ssoDenyIdentity(['PMI ADMIN']));

    $this->get('/martis/sso/azure/callback')->assertRedirect(route('martis.login'));

    expect(SsoDenyUser::query()->count())->toBe(0);
    Event::assertDispatched(SsoFailed::class, fn (SsoFailed $e) => $e->reason === 'custom_refusal');
});

it('transaction: an unexpected exception rolls back and still propagates', function () {
    ssoDenyNeedsUser('resolveRolesUsing closure');
    MartisSso::resolveUserUsing(function (SsoIdentity $identity) {
        SsoDenyUser::query()->create(['name' => 'Half Done', 'email' => 'halfdone@example.com', 'password' => bcrypt('x')]);

        throw new LogicException('a bug in the host closure');
    });
    ssoDenyServe($this->app, ssoDenyIdentity(['PMI ADMIN']));
    $this->withoutExceptionHandling();
    // The test case may hold a transaction of its own: the sign-in must hand back the level it found.
    $baseLevel = DB::connection()->transactionLevel();

    expect(fn () => $this->get('/martis/sso/azure/callback'))->toThrow(LogicException::class);
    expect(SsoDenyUser::query()->count())->toBe(0)
        ->and(DB::connection()->transactionLevel())->toBe($baseLevel);
});

it('transaction: SsoFailed is dispatched after the rollback, so a listener sees no row', function () {
    ssoDenyNeedsUser('resolveRolesUsing closure');
    $seen = null;
    $baseLevel = DB::connection()->transactionLevel();
    Event::listen(SsoFailed::class, function () use (&$seen) {
        $seen = ['rows' => SsoDenyUser::query()->count(), 'level' => DB::connection()->transactionLevel()];
    });
    ssoDenyServe($this->app, ssoDenyIdentity(['NOT A ROLE']));

    $this->get('/martis/sso/azure/callback')->assertRedirect(route('martis.login'));

    expect($seen)->toBe(['rows' => 0, 'level' => $baseLevel]);
});

it('transaction: provision_on_deny = true runs without a transaction and keeps the write', function () {
    config()->set('martis.auth.sso.providers.azure.provision_on_deny', true);
    ssoDenyNeedsUser('resolveRolesUsing closure');
    ssoDenyServe($this->app, ssoDenyIdentity(['NOT A ROLE']));

    $this->get('/martis/sso/azure/callback')->assertRedirect(route('martis.login'));

    expect(SsoDenyUser::query()->where('email', 'person@example.com')->exists())->toBeTrue()
        ->and(auth()->check())->toBeFalse();
});

it('runs the resolver inside a transaction on the transaction path and outside one on the pre-check path', function () {
    $levels = [];
    MartisSso::resolveUserUsing(function (SsoIdentity $identity) use (&$levels) {
        $levels[] = DB::connection()->transactionLevel();

        return SsoDenyUser::query()->updateOrCreate(['email' => $identity->email], ['name' => 'N', 'password' => bcrypt('x')]);
    });
    ssoDenyServe($this->app, ssoDenyIdentity(['PMI ADMIN']));

    $this->get('/martis/sso/azure/callback')->assertRedirect();
    auth()->logout();

    ssoDenyNeedsUser('resolveRolesUsing closure');
    $this->get('/martis/sso/azure/callback')->assertRedirect();

    // The test case may wrap each test in a transaction itself: compare, do not pin.
    expect($levels[1])->toBe($levels[0] + 1);
});

it('IdentityResolver exposes the user model connection for the transaction', function () {
    expect((new IdentityResolver)->connection()->getName())->toBe(DB::connection()->getName());
});

it('throws naming the key for an on_no_role_match value it does not know, and writes nothing', function () {
    config()->set('martis.auth.sso.providers.azure.on_no_role_match', 'allow');
    ssoDenyServe($this->app, ssoDenyIdentity(['NOT A ROLE']));
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/martis/sso/azure/callback'))
        ->toThrow(InvalidArgumentException::class, 'martis.auth.sso.providers.azure.on_no_role_match');
    expect(SsoDenyUser::query()->count())->toBe(0)
        ->and(auth()->check())->toBeFalse();
});
