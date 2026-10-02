<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteServiceProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Martis\Sso\Facades\MartisSso;
use Martis\Sso\Providers\AzureProvider;
use Martis\Sso\SsoIdentity;
use Martis\Sso\SsoManager;

/*
 * The Azure provider checks the tenant of the identity against the tenant
 * the app registration is tied to (v2.4.0). A multi-tenant registration lets
 * any Entra tenant's user sign in with whatever email their tenant says, and
 * the identity is matched to a local account by email.
 */

const AZURE_TENANT = '11111111-2222-3333-4444-555555555555';
const AZURE_OTHER_TENANT = '99999999-8888-7777-6666-555555555555';

class AzureTenantUser extends AuthUser
{
    protected $table = 'users';

    protected $guarded = [];
}

/** A compact JWT whose payload holds $claims (the signature is never read). */
function azureAccessToken(array $claims): string
{
    $encode = static fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

    return $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($claims).'.signature';
}

/** Make Socialite's `azure-tenant-test` driver hand back this user. */
function azureSocialiteReturns(string $token, array $raw = []): void
{
    $user = (new SocialiteUser)->map(['id' => 'oid-1', 'name' => 'Ada', 'email' => 'ada@example.com'])->setRaw($raw);
    $user->setToken($token);

    Socialite::extend('azure-tenant-test', fn () => new class($user) implements SocialiteProvider
    {
        public function __construct(private SocialiteUser $user) {}

        public function redirect()
        {
            return redirect('/');
        }

        public function user()
        {
            return $this->user;
        }
    });
    // The manager keeps the driver it built the first time.
    Socialite::forgetDrivers();
}

function azureIdentity(): SsoIdentity
{
    return (new AzureProvider)->resolveIdentity(Request::create('/martis/sso/azure/callback'));
}

beforeEach(function () {
    $this->app->register(SocialiteServiceProvider::class);
    config()->set('martis.auth.sso.providers.azure', [
        'enabled' => true,
        'driver' => 'azure-tenant-test',
        'role_source' => 'callable',
    ]);
    config()->set('services.azure-tenant-test.tenant', null);
});

it('lets the identity of the configured tenant in', function () {
    config()->set('martis.auth.sso.providers.azure.tenant', AZURE_TENANT);
    azureSocialiteReturns(azureAccessToken(['tid' => AZURE_TENANT]));

    expect(azureIdentity()->email)->toBe('ada@example.com');
});

it('rejects an identity of another tenant', function () {
    config()->set('martis.auth.sso.providers.azure.tenant', AZURE_TENANT);
    azureSocialiteReturns(azureAccessToken(['tid' => AZURE_OTHER_TENANT]));

    expect(fn () => azureIdentity())->toThrow(RuntimeException::class, 'not the configured tenant');
});

it('compares the tenant ids without regard to case', function () {
    config()->set('martis.auth.sso.providers.azure.tenant', strtoupper(AZURE_TENANT));
    azureSocialiteReturns(azureAccessToken(['tid' => AZURE_TENANT]));

    expect(azureIdentity()->email)->toBe('ada@example.com');
});

it('rejects an identity whose tenant cannot be read while a tenant is configured', function (string $token) {
    config()->set('martis.auth.sso.providers.azure.tenant', AZURE_TENANT);
    azureSocialiteReturns($token);

    expect(fn () => azureIdentity())->toThrow(RuntimeException::class, 'could not be read');
})->with([
    'an opaque token' => ['EwB4A8l6BAAUbDba3x2OMJElkF7gJ4z/VbCPEz0AAA'],
    'a token without tid' => [fn () => azureAccessToken(['oid' => 'x'])],
    'a tid that is no GUID' => [fn () => azureAccessToken(['tid' => 'contoso'])],
    'no token' => [''],
]);

it('reads the tid among the raw attributes of the driver first', function () {
    config()->set('martis.auth.sso.providers.azure.tenant', AZURE_TENANT);
    azureSocialiteReturns('opaque-token', ['tid' => AZURE_TENANT]);

    expect(azureIdentity()->email)->toBe('ada@example.com');

    azureSocialiteReturns(azureAccessToken(['tid' => AZURE_TENANT]), ['tid' => AZURE_OTHER_TENANT]);

    expect(fn () => azureIdentity())->toThrow(RuntimeException::class, 'not the configured tenant');
});

it('takes the tenant of the Socialite driver config when the provider sets none', function () {
    config()->set('services.azure-tenant-test.tenant', AZURE_TENANT);
    azureSocialiteReturns(azureAccessToken(['tid' => AZURE_OTHER_TENANT]));

    expect(fn () => azureIdentity())->toThrow(RuntimeException::class, 'not the configured tenant');
});

it('checks nothing for a multi-tenant registration or an unset tenant', function (mixed $tenant) {
    config()->set('martis.auth.sso.providers.azure.tenant', $tenant);
    azureSocialiteReturns(azureAccessToken(['tid' => AZURE_OTHER_TENANT]));

    expect(azureIdentity()->email)->toBe('ada@example.com');
})->with(['common' => ['common'], 'organizations' => ['Organizations'], 'consumers' => ['consumers'], 'empty' => [''], 'null' => [null]]);

it('resolves a tenant configured by domain through the OpenID metadata, once a day', function () {
    Cache::flush();
    Http::fake(['login.microsoftonline.com/*' => Http::response(['issuer' => 'https://login.microsoftonline.com/'.AZURE_TENANT.'/v2.0'])]);
    config()->set('martis.auth.sso.providers.azure.tenant', 'contoso.onmicrosoft.com');
    azureSocialiteReturns(azureAccessToken(['tid' => AZURE_TENANT]));

    expect(azureIdentity()->email)->toBe('ada@example.com');
    azureIdentity();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://login.microsoftonline.com/contoso.onmicrosoft.com/v2.0/.well-known/openid-configuration');

    azureSocialiteReturns(azureAccessToken(['tid' => AZURE_OTHER_TENANT]));
    expect(fn () => azureIdentity())->toThrow(RuntimeException::class, 'not the configured tenant');
});

it('rejects the identity, without caching the failure, while the domain tenant cannot be resolved', function () {
    Cache::flush();
    Http::fake(['login.microsoftonline.com/*' => Http::sequence()->push('down', 503)->push(['issuer' => 'https://login.microsoftonline.com/'.AZURE_TENANT.'/v2.0'])]);
    config()->set('martis.auth.sso.providers.azure.tenant', 'contoso.onmicrosoft.com');
    azureSocialiteReturns(azureAccessToken(['tid' => AZURE_TENANT]));

    expect(fn () => azureIdentity())->toThrow(RuntimeException::class, 'could not be resolved to a tenant id');
    expect(azureIdentity()->email)->toBe('ada@example.com');
});

// -----------------------------------------------------------------------------
// Through the callback: a refused tenant signs nobody in and creates nobody.
// -----------------------------------------------------------------------------

it('refuses the callback of another tenant: nobody is signed in or created', function () {
    Schema::dropIfExists('users');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });
    config()->set('auth.providers.users.model', AzureTenantUser::class);
    config()->set('martis.auth.sso.enabled', true);
    config()->set('martis.auth.sso.providers.azure', [
        'enabled' => true,
        'driver' => 'azure-tenant-test',
        'role_source' => 'callable',
        'tenant' => AZURE_TENANT,
        'role_strategy' => 'config',
        'role_map' => ['ADMINS' => 'admin'],
        'auto_create_user' => true,
        'identity_match_attribute' => 'email',
        'permission_adapter' => 'callable',
        'on_no_role_match' => 'guest',
    ]);
    $this->app['router']->setRoutes(new RouteCollection);
    require __DIR__.'/../../routes/martis.php';
    $this->app->make(SsoManager::class)->flushHooksForTesting();
    MartisSso::syncRolesUsing(function (AuthUser $user, Collection $roles) {});
    azureSocialiteReturns(azureAccessToken(['tid' => AZURE_OTHER_TENANT]));

    $this->get('/martis/sso/azure/callback')
        ->assertRedirect('/martis/login')
        ->assertSessionHasErrors(['sso' => __('martis::messages.sso_callback_failed')]);

    $this->assertGuest();
    expect(AzureTenantUser::query()->count())->toBe(0);

    // The same callback from the configured tenant signs in.
    azureSocialiteReturns(azureAccessToken(['tid' => AZURE_TENANT]));
    $this->get('/martis/sso/azure/callback')->assertRedirect();
    $this->assertAuthenticated();
    expect(AzureTenantUser::query()->where('email', 'ada@example.com')->count())->toBe(1);

    $this->app->make(SsoManager::class)->flushHooksForTesting();
});
