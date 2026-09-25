<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Martis\Auth\GuardCatalog;
use Martis\Auth\MagicLinkNotification;
use Martis\Auth\MagicLinkService;

/**
 * Handles the magic-link (passwordless) sign-in surfaces. Off by
 * default; gated on `auth.magic_link.enabled`. The controller stays
 * lean — token issuance + consumption sit in `MagicLinkService` so
 * tests can drive them directly without hitting the HTTP layer.
 *
 * Public endpoints:
 *
 *   POST /martis/api/auth/magic-link/request  { email }
 *   GET  /martis/api/auth/magic-link/consume?email=...&token=...
 *
 * The consume endpoint redirects to the dashboard on success and to
 * `/login?magic_link=expired` on failure so a leaked link cannot
 * leak its content into a server log error.
 *
 * The email is looked up (and auto-registered) in the user provider of
 * the Martis guard, the guard consume() signs the user into: a user of
 * another provider (the site's `users`) would put their id in the Martis
 * guard's session and sign in the panel user with that id.
 */
class MagicLinkController
{
    public function __construct(
        protected MagicLinkService $service,
    ) {}

    public function request(Request $request): JsonResponse
    {
        if (! (bool) config('martis.auth.magic_link.enabled', false)) {
            return response()->json(['message' => __('martis::auth.magic_link_disabled')], 404);
        }

        $payload = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = strtolower((string) $payload['email']);
        $user = $this->resolveUser($email);

        if ($user === null && ! (bool) config('martis.auth.magic_link.auto_register', false)) {
            // Behave identically whether the email exists or not — no
            // account-enumeration leak. The frontend still toasts
            // "check your inbox" on this path.
            return response()->json(['ok' => true]);
        }

        $token = $this->service->issue($email);
        if ($token === null) {
            return response()->json(['message' => __('martis::auth.magic_link_unavailable')], 503);
        }

        $url = URL::route('martis.api.auth.magic-link.consume', [
            'email' => $email,
            'token' => $token,
        ], absolute: true);

        // A Martis user model without Notifiable has no mail route: mail the
        // address the link was asked for, the one the user was found by.
        $target = $user !== null && method_exists($user, 'routeNotificationFor')
            ? $user
            : $this->resolveAnonymousNotifiable($email);
        Notification::send($target, new MagicLinkNotification($url, $this->service->ttlMinutes()));

        return response()->json(['ok' => true]);
    }

    public function consume(Request $request): RedirectResponse
    {
        $loginPath = '/'.ltrim((string) config('martis.path', 'martis'), '/').'/login';

        if (! (bool) config('martis.auth.magic_link.enabled', false)) {
            return redirect($loginPath.'?magic_link=disabled');
        }

        // A parameter sent as an array (`email[]=`) reads as missing.
        $rawEmail = $request->query('email', '');
        $rawToken = $request->query('token', '');
        $email = strtolower(is_string($rawEmail) ? $rawEmail : '');
        $token = is_string($rawToken) ? $rawToken : '';

        if ($email === '' || $token === '') {
            return redirect($loginPath.'?magic_link=invalid');
        }

        $consumedEmail = $this->service->consume($email, $token);
        if ($consumedEmail === null) {
            return redirect($loginPath.'?magic_link=expired');
        }

        $user = $this->resolveUser($consumedEmail);
        if ($user === null && (bool) config('martis.auth.magic_link.auto_register', false)) {
            $user = $this->autoRegister($consumedEmail);
        }

        if ($user === null) {
            return redirect($loginPath.'?magic_link=expired');
        }

        Auth::guard(GuardCatalog::martis())->login($user);
        $request->session()->regenerate();

        $home = '/'.ltrim((string) config('martis.path', 'martis'), '/');

        return redirect($home);
    }

    protected function resolveUser(string $email): ?Authenticatable
    {
        return $this->userProvider()?->retrieveByCredentials(['email' => $email]);
    }

    /**
     * The user provider of the Martis guard: the guard's own when it
     * exposes one (a session guard does), else the provider its config
     * names.
     */
    protected function userProvider(): ?UserProvider
    {
        $guard = Auth::guard(GuardCatalog::martis());
        if (method_exists($guard, 'getProvider')) {
            $provider = $guard->getProvider();
            if ($provider instanceof UserProvider) {
                return $provider;
            }
        }

        $name = config('auth.guards.'.GuardCatalog::martis().'.provider');

        return is_string($name) && $name !== '' ? Auth::createUserProvider($name) : null;
    }

    protected function autoRegister(string $email): ?Authenticatable
    {
        $userClass = $this->userClass();
        if ($userClass === null) {
            return null;
        }

        // forceFill (not the mass-assignment constructor): a host User model
        // that doesn't list email/name/password in $fillable would otherwise
        // silently drop them, creating a broken account with a null email /
        // password. These are package-controlled values, not raw user input.
        /** @var Model $user */
        $user = new $userClass;
        $user->forceFill([
            'email' => $email,
            'name' => $email,
            'password' => bcrypt(Str::random(40)),
        ])->save();

        return $user instanceof Authenticatable ? $user : null;
    }

    protected function resolveAnonymousNotifiable(string $email): AnonymousNotifiable
    {
        return Notification::route('mail', $email);
    }

    /**
     * The model auto-registration creates: the Martis guard provider's,
     * when that provider is Eloquent.
     */
    protected function userClass(): ?string
    {
        $provider = $this->userProvider();
        $class = $provider instanceof EloquentUserProvider ? $provider->getModel() : '';

        return $class !== '' && class_exists($class) ? $class : null;
    }
}
