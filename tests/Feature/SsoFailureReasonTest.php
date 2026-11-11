<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Martis\Events\SsoFailed;
use Martis\Sso\Facades\MartisSso;
use Martis\Sso\IdentityResolver;
use Martis\Sso\Providers\AzureProvider;
use Martis\Sso\SsoException;
use Martis\Sso\SsoIdentity;
use Martis\Sso\SsoManager;
use Psr\Log\LoggerInterface;

class SsoReasonUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class SsoReasonRole extends Model
{
    protected $table = 'sso_reason_roles';

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
        $table->rememberToken();
        $table->timestamps();
    });
    Schema::dropIfExists('sso_reason_roles');
    Schema::create('sso_reason_roles', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('azure_group_name')->nullable();
    });
    SsoReasonRole::create(['name' => 'admin', 'azure_group_name' => 'PMI ADMIN']);

    config()->set('auth.providers.users.model', SsoReasonUser::class);
    config()->set('martis.auth.sso.enabled', true);

    /** @var Router $router */
    $router = $this->app['router'];
    $router->setRoutes(new RouteCollection);
    require __DIR__.'/../../routes/martis.php';

    config()->set('martis.auth.sso.providers.azure', [
        'enabled' => true,
        'driver' => 'azure',
        'role_strategy' => 'column',
        'role_column' => 'azure_group_name',
        'role_model' => SsoReasonRole::class,
        'auto_create_user' => true,
        'identity_match_attribute' => 'email',
        'sync_user_attributes' => ['name', 'email'],
        'sync_roles' => true,
        'permission_adapter' => 'callable',
        'on_no_role_match' => 'deny',
    ]);

    $this->app->make(SsoManager::class)->flushHooksForTesting();
    MartisSso::syncRolesUsing(fn () => null);

    // Capture what the callback logs.
    $this->logged = new ArrayObject;
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []) {
        $this->logged[] = ['message' => $message, 'context' => $context];
    });
    Log::shouldReceive('channel')->andReturn($logger);
});

afterEach(function () {
    Schema::dropIfExists('sso_reason_roles');
    $this->app->make(SsoManager::class)->flushHooksForTesting();
});

/** Make the Azure driver hand the callback an identity, or throw. */
function ssoReasonProvider(object $app, SsoIdentity|Throwable $result): void
{
    $app->instance(AzureProvider::class, new class($result) extends AzureProvider
    {
        public function __construct(private SsoIdentity|Throwable $result) {}

        public function resolveIdentity(Request $request): SsoIdentity
        {
            if ($this->result instanceof Throwable) {
                throw $this->result;
            }

            return $this->result;
        }

        public function redirect(Request $request): RedirectResponse
        {
            return redirect('/');
        }
    });
}

function ssoReasonIdentity(string $email = 'someone@example.com', array $roles = ['PMI ADMIN']): SsoIdentity
{
    return new SsoIdentity('azure', 'azure-'.md5($email), $email, 'Someone', $roles);
}

it('turns a plain provider exception into reason "unknown" with the generic text', function () {
    Event::fake([SsoFailed::class]);
    $boom = new RuntimeException('token endpoint unreachable');
    ssoReasonProvider($this->app, $boom);

    $response = $this->get('/martis/sso/azure/callback');

    $response->assertRedirect(route('martis.login'));
    $response->assertSessionHasErrors(['sso' => __('martis::messages.sso_callback_failed')]);
    expect(auth()->check())->toBeFalse();

    Event::assertDispatchedTimes(SsoFailed::class, 1);
    Event::assertDispatched(SsoFailed::class, fn (SsoFailed $e) => $e->provider === 'azure'
        && $e->reason === SsoException::REASON_UNKNOWN
        && $e->exception === $boom);
});

it('logs provider, reason, request id, exception class and message with the same keys on every refusal', function () {
    ssoReasonProvider($this->app, new RuntimeException('token endpoint unreachable'));

    $this->withHeader('X-Request-Id', 'req-abc-123')->get('/martis/sso/azure/callback');

    expect($this->logged)->toHaveCount(1);
    $entry = $this->logged[0];
    expect($entry['message'])->toBe('SSO callback failed')
        ->and($entry['context'])->toBe([
            'provider' => 'azure',
            'reason' => 'unknown',
            'request_id' => 'req-abc-123',
            'exception' => RuntimeException::class,
            'error' => 'token endpoint unreachable',
        ]);
});

it('generates a request id for the log line when the request carries none', function () {
    ssoReasonProvider($this->app, new RuntimeException('x'));

    $this->get('/martis/sso/azure/callback');

    expect($this->logged[0]['context']['request_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

it('never lets a token inside an exception message reach the log context', function () {
    $message = 'Client error: `POST https://login.example/oauth2/token?code=AUTH-CODE-SECRET&state=STATE-SECRET&client_secret=CLIENT-SECRET` '
        .'resulted in a `400` response: {"error":"invalid_grant","id_token":"ID-TOKEN-SECRET","access_token":"ACCESS-SECRET","refresh_token":"REFRESH-SECRET","token":"BARE-TOKEN-SECRET"}';
    ssoReasonProvider($this->app, new RuntimeException($message));

    $this->get('/martis/sso/azure/callback');

    $context = json_encode($this->logged[0]['context'], JSON_THROW_ON_ERROR);
    foreach (['AUTH-CODE-SECRET', 'STATE-SECRET', 'CLIENT-SECRET', 'ID-TOKEN-SECRET', 'ACCESS-SECRET', 'REFRESH-SECRET', 'BARE-TOKEN-SECRET'] as $secret) {
        expect($context)->not->toContain($secret);
    }
    expect($context)->toContain('invalid_grant');
});

it('shows the message key of an SsoException when the translation exists and keeps its reason', function () {
    Event::fake([SsoFailed::class]);
    ssoReasonProvider($this->app, new SsoException('tenant mismatch', 'tenant_mismatch', 'martis::messages.sso_no_role_match'));

    $response = $this->get('/martis/sso/azure/callback');

    $response->assertSessionHasErrors(['sso' => __('martis::messages.sso_no_role_match')]);
    Event::assertDispatched(SsoFailed::class, fn (SsoFailed $e) => $e->reason === 'tenant_mismatch');
    expect($this->logged[0]['context']['reason'])->toBe('tenant_mismatch')
        ->and($this->logged[0]['context']['exception'])->toBe(SsoException::class);
});

it('falls back to the generic text when the message key has no translation', function () {
    ssoReasonProvider($this->app, new SsoException('x', 'custom', 'martis::messages.does_not_exist_anywhere'));

    $this->get('/martis/sso/azure/callback')
        ->assertSessionHasErrors(['sso' => __('martis::messages.sso_callback_failed')]);
});

it('falls back to the generic text for an SsoException without a message key', function () {
    ssoReasonProvider($this->app, new SsoException('x', 'custom'));

    $this->get('/martis/sso/azure/callback')
        ->assertSessionHasErrors(['sso' => __('martis::messages.sso_callback_failed')]);
});

it('reports an adoption refusal as reason "account_unverified" with its own text', function () {
    Event::fake([SsoFailed::class]);
    IdentityResolver::forgetResolver();
    config()->set('martis.auth.registration.enabled', true);
    SsoReasonUser::query()->create(['name' => 'Squatter', 'email' => 'victim@example.com', 'password' => bcrypt('x')]);
    ssoReasonProvider($this->app, ssoReasonIdentity('victim@example.com'));

    $response = $this->get('/martis/sso/azure/callback');

    $response->assertSessionHasErrors(['sso' => __('martis::messages.sso_account_unverified')]);
    Event::assertDispatched(SsoFailed::class, fn (SsoFailed $e) => $e->reason === 'account_unverified' && $e->exception !== null);
    expect($this->logged[0]['context']['reason'])->toBe('account_unverified');
});

it('reports a user that is not provisioned as reason "not_provisioned"', function () {
    Event::fake([SsoFailed::class]);
    config()->set('martis.auth.sso.providers.azure.auto_create_user', false);
    ssoReasonProvider($this->app, ssoReasonIdentity());

    $response = $this->get('/martis/sso/azure/callback');

    $response->assertSessionHasErrors(['sso' => __('martis::messages.sso_user_not_provisioned')]);
    Event::assertDispatched(SsoFailed::class, fn (SsoFailed $e) => $e->reason === 'not_provisioned' && $e->exception === null);
    expect($this->logged[0]['context']['reason'])->toBe('not_provisioned');
});

it('reports a denied role mapping as reason "no_role_match"', function () {
    Event::fake([SsoFailed::class]);
    ssoReasonProvider($this->app, ssoReasonIdentity(roles: ['NOT A ROLE']));

    $response = $this->get('/martis/sso/azure/callback');

    $response->assertSessionHasErrors(['sso' => __('martis::messages.sso_no_role_match')]);
    Event::assertDispatched(SsoFailed::class, fn (SsoFailed $e) => $e->reason === 'no_role_match');
    expect($this->logged[0]['context']['reason'])->toBe('no_role_match');
});

it('reports a no-role-match hook redirect as a refusal too and returns that redirect', function () {
    Event::fake([SsoFailed::class]);
    config()->set('martis.auth.sso.providers.azure.on_no_role_match', 'callable');
    MartisSso::onNoRoleMatchUsing(fn () => redirect('/somewhere-else'));
    ssoReasonProvider($this->app, ssoReasonIdentity(roles: ['NOT A ROLE']));

    $this->get('/martis/sso/azure/callback')->assertRedirect('/somewhere-else');

    Event::assertDispatched(SsoFailed::class, fn (SsoFailed $e) => $e->reason === 'no_role_match');
    expect(auth()->check())->toBeFalse();
});

it('does not dispatch SsoFailed on a successful sign-in', function () {
    Event::fake([SsoFailed::class]);
    ssoReasonProvider($this->app, ssoReasonIdentity());

    $this->get('/martis/sso/azure/callback')->assertRedirect();

    Event::assertNotDispatched(SsoFailed::class);
    expect(auth()->check())->toBeTrue()
        ->and($this->logged)->toHaveCount(0);
});
