<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Martis\Auth\GuardCatalog;
use Martis\Auth\MagicLinkNotification;
use Martis\Auth\MagicLinkService;
use Martis\Auth\TwoFactorPass;
use Martis\Sso\SsoSession;
use Martis\Support\CanonicalUrl;

/**
 * Handles the magic-link (passwordless) sign-in surfaces. Off by
 * default; gated on `auth.magic_link.enabled`. The controller stays
 * lean — token issuance + consumption sit in `MagicLinkService` so
 * tests can drive them directly without hitting the HTTP layer.
 *
 * Public endpoints:
 *
 *   POST /martis/api/auth/magic-link/request  { email }
 *   GET  /martis/magic-link/confirm?email=...&token=...
 *   POST /martis/api/auth/magic-link/consume  { email, token, replace_session? }
 *
 * The emailed link is the GET: it opens a confirmation page ("Sign in as
 * x@y?") and never signs anyone in or burns the token, so a mail scanner,
 * a link preview, a prefetch or an `<img>` that loads it does nothing. The
 * page POSTs the sign-in (CSRF-protected), which consumes the token. A
 * browser already signed in as another user is asked to confirm the swap
 * first (`replace_session`). An expired or invalid token redirects to
 * `/login?magic_link=expired` (or `invalid`) so a leaked link cannot leak
 * its content into a server log error.
 *
 * The emailed URL is built on `APP_URL`, never on the request's host
 * (CanonicalUrl).
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

        $url = CanonicalUrl::route('martis.magic-link.confirm', [
            'email' => $email,
            'token' => $token,
        ]);

        // A Martis user model without Notifiable has no mail route: mail the
        // address the link was asked for, the one the user was found by.
        $target = $user !== null && method_exists($user, 'routeNotificationFor')
            ? $user
            : $this->resolveAnonymousNotifiable($email);
        Notification::send($target, new MagicLinkNotification($url, $this->service->ttlMinutes()));

        return response()->json(['ok' => true]);
    }

    /**
     * The page the emailed link opens: the SPA shell, which asks to
     * confirm the sign-in. Reads the token without consuming it.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $loginPath = $this->loginPath();

        if (! (bool) config('martis.auth.magic_link.enabled', false)) {
            return redirect($loginPath.'?magic_link=disabled');
        }

        [$email, $token] = $this->linkParameters($request->query('email', ''), $request->query('token', ''));

        if ($email === '' || $token === '') {
            return redirect($loginPath.'?magic_link=invalid');
        }

        if (! $this->service->check($email, $token)) {
            return redirect($loginPath.'?magic_link=expired');
        }

        // The URL carries the token: keep it out of caches and out of the
        // Referer of any request the page makes.
        return response(view('martis::app'))
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Sign in with the emailed token, once the page confirmed it.
     *
     * Answers 422 for an invalid or expired token, and 409
     * (`session_conflict`) without consuming the token when the browser is
     * signed in as another user and `replace_session` is not true.
     *
     * @body-param string email required The address the link was sent to.
     * @body-param string token required The token of the emailed link.
     * @body-param boolean replace_session Confirm signing out the user this browser is signed in as.
     *
     * @response array{redirect: string}
     */
    public function consume(Request $request): JsonResponse
    {
        if (! (bool) config('martis.auth.magic_link.enabled', false)) {
            return response()->json(['message' => __('martis::auth.magic_link_disabled')], 404);
        }

        [$email, $token] = $this->linkParameters($request->input('email', ''), $request->input('token', ''));

        if ($email === '' || $token === '') {
            return $this->tokenRefused('invalid');
        }

        if (! $this->service->check($email, $token)) {
            return $this->tokenRefused('expired');
        }

        /** @var StatefulGuard $guard */
        $guard = Auth::guard(GuardCatalog::martis());
        $current = $guard->user();

        // A session of another user is not replaced without the browser
        // saying so: answer before the token is spent, so the confirmation
        // can be sent again.
        if ($current !== null && ! $request->boolean('replace_session') && ! $this->isSameUser($current, $email)) {
            return $this->sessionConflict($current);
        }

        $consumedEmail = $this->service->consume($email, $token);
        if ($consumedEmail === null) {
            return $this->tokenRefused('expired');
        }

        $user = $this->resolveUser($consumedEmail);
        if ($user === null && (bool) config('martis.auth.magic_link.auto_register', false)) {
            $user = $this->autoRegister($consumedEmail);
        }

        if ($user === null) {
            return $this->tokenRefused('expired');
        }

        // The session of the user being replaced goes with them: whatever it
        // held (the 2FA pass, an impersonation) is not the new user's.
        if ($current !== null && ! $this->sameIdentifier($current, $user)) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $guard->login($user);
        $request->session()->regenerate();

        // Every sign-in starts without a 2FA pass: a magic link must not
        // inherit the pass of an earlier sign-in of this browser session.
        TwoFactorPass::revoke($request->session());

        // A password, magic-link or invitation sign-in is not an SSO one: drop
        // the SSO origin an earlier SSO sign-in left in this session or in
        // the browser's cookie (SsoSession), and every cookie of it the user
        // kept, so the forced password change gate and the federated logout
        // do not read it.
        SsoSession::forget($request, $user);

        return response()->json(['redirect' => '/'.ltrim((string) config('martis.path', 'martis'), '/')]);
    }

    private function loginPath(): string
    {
        return '/'.ltrim((string) config('martis.path', 'martis'), '/').'/login';
    }

    /**
     * The email and the token of a link, lowercased and as strings: a
     * parameter sent as an array (`email[]=`) reads as missing.
     *
     * @return array{string, string}
     */
    private function linkParameters(mixed $rawEmail, mixed $rawToken): array
    {
        return [strtolower(is_string($rawEmail) ? $rawEmail : ''), is_string($rawToken) ? $rawToken : ''];
    }

    /** The 422 of a token the server refuses; `$reason` is `invalid` or `expired`. */
    private function tokenRefused(string $reason): JsonResponse
    {
        $message = __('martis::auth.magic_link_'.$reason);

        return new JsonResponse([
            'message' => $message,
            'errors' => [['field' => 'token', 'message' => $message, 'code' => $reason]],
        ], 422);
    }

    /** The 409 of a browser signed in as another user, with the confirmation to ask. */
    private function sessionConflict(Authenticatable $current): JsonResponse
    {
        $message = __('martis::auth.magic_link_session_conflict', [
            'current' => (string) (data_get($current, 'email') ?? $current->getAuthIdentifier()),
        ]);

        return new JsonResponse([
            'message' => $message,
            'errors' => [['field' => 'session', 'message' => $message, 'code' => 'session_conflict']],
        ], 409);
    }

    /** Whether the signed-in user is the one the link is for. */
    private function isSameUser(Authenticatable $current, string $email): bool
    {
        $target = $this->resolveUser($email);

        return $target !== null && $this->sameIdentifier($current, $target);
    }

    private function sameIdentifier(Authenticatable $a, Authenticatable $b): bool
    {
        return $a::class === $b::class && (string) $a->getAuthIdentifier() === (string) $b->getAuthIdentifier();
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
            'password' => Hash::make(Str::random(40)),
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
