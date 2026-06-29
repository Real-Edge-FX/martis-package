<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
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

        $target = $user ?? $this->resolveAnonymousNotifiable($email);
        Notification::send($target, new MagicLinkNotification($url, $this->service->ttlMinutes()));

        return response()->json(['ok' => true]);
    }

    public function consume(Request $request): RedirectResponse
    {
        $loginPath = '/'.ltrim((string) config('martis.path', 'martis'), '/').'/login';

        if (! (bool) config('martis.auth.magic_link.enabled', false)) {
            return redirect($loginPath.'?magic_link=disabled');
        }

        $email = strtolower((string) $request->query('email', ''));
        $token = (string) $request->query('token', '');

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

        $guard = (string) config('martis.guard', 'web');
        Auth::guard($guard)->login($user);
        $request->session()->regenerate();

        $home = '/'.ltrim((string) config('martis.path', 'martis'), '/');

        return redirect($home);
    }

    protected function resolveUser(string $email): ?Authenticatable
    {
        $provider = Auth::createUserProvider((string) config('auth.defaults.provider', 'users'));
        if ($provider === null) {
            return null;
        }

        return $provider->retrieveByCredentials(['email' => $email]);
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

    protected function userClass(): ?string
    {
        $provider = (string) config('auth.defaults.provider', 'users');
        $driver = (string) config("auth.providers.{$provider}.driver", 'eloquent');
        $class = (string) config("auth.providers.{$provider}.model", '');

        return $driver === 'eloquent' && $class !== '' && class_exists($class) ? $class : null;
    }
}
