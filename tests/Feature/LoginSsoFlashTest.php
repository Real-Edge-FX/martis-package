<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Martis\Auth\PublishedFlash;
use Martis\Sso\Providers\AzureProvider;
use Martis\Sso\SsoException;

// ===========================================================================
// A refused SSO sign-in shows on the login page (v2.21.0).
//
// SsoController redirects a refusal to the login page with
// `withErrors(['sso' => text])`. The page is the SPA shell, which never read
// that flash, so a refusal was a silent bounce. The shell now publishes the
// flashed `sso` error of the current request, once, as `MartisConfig.flash.sso`
// and nothing else of the error bag.
// ===========================================================================

beforeEach(function () {
    $this->hotFile = public_path('vendor/martis/hot');
    File::ensureDirectoryExists(dirname($this->hotFile));
    File::put($this->hotFile, 'http://localhost:5173');

    config()->set('martis.auth.sso.enabled', true);
    config()->set('martis.auth.sso.providers.azure', ['enabled' => true, 'driver' => 'azure']);

    /** @var Router $router */
    $router = $this->app['router'];
    $router->setRoutes(new RouteCollection);
    require __DIR__.'/../../routes/martis.php';

    Log::shouldReceive('channel')->andReturn(Mockery::spy());
});

afterEach(function () {
    File::delete($this->hotFile);
});

/** The `flash` entry of `window.MartisConfig` in the HTML of a page. */
function shellFlash(string $html): ?array
{
    preg_match('/^\s*flash: (.*),$/m', $html, $match);

    return isset($match[1]) ? json_decode($match[1], true, flags: JSON_THROW_ON_ERROR) : null;
}

/** Make the Azure driver refuse the callback with $exception. */
function refuseCallbackWith(object $app, Throwable $exception): void
{
    $app->instance(AzureProvider::class, new class($exception) extends AzureProvider
    {
        public function __construct(private Throwable $exception) {}

        public function resolveIdentity(Request $request): never
        {
            throw $this->exception;
        }

        public function redirect(Request $request): RedirectResponse
        {
            return redirect('/');
        }
    });
}

it('publishes the flashed sso error once, and not on the next request', function () {
    refuseCallbackWith($this->app, new SsoException('x', 'no_role_match', 'martis::messages.sso_no_role_match'));

    $html = $this->followingRedirects()->get('/martis/sso/azure/callback')->assertOk()->getContent();

    expect(shellFlash($html))->toBe(['sso' => __('martis::messages.sso_no_role_match')]);

    $next = $this->get('/martis/login')->assertOk()->getContent();

    expect(shellFlash($next))->toBe(['sso' => null]);
});

it('publishes the generic text for a refusal that is not an SsoException', function () {
    refuseCallbackWith($this->app, new RuntimeException('token endpoint unreachable'));

    $html = $this->followingRedirects()->get('/martis/sso/azure/callback')->getContent();

    expect(shellFlash($html))->toBe(['sso' => __('martis::messages.sso_callback_failed')])
        ->and($html)->not->toContain('token endpoint unreachable');
});

it('publishes the text of a provider that is not enabled, on both SSO routes', function () {
    config()->set('martis.auth.sso.providers.azure.enabled', false);

    $callback = $this->followingRedirects()->get('/martis/sso/azure/callback')->getContent();
    expect(shellFlash($callback))->toBe(['sso' => __('martis::messages.sso_provider_disabled')]);

    $redirect = $this->followingRedirects()->get('/martis/sso/azure/redirect')->getContent();
    expect(shellFlash($redirect))->toBe(['sso' => __('martis::messages.sso_provider_disabled')]);
});

it('publishes nothing on a page that follows no refusal', function () {
    $html = $this->get('/martis/login')->assertOk()->getContent();

    expect(shellFlash($html))->toBe(['sso' => null]);
});

it('never publishes the rest of the error bag', function () {
    $this->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag([
        'sso' => 'Sign-in failed.',
        'email' => 'These credentials do not match our records.',
        'token' => 'SECRET-INVITATION-TOKEN',
    ]))]);

    $html = $this->get('/martis/login')->assertOk()->getContent();

    expect(shellFlash($html))->toBe(['sso' => 'Sign-in failed.'])
        ->and($html)->not->toContain('SECRET-INVITATION-TOKEN')
        ->and($html)->not->toContain('do not match our records');
});

it('encodes the text so that markup in it cannot close the inline script', function () {
    $this->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag([
        'sso' => '</script><script>alert(1)</script>',
    ]))]);

    $html = $this->get('/martis/login')->assertOk()->getContent();

    expect($html)->not->toContain('</script><script>alert(1)')
        ->and(shellFlash($html))->toBe(['sso' => '</script><script>alert(1)</script>']);
});

/** Make `request()` a request whose session holds $errors under `errors` (none when null). */
function requestWithErrors(mixed $errors, bool $session = true): void
{
    $request = Request::create('/martis/login');

    if ($session) {
        $store = new Store('flash-test', new ArraySessionHandler(10));
        $store->start();
        $errors === null ?: $store->put('errors', $errors);
        $request->setLaravelSession($store);
    }

    app()->instance('request', $request);
}

it('keeps only a non-empty string of the sso key', function () {
    $bag = static fn (mixed $sso): ViewErrorBag => (new ViewErrorBag)->put('default', new MessageBag(['sso' => $sso]));

    requestWithErrors($bag('Sign-in failed.'));
    expect(PublishedFlash::toArray())->toBe(['sso' => 'Sign-in failed.']);

    requestWithErrors($bag(['first', 'second']));
    expect(PublishedFlash::toArray())->toBe(['sso' => 'first']);

    foreach (['', 5, null] as $unusable) {
        requestWithErrors($bag($unusable));
        expect(PublishedFlash::toArray())->toBe(['sso' => null]);
    }
});

it('answers null without a session, without errors, or with errors that are not a bag', function () {
    requestWithErrors(null, session: false);
    expect(PublishedFlash::toArray())->toBe(['sso' => null]);

    requestWithErrors(null);
    expect(PublishedFlash::toArray())->toBe(['sso' => null]);

    requestWithErrors(['sso' => 'a plain array is not what withErrors() flashes']);
    expect(PublishedFlash::toArray())->toBe(['sso' => null]);
});
