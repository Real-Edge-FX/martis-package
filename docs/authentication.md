# Authentication

Martis provides a complete authentication system, from login and logout to two-factor authentication (2FA), magic links and a user profile page. Every part, browser-session management included, is configurable and overridable.

> See also: [SSO](sso.md) for OAuth/OIDC providers (Azure / Google / GitHub / custom), and [Impersonation](impersonation.md) for the login-as-another-user subsystem (admins surfacing a switch from the user menu and from the User Resource detail page).

## Login Flow

Martis uses Laravel's authentication guards. By default, it uses the application's default guard.

### Login Page

The login page is accessible at `/{martis-path}/login`. It renders a form with email and password fields, styled to match the current theme.

**API Endpoint:**

```
POST /martis/api/auth/login
Content-Type: application/json

{
    "email": "admin@example.com",
    "password": "secret",
    "keep_signed_in": true
}
```

`keep_signed_in` is optional (defaults to `false` when omitted). When `true`, the login issues Laravel's long-lived remember-me cookie (`remember_web_*`) so the session survives past `config('session.lifetime')`; the login page renders the "Keep me signed in on this device" toggle checked by default. Both the SPA endpoint above and the non-SPA `POST /{martis-path}/login` share the same handling (v1.31.1+).

**Responses:**

| Status | Body | Description |
|--------|------|-------------|
| `200` | `{ "id": 1, "name": "Maria", "email": "...", "avatar_url": "...", ... }` | Successful login. The user object is returned flat (no `user` wrapper). Authentication is session-cookie based, so there is no `token` to track. |
| `200` | `{ "two_factor_required": true, "message": "..." }` | 2FA enabled on the account — the frontend redirects to the challenge screen. |
| `200` | `{ "email_verification_required": true, "message": "..." }` | (v1.8.14+) Email verification is enabled (`MARTIS_AUTH_EMAIL_VERIFICATION_ENABLED=true`) and the user has not confirmed yet. The session is still established so the resend-link endpoint behind `auth:` works — the frontend redirects to `/{martis-path}/email/verify` instead of the dashboard. The same gate appears on `GET /api/auth/user` as `email_verification_pending: true` so a refresh / deep-link reload bootstraps on the verify page rather than the SPA shell. |
| `200` | `{ "password_change_required": true, "message": "..." }` | (v2.3.0) The [forced password change](#forced-password-change) holds the user: the session is established and the frontend goes to the change page. `GET /api/auth/user` answers `password_change_pending: true` for the same user, so a reload lands on the page too. |
| `422` | `{ "message": "...", "errors": {...} }` | Validation error. |
| `429` | `{ "message": "Too many attempts" }` | Rate limited. Default: `MARTIS_LOGIN_THROTTLE_ATTEMPTS=20` per `MARTIS_LOGIN_THROTTLE_MINUTES=1`. The same envelope applies to register, password-reset and 2FA challenge endpoints. Per-email throttle in addition to per-IP — see [Per-email throttle](#per-email-throttle) below. |

> **Auth model.** Martis is **session-cookie based**, not bearer-token based. The Laravel guard sets the cookie on `attempt()` + `session()->regenerate()`. There is no JWT; no `Authorization: Bearer …` header is needed (or accepted) by any Martis endpoint. Same-origin SPA calls work out of the box.

### Per-email throttle

Besides the per-IP `throttle:N,1` envelope (configured via `MARTIS_LOGIN_THROTTLE_ATTEMPTS` / `_MINUTES`), both password sign-in routes (`POST /{martis-path}/login` since v2.4.0, and `POST /api/auth/login`) and the magic-link request run a second named limiter, `martis-login`, that holds one limit for a request that names an email (lowercased and trimmed): the email **and the client IP**, `MARTIS_LOGIN_THROTTLE_ATTEMPTS` (20) per `MARTIS_LOGIN_THROTTLE_MINUTES` (1). It stops one machine guessing at one account, without one IP's attempts counting against another's.

On its own that limit gives every source IP a bucket of its own for the same email, so an attacker spreading guesses over N addresses got N x 20 guesses a minute at one account. The **per-account limit** (v2.4.0) closes that: `MARTIS_LOGIN_THROTTLE_EMAIL_ATTEMPTS` (100) per `MARTIS_LOGIN_THROTTLE_EMAIL_MINUTES` (15), the same bucket whatever the source IP. It is a higher threshold over a longer window, so a user who mistypes a password never meets it, and it is applied by the sign-in controllers (`Martis\Auth\AccountLoginThrottle`), not by the named limiter:

- **Only wrong passwords count.** The controller refuses with `429` (before it checks the password, the right one included) when the bucket is used up, counts a wrong password, and clears the bucket on a right one. The owner's own good sign-ins never spend it.
- **One bucket per account, not per spelling.** It is keyed on the id of the user the email matches in the Martis guard's provider (a case- or accent-insensitive database collation matches `Victim@x` and `victím@x` to one row), falling back to the lowercased, trimmed email when no user matches.
- **The magic-link request has a bucket of its own** (same size): it counts its own requests, known address or not, so password noise cannot starve it, its requests cannot starve the password sign-in, and one address cannot be mailed without bound.

The cost of any per-account limit is that someone who sends that many wrong passwords for an email can keep its owner from signing in for the rest of the window; raise the threshold, or set `MARTIS_LOGIN_THROTTLE_EMAIL_ATTEMPTS=0` to turn the per-account limit off and keep the first. Up to v2.3.0 `POST /{martis-path}/login` carried the per-IP throttle only and the `martis-login` limiter had no per-account limit at all.

The named limiter is registered in `MartisServiceProvider::registerRateLimiters()` and reads its config per request. Empty-payload requests (no `email` field at all) fall back to per-IP keying so an unfocused script still hits the rate limit. Override the default by re-registering `RateLimiter::for('martis-login', ...)` in your own service provider — last definition wins.

### Logout

```
POST /martis/api/auth/logout
```

Invalidates the server-side session. The browser cookie is cleared on the next request.

### Check Current User

```
GET /martis/api/auth/user
```

Public route (deliberately unprotected) so the Login page can probe the active session without a noisy `401` in the console. Returns the user object when a session cookie is present, or `null` when the visitor is a guest.

The user object, here and in the login response, is the user model's attributes without the password, the remember token and the 2FA secret and recovery codes, plus the avatar the Topbar shows, from the profile resource (see [Custom Profile Resource](#custom-profile-resource)): `avatar_url`, and the `avatar_initials` and `avatar_palette` (a slot of the theme's `--martis-avatar-N` tokens) it falls back to without a picture.

## Auth UI shell

All unauthenticated pages (Login, Register, 2FA challenge, 404 / 403 / 500) share a single shell component:

```
components/auth/AuthFrame.tsx        — dot-grid background, brand row, card slot, Shell footer
components/auth/AuthControls.tsx     — guest-mode theme + language pickers (top-right)
components/auth/ErrorScreen.tsx      — watermark code + accent icon + CTA buttons
```

`AuthFrame` is the only shell; each page (`pages/Login.tsx`, `pages/Register.tsx`, `pages/TwoFactorChallenge.tsx`, `pages/NotFound.tsx`, `pages/Forbidden.tsx`, `pages/ServerError.tsx`) renders its own content inside.

### CSS surface

The auth CSS lives in `resources/css/martis.css` and uses the namespaced `.martis-auth-*` family:

| Class | Role |
|------|------|
| `.martis-auth-frame` | Full-viewport wrapper with the accent halo background. |
| `.martis-auth-bg` | Dot-grid overlay with a radial mask. |
| `.martis-auth-card` | Surface card (400 px default) that holds the form. |
| `.martis-auth-brand` | Logo row at the top of the card. No text label — the logo asset already carries the wordmark. |
| `.martis-auth-title` / `.martis-auth-sub` | Primary title and muted subtitle. |
| `.martis-auth-divider` | "or" rule between SSO buttons and email/password. |
| `.martis-auth-foot` | Centered footer matching the Shell footer (`© {brand} · Powered by Martis`). |
| `.martis-auth-controls` | Top-right theme / language toggle strip. |
| `.martis-auth-back` | Circular back button (used on the 2FA challenge). |
| `.martis-auth-forgot` | Inline "Forgot?" link. |
| `.martis-auth-toggle` / `.martis-auth-toggle-track` | Custom toggle switch for the "Keep me signed in" option. |
| `.martis-auth-otp-row` / `.martis-auth-otp-input` | 6-cell OTP grid for the 2FA challenge. |
| `.martis-error-screen` / `.martis-error-code` / `.martis-error-icon` / `.martis-error-title` / `.martis-error-desc` / `.martis-error-id` | Error screens (404 / 403 / 500) with watermark code and optional incident id. |

### Brand logo

`AuthFrame` reads the logo from `@images/logo.png` shipped with the package. Consumers that rebuild the frontend can swap the import with their own asset. The brand row renders **only the image** — do not add a text wordmark: the logo already contains "Martis".

## Alternative sign-in flows

Martis ships themed pages and default backend handlers for **all four** guest auth surfaces. Every surface is independently togglable, every page can point off-platform, and every backend handler can be swapped for a consumer's own implementation.

The four surfaces:

| Surface | Frontend page | Backend endpoint | Default handler |
|---|---|---|---|
| Login | `pages/Login.tsx` | `POST /{martis-path}/api/auth/login` | `LoginController` (always on) |
| Register | `pages/Register.tsx` | `POST /{martis-path}/api/auth/register` | `Martis\Auth\DefaultRegistersUsers` |
| Forgot password | `pages/ForgotPassword.tsx` | `POST /{martis-path}/api/auth/password/email` | `Martis\Auth\DefaultSendsPasswordResetLinks` |
| Reset password | `pages/ResetPassword.tsx` | `POST /{martis-path}/api/auth/password/reset` | `Martis\Auth\DefaultResetsUserPasswords` |

### `config/martis.php` → `auth`

```php
'auth' => [
    'sso' => [
        'enabled'   => env('MARTIS_SSO_ENABLED', false),
        'providers' => [
            // Per-provider blocks (azure, google, github, …) live here.
            // See sso.md for the full schema and `martis:sso` scaffolder.
        ],
    ],
    'passwordReset' => [
        'enabled' => env('MARTIS_AUTH_PASSWORD_RESET_ENABLED', false),
        // Empty → Martis serves /forgot-password and /reset-password.
        // Set    → "Forgot?" link redirects off-platform.
        'url'     => env('MARTIS_AUTH_PASSWORD_RESET_URL'),
        // Laravel password broker name (config/auth.php → passwords.*).
        // Unset: the broker whose provider is the Martis guard's.
        'broker'  => env('MARTIS_AUTH_PASSWORD_BROKER'),
    ],
    'registration' => [
        'enabled'      => env('MARTIS_AUTH_REGISTRATION_ENABLED', false),
        // Empty → Martis serves /register; Set → off-platform link.
        'url'          => env('MARTIS_AUTH_REGISTRATION_URL'),
        // Optional role to assign to every new user (Spatie/HasRoles).
        'default_role' => env('MARTIS_AUTH_REGISTRATION_DEFAULT_ROLE'),
    ],
    'controls' => [
        'theme'  => env('MARTIS_AUTH_CONTROL_THEME', true),
        'locale' => env('MARTIS_AUTH_CONTROL_LOCALE', true),
    ],
],
```

All flags default to `false`. A fresh `composer require martis/martis` install ships only the Login surface enabled — no placeholder CTAs, no orphan endpoints.

| Block | Shape | Purpose |
|---|---|---|
| `sso` | `enabled` + `providers` map | Renders one button per enabled provider. Martis owns the OAuth dance end-to-end. See [`sso.md`](sso.md). |
| `passwordReset` | `enabled` + `url` + `broker` | Renders the "Forgot?" link, hosts `/forgot-password` and `/reset-password` pages, and POST endpoints. `url` redirects off-platform. `broker` selects the Laravel password broker; unset, Martis picks the one whose provider is the Martis guard's (see [Which password broker resets a password](#which-password-broker-resets-a-password)). |
| `registration` | `enabled` + `url` + `default_role` | Renders the "Create an account" link, hosts `/register` page and POST endpoint. `default_role` is auto-assigned via `assignRole()` when set. |
| `controls` | `theme` + `locale` | Top-right widget visibility on every auth surface. |

### Surface lifecycle (each flow)

1. **Disabled** (`enabled=false`): page route 302s to `/login`; link stays hidden; POST endpoints return 404.
2. **On-platform** (`enabled=true`, `url=''`): Martis renders its own themed page; POST endpoints route through the Martis-shipped default handler.
3. **Off-platform** (`enabled=true`, `url='https://…'`): the link in the Login page points off-platform; the internal page redirects there too; POST endpoints stay at 404 (because the consumer hosts their own elsewhere).

### Forgot password

When `auth.passwordReset.enabled=true` and `url` is empty:

1. Login page renders the "Forgot?" link next to the password field.
2. Click → router pushes `/forgot-password`. The page asks for the email and POSTs to `/api/auth/password/email`.
3. Server calls `Password::broker(<broker>)->sendResetLink()`, which dispatches a notification through whichever mailer the host app has configured (Resend, Mailgun, SES, SMTP).
4. Email arrives with a link to `/reset-password#token=...&email=...` (the token is in the URL fragment, see [One-time links keep the token out of the request line](#one-time-links-keep-the-token-out-of-the-request-line)). The page asks for the new password and POSTs the token, the email and the password to `/api/auth/password/reset`.
5. Server calls `Password::broker(<broker>)->reset()`, fires `Illuminate\Auth\Events\PasswordReset`, and returns 200.
6. Client toasts success and redirects to `/login`.

**The answer does not say whether the address has an account (v2.4.0).** Both endpoints are public, so what they say must not let an anonymous caller confirm which addresses are panel accounts:

- `POST /api/auth/password/email` answers `200 { "ok": true, "status": "We have emailed your password reset link." }` for every address the request validates for: a known one, an unknown one, and a known one asked again within the broker's `throttle` window (the broker answers `passwords.throttled` there, which only a known address gets). The response is the same, byte for byte, and only a known address is mailed. An SSO-only account (no password hash) answers the same way. The magic-link request has always worked like this.
- `POST /api/auth/password/reset` answers `422 "This password reset token is invalid."` for an unknown address, as it does for a known one with a wrong token (the broker says `passwords.user` for the first and `passwords.token` for the second).
- With `APP_DEBUG=true` both endpoints give the broker's detailed `422` (`passwords.user`, `passwords.throttled`), which helps while setting reset up. Up to v2.3.0 production answered `422` with that detail too, although the controller's docblock said it was neutral there.

Request validation errors (a malformed address) stay `422` for every caller. Two differences remain that a mailer makes: a mail failure (the SMTP timeout above) answers `503`, and so only for an address that has an account, and sending takes longer than not sending, so a caller who times the endpoint can tell a known address from an unknown one. Send the reset notification on a queue (a `ShouldQueue` notification, see Laravel's notification docs) to remove both.

### Which password broker resets a password

The forgot-password and reset endpoints run on a Laravel password broker (`config/auth.php` → `passwords`), which finds the account by email through its provider. Martis uses the broker of the Martis guard's users (`Martis\Auth\GuardCatalog::martisPasswordBroker()`):

- `MARTIS_AUTH_PASSWORD_BROKER` unset: the app's default broker (`auth.defaults.passwords`) when its provider is the Martis guard's provider, else the first broker whose provider is. A default install (`web` guard, `users` provider, `users` broker) resolves `users`.
- `MARTIS_AUTH_PASSWORD_BROKER` set: that broker, which must exist and whose provider must be the Martis guard's (or sign in users of the same table).

Anything else throws a `Martis\Auth\PasswordBrokerConfigurationException` (an `InvalidArgumentException`) naming `martis.auth.passwordReset.broker`, and the endpoint answers 500 with it reported: a broker of another provider would look the email up among other users (the site's `users` beside an `admins` guard) and mail or reset that account. For an `admins` guard, declare its broker:

```php
// config/auth.php
'passwords' => [
    'users' => ['provider' => 'users', 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60],
    'admins' => ['provider' => 'admins', 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60],
],
```

Nova reads one broker too (`NOVA_PASSWORDS`, `config('nova.passwords')`, the app's default broker when null) and does not check its provider against `NOVA_GUARD`, which [nova-issues#1989](https://github.com/laravel/nova-issues/issues/1989) reports as resetting the wrong users. Martis picks the broker from the guard and refuses a mismatch instead (v2.0.1).

## Registration

When `auth.registration.enabled=true` and `url` is empty:

1. Login page renders the "Create an account" link under the Sign in button.
2. Click → router pushes `/register`. The page asks for `name`, `email`, `password`, `password_confirmation` and POSTs to `/api/auth/register`.
3. Server resolves the bound `RegistersUsers` implementation (default: `DefaultRegistersUsers`), validates, creates the user, optionally assigns `auth.registration.default_role`, fires `Illuminate\Auth\Events\Registered`, and returns 201.
4. Client toasts success and redirects to `/login`.

The `default_role` knob covers the most common SaaS flow ("every signup lands on the `free` plan"). Anything more elaborate (audit logging, locale defaults, payment provider customer creation, Stripe checkout redirect, invite-token validation) goes through the override hook.

## Customising auth surfaces

Martis exposes three independent override layers. Pick the one that matches the depth of the change you need.

### Layer 1 — point the link off-platform (config only)

Set `auth.{flow}.url` to the external URL. The "Sign up" / "Forgot?" link redirects there; the internal page redirects there too; POST endpoints become inert. Useful when the consumer hosts a marketing-grade signup on a different stack (Webflow, Next.js landing, etc.).

```bash
# .env
MARTIS_AUTH_REGISTRATION_ENABLED=true
MARTIS_AUTH_REGISTRATION_URL=https://app.example.com/signup
```

### Layer 2 — replace the React page (component override)

Use the Martis component override system to swap any of the auth pages. The artisan generator scaffolds a TSX file, registers it under a fixed key under `resources/js/martis-extensions/overrides/`, and the SPA router (`router.tsx`) resolves the override before the bundled default — exactly the same mechanism that already works for `--type=shell` / `--type=topbar` / etc.

```bash
php artisan martis:component LoginPage --type=login-page
```

Generates `resources/js/martis-extensions/overrides/LoginPage.tsx` (a working starting point that calls `useAuth().login()` and renders inside `AuthFrame`). The auth-page types always write this fixed file name, whatever name you pass, because the auto-discovery entry (`resources/js/martis-extensions/index.ts`) maps the file name to the registry key through its `OVERRIDE_KEYS` table (`LoginPage` → `auth:login`). No `register()` call is needed. If you register a component by hand instead, use the exact key: `componentRegistry.register('auth:login', MyLogin)`.

Same notation extends to every auth surface:

| `--type=` value | Registry key | Replaces |
|---|---|---|
| `login-page` | `auth:login` | `pages/Login.tsx` |
| `register-page` | `auth:register` | `pages/Register.tsx` |
| `forgot-password-page` | `auth:forgot-password` | `pages/ForgotPassword.tsx` |
| `reset-password-page` | `auth:reset-password` | `pages/ResetPassword.tsx` |
| `email-verify-notice-page` | `auth:email-verify-notice` | `pages/EmailVerifyNotice.tsx` |
| `password-change-page` | `auth:password-change` | `pages/PasswordChangeRequired.tsx` |

After generating the override, build your extension bundle **in your application root** (never inside `vendor/martis/martis`, whose precompiled SPA does not include consumer code since v1.8.19):

```bash
npm run build:extensions
```

The bundle lands in `public/vendor/martis-user/extensions.js`, which the SPA loads at runtime from the URLs in `MARTIS_EXTENSIONS` (`martis:install` sets `/vendor/martis-user/extensions.js`). Visit `/{martis-path}/login` and the override renders instead of the bundled page. To check it, load the page itself: `window.Martis.componentRegistry.has('auth:login')` only shows that the key is registered. Before v2.2.0 the router read the key before the extension bundle loaded, so the bundled page rendered even with the key registered.

Reference impls live under `vendor/martis/martis/resources/js/pages/` — the stub starts as a working copy of the bundled default so you can edit incrementally rather than rewrite from scratch.

### Layer 3 — replace the backend handler (service container binding)

Bind your own implementation of the relevant contract in your service provider. The Martis-shipped controllers resolve the contract from the container, so the consumer's class transparently takes over.

```php
// app/Providers/MartisServiceProvider.php
public function register(): void
{
    $this->app->bind(
        \Martis\Contracts\RegistersUsers::class,
        \App\Auth\MyRegistrar::class,
    );

    $this->app->bind(
        \Martis\Contracts\SendsPasswordResetLinks::class,
        \App\Auth\MyResetLinkSender::class,
    );

    $this->app->bind(
        \Martis\Contracts\ResetsUserPasswords::class,
        \App\Auth\MyPasswordResetter::class,
    );
}
```

Each contract has a single method and ships a battle-tested default. Override only what you need.

```php
// app/Auth/MyRegistrar.php
namespace App\Auth;

use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Martis\Contracts\RegistersUsers;
use App\Models\User;

class MyRegistrar implements RegistersUsers
{
    public function register(Request $request): Authenticatable
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'min:12'],   // stricter than default
            'invite'   => ['required', 'exists:invitations,token'],
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'plan'     => 'starter',                              // skip the default 'free'
        ]);

        $user->assignRole('starter');
        $user->markInvitationConsumed($data['invite']);

        event(new Registered($user));

        return $user;
    }
}
```

The Martis-shipped React form already understands the response shape (`201` on success, `422` with `errors.field` on validation failure), so the React side keeps working as long as the override returns an `Authenticatable` and throws `ValidationException` on invalid input.

> **Building an invite-token check like the `invite` field above?** Consider `martis:invitations` instead of hand-rolling it against self-service registration. It ships a dedicated `InvitationManager` (hashed single-use tokens, TTL, enumeration-neutral accept flow), a public accept screen, and a generator that scaffolds the admin-side resource + actions — a closed, invite-only onboarding flow that composes with (rather than bolts onto) the registration pipeline above. See [invitations.md](invitations.md).

### Layer-by-layer compatibility

The three layers compose. Override the React page AND the backend AND keep the Martis-themed link visibility (Layer 1 staying empty). Or override only the React page and let the default backend keep working. Or do nothing and ship with the defaults.

The lemma we hold onto: **flexibility and personalization**. Every visible string, every business rule, and every page is replaceable; the boring infrastructure (route registration, throttle, CSRF, broker plumbing) stays out of the consumer's way.

### Guest controls visibility (`auth.controls`)

Two compact widgets live in the top-right of every auth surface (Login, Register, 2FA challenge, error pages): a theme cycle button (dark → light → system) and a language picker. Each one is independently togglable via `config/martis.php`:

```php
'controls' => [
    'theme'  => env('MARTIS_AUTH_CONTROL_THEME', true),
    'locale' => env('MARTIS_AUTH_CONTROL_LOCALE', true),
],
```

| Toggle | Default | Effect when `false` |
|--------|---------|----------------------|
| `theme`  | `true` | Hides the theme cycle button. Theme still resolves from `config/martis.theme.default`, the user's stored preference, or the system setting — only the visual control disappears. |
| `locale` | `true` | Hides the language picker. The active locale stays in effect from the per-user preference / `config/martis.locale` / `config/app.locale` — only the picker disappears. |

When both flags are `false`, the strip itself doesn't render, so a single-locale single-theme deployment gets a pristine login screen.

#### Guest persistence (v1.7.6)

A guest's choice on the strip persists in `localStorage` (`martis-preferences` key) and survives a hard refresh. The picker NEVER calls the server — `/api/preferences` is `martis.auth`-protected and would 401 for a guest, polluting the console. Once the user signs in, `readInitialPrefs` keeps their localStorage choice (priority over the server "default" payload) and the next explicit `update()` call writes it server-side.

**Whose picks they are (v2.4.0).** A guest's picks are carried to the account that signs in with one `PUT /api/preferences` only when they were made in the tab session of the person signing in: a marker (`martis-preferences-guest-modified`) is set in `sessionStorage` when a guest changes a preference, and consumed by the sign-in that follows in that tab (it survives the full page load a redirect brings). On a shared browser, picks a previous visitor left in `localStorage` never reach the next account: that sign-in reads the account's own saved preferences. Before v2.4.0 the marker was a `localStorage` flag, bound to no one; a leftover one is ignored and removed. Signing out also drops the cached preferences (`martis-preferences`) and the marker, so the login page that follows starts from the panel defaults.

Both controls render with the global PrimeReact tooltip (`data-pr-tooltip`), so styling matches the rest of the shell.

### Customising the auth copy

Two paths, in order of recommendation. Pick one:

#### Path 1 — Publish the language files (recommended for multi-locale)

This is the canonical Laravel path and what you want when:

- you ship multiple languages,
- you want translators to edit copy without touching PHP config,
- you also want to override other Martis strings (toasts, validation messages, dashboard greeting, …).

```bash
# In the consumer app, run once:
php artisan vendor:publish --tag=martis-lang --force
```

This copies the package's `resources/lang/{en,pt_BR,pt_PT}/*.php` to `resources/lang/vendor/martis/{en,pt_BR,pt_PT}/*.php` in your app. Edit only the keys you want to override — Laravel deep-merges the published file with the package shipped one, so unset keys fall through automatically.

The auth keys live in `auth.php`:

| Key | Renders on |
|---|---|
| `login_title` | Login page heading |
| `login_sub` | Login page subtitle (no SSO providers configured) |
| `login_sub_v2` | Login page subtitle (SSO providers visible) |
| `register_title` / `register_sub` | Register page |
| `forgot_password_title` / `forgot_password_sub` | Forgot-password page |
| `reset_password_title` / `reset_password_sub` | Reset-password page |
| `invitation_accept_title` / `invitation_accept_sub` | Invitation-accept page |

Example — `resources/lang/vendor/martis/pt_BR/auth.php`:

```php
<?php
return [
    'login_title' => 'Entre no Acme',
    'login_sub' => 'Bem-vindo de volta. Use seu e-mail e senha.',
    // any key you don't override falls through to the package default.
];
```

After editing, `php artisan optimize:clear` (or restart php-fpm) for the new strings to land in production. Laravel resolves the active locale automatically; no Martis config involved.

#### Path 2 — `auth.copy` config override (single-locale or env-driven)

When the consumer runs a single language and wants a quick brand override without publishing the lang files, OR when the value has to come from env (CI / Docker injection), use `config('martis.auth.copy.*')`:

```php
'auth' => [
    // ... sso / passwordReset / registration / controls
    'copy' => [
        'login' => [
            'title' => env('MARTIS_AUTH_LOGIN_TITLE'),
            'subtitle' => env('MARTIS_AUTH_LOGIN_SUBTITLE'),
            'subtitle_with_sso' => env('MARTIS_AUTH_LOGIN_SUBTITLE_SSO'),
        ],
        'register' => [
            'title' => env('MARTIS_AUTH_REGISTER_TITLE'),
            'subtitle' => env('MARTIS_AUTH_REGISTER_SUBTITLE'),
        ],
        'forgot_password' => [
            'title' => env('MARTIS_AUTH_FORGOT_TITLE'),
            'subtitle' => env('MARTIS_AUTH_FORGOT_SUBTITLE'),
        ],
        'reset_password' => [
            'title' => env('MARTIS_AUTH_RESET_TITLE'),
            'subtitle' => env('MARTIS_AUTH_RESET_SUBTITLE'),
        ],
        'invitation_accept' => [
            'title' => env('MARTIS_AUTH_INVITATION_ACCEPT_TITLE'),
            'subtitle' => env('MARTIS_AUTH_INVITATION_ACCEPT_SUBTITLE'),
        ],
    ],
],
```

Each entry accepts:

- **`null`** (default) — fall through to the bundled translation (or your published override from Path 1).
- **`string`** — applied verbatim on every locale.
- **`array<locale, string>`** — multi-locale (v1.8.5+). The React `useAuthCopy()` resolves the active locale at render time. **For multi-locale Path 1 is recommended** — keeping copy in lang files keeps the translation workflow standard. The array form exists for projects that prefer to keep all consumer customisation in `config/martis.php`.

#### Resolution order

When a page renders, the `useAuthCopy()` helper resolves each value in this order:

1. `config('martis.auth.copy.<page>.<key>')` — Path 2 override (string, array per locale, or null).
2. `__('martis::auth.<key>')` — published lang file (Path 1) **or** package default.

Path 1 always wins over the package defaults. Path 2 wins over both.

> **Recommendation**: when you have one customisation, use Path 2 (one line in `.env` or `config/martis.php`). When you have many, or you ship more than one language, use Path 1 (publish + edit).

### Password-reset URL routing (v1.8.3)

Laravel's bundled `ResetPassword` notification renders the email link via `route('password.reset', ...)`. Martis nests every route under a `martis.` name prefix, so the global `password.reset` is undefined and the broker would crash with `RouteNotFoundException`. Starting with v1.8.3, `MartisServiceProvider::boot()` registers `ResetPassword::createUrlUsing(...)` automatically when `martis.auth.passwordReset.enabled === true`, pointing the link at the Martis-shipped `martis.password.reset` route (`/{martis-path}/reset-password#token=…&email=…` since v2.6.0).

Since v2.4.0 the link is built on `APP_URL` (`Martis\Support\CanonicalUrl`), never on the request's `Host` or `X-Forwarded-Host`: a reset requested with a forged host used to mail the token to the attacker's domain. The magic link, the invitation and the email-change confirmation are built the same way, so `APP_URL` must be the URL the panel is served on (the package throws a clear error when it is not an absolute http(s) URL, rather than falling back to the request). A signed link (email verification, email change) is signed over that root, so it must be served on the host `APP_URL` names.

The registration is **defensive** — it skips when a callback is already configured by the host app. Consumers who want a custom URL (off-platform reset page, magic-link, deep-link to a mobile app) register their own callback in `AppServiceProvider::boot()`:

```php
use Illuminate\Auth\Notifications\ResetPassword;

public function boot(): void
{
    ResetPassword::createUrlUsing(function ($notifiable, string $token) {
        return 'https://app.example.com/reset#'.http_build_query([
            'token' => $token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], '', '&', PHP_QUERY_RFC3986);
    });
}
```

Order of registration doesn't matter: the consumer callback wins because Martis's probe sees the property already set and bails out.

A callback that points at the Martis page builds the link with `Martis\Support\TokenLink::url('martis.password.reset', ['token' => $token, 'email' => $email])`, which puts both in the fragment on `APP_URL`. Keep the token out of the path and the query string of any link you build yourself; see the next section.

### One-time links keep the token out of the request line

Since v2.6.0 every link Martis emails with a one-time credential in it carries that credential in the URL fragment (after `#`), never in the path or the query string:

| Link | Shape |
|---|---|
| Password reset | `/{martis-path}/reset-password#token=…&email=…` |
| Invitation | `/{martis-path}/invitations/accept#token=…` |
| Magic-link sign-in | `/{martis-path}/magic-link/confirm#email=…&token=…` |

A browser never sends the fragment to the server, so the token does not reach the request line that reverse proxies, web servers, load balancers and APM or tracing layers log by default. Up to v2.5.x the reset and invitation tokens were path segments (`/reset-password/{token}`, `/invitations/accept/{token}`) and the magic-link token a query parameter: every access log on the way recorded a live token, enough to reset a password, accept an invitation or sign in until it expired or was used.

The page the link opens reads the fragment, drops it from the address bar, and sends the token in the body of its `POST` (`/api/auth/password/reset`, `/api/invitations/accept`, `/api/auth/magic-link/consume`). A page override reads it with `useAuthLinkParams()` from `@martis/runtime` (see [overrides.md](overrides.md#auth-page-overrides-and-emailed-links)). The page responses are `Cache-Control: no-store, private` with `Referrer-Policy: no-referrer`.

**Links mailed before the upgrade keep working until they expire.** The old shapes are still routed and answer a redirect to the fragment form, without checking the token: `GET /reset-password/{token}?email=…`, `GET /invitations/accept/{token}`, `GET /magic-link/confirm?email=…&token=…` and the pre-v2.4.0 `GET /api/auth/magic-link/consume?…`. The same redirect applies to a `?token=` query string on `/reset-password` or `/invitations/accept` (and the bare `?<token>` on `/invitations/accept`), which a custom URL callback built with `route('martis.password.reset', [...])` or `route('martis.invitations.accept', $rawToken)` now produces. These requests still put the token in the request line, so they are a transition path, not a shape to build: the legacy routes go in v3.0.0.

Building a link of your own (a custom `ResetPassword::createUrlUsing()` or `InvitationUrl::createUrlUsing()` callback, a custom notification) follows the same rule. `Martis\Support\TokenLink::url($routeName, $parameters)` returns the named route on `APP_URL` with `$parameters` in the fragment.

Email verification and email-change links are signed URLs: the signature is checked by the server, so it stays in the query string. Following one again only completes the action the account owner asked for, on the address they chose, so it grants no access.

### Graceful errors (v1.8.0)

- **Mailer down on forgot-password.** When the host app's mailer fails (SMTP timeout, invalid credentials, queue worker offline), the `POST /api/auth/password/email` endpoint now catches the throwable and returns a structured `503` with `{message: __('auth.forgot_password_mailer_unavailable')}`. The original exception is still passed to `report()` for monitoring. The frontend toast surfaces the translated message instead of the raw 500 stack trace.
- **Feature off.** When `passwordReset.enabled` or `registration.enabled` is `false`, the forgot-password / register pages bounce the visitor back to `/login` with an info toast in the active locale instead of throwing on mount.
- **Defensive JSON parse on the client.** The shared `api.ts` helper tolerates non-JSON error responses (HTML 404s from misconfigured routes); they no longer leak `SyntaxError: Unexpected token <` to the console.

### Minimal consumer recipe

```php
// routes/api.php
Route::post('/martis/api/auth/register', function (Request $request) {
    $data = $request->validate([
        'name'     => ['required', 'string', 'max:255'],
        'email'    => ['required', 'email', 'unique:users,email'],
        'password' => ['required', 'confirmed', 'min:8'],
    ]);

    $user = User::create([
        'name' => $data['name'],
        'email' => $data['email'],
        'password' => Hash::make($data['password']),
    ]);

    event(new Registered($user));

    return response()->json(['user' => $user], 201);
});
```

## Email verification

Martis ships a complete email-verification surface. Off by default — flipping a single config flag wires the middleware, themed pages, and POST endpoint at once.

### When does Martis send a verification email?

Whenever you call `event(new \Illuminate\Auth\Events\Registered($user))`, Laravel core checks whether `$user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail`. If yes, Laravel core dispatches the stock `\Illuminate\Auth\Notifications\VerifyEmail` notification through whichever mailer the host app has configured.

The Martis-shipped `DefaultRegistersUsers` fires `Registered` on every successful signup. So:

- **User model implements `MustVerifyEmail`** → email goes out automatically on signup.
- **User model does not implement `MustVerifyEmail`** → no email is ever sent.

Martis intentionally does not force the contract on the consumer's User model. Some apps do not want email verification at all; others have their own delivery flow (magic links, SSO-only, etc.).

> **Block-on-misconfiguration (v1.8.9+).** When `MARTIS_AUTH_EMAIL_VERIFICATION_ENABLED=true` AND your User model does NOT implement `MustVerifyEmail`, the `martis.verified` middleware now falls back to checking the `email_verified_at` column directly. The standard Laravel `users` migration ships that column, so on a default install:
>
> - The user is **blocked** from the panel after registration (column is null).
> - But no verification email is sent (Laravel's listener is keyed on the contract).
> - `DefaultRegistersUsers` writes a clear warning to `laravel.log` so the dev sees the misconfiguration on the first signup.
>
> Pre-v1.8.9 the middleware silently let unverified non-`MustVerifyEmail` users in — a security regression closed by this change. Add `implements MustVerifyEmail` to `App\Models\User` to unblock the email send + verify-link flow.

### Enable the full Martis verification flow

Three steps:

1. **User model** must implement `MustVerifyEmail`:
   ```php
   use Illuminate\Contracts\Auth\MustVerifyEmail;

   class User extends Authenticatable implements MustVerifyEmail { /* … */ }
   ```

2. **Set the master flag**:
   ```env
   MARTIS_AUTH_EMAIL_VERIFICATION_ENABLED=true
   # optional — redirect off-platform when blocked instead of /email/verify
   MARTIS_AUTH_EMAIL_VERIFICATION_NOTICE_URL=
   ```

3. **Mailer must be configured** (Postmark, Resend, Mailgun, SES, SMTP — anything Laravel supports). The verification notification goes through the default mail channel.

### What happens when the flag is on but the mailer isn't?

The package does not pre-validate `MAIL_*` env on boot. Three scenarios depending on the value of `MAIL_MAILER`:

| `MAIL_MAILER` | Effect on registration | Effect on user | What to do |
|---|---|---|---|
| `log` (Laravel's default on a fresh install) | Registration succeeds. Verification URL is written to `storage/logs/laravel.log`. | Login still blocks the user at `/email/verify` with a "resend" button. The link is reachable from the log file for the dev to forward / paste. | Acceptable in dev. **Set a real mailer before going live** so users actually receive the link. |
| `smtp` / `postmark` / `resend` / `ses` / `mailgun` configured AND reachable | Registration succeeds. Email is sent through the host's mailer. | Standard flow — user clicks link, lands on `/email/verify/{id}/{hash}`, column gets populated, panel unlocks. | Nothing extra. |
| `smtp` (or any external transport) configured but **broken** (wrong host, wrong creds, network unreachable) | `Mail::send` throws. Registration POST returns **500**. The user is created server-side but never sees the success response. | The user can still log in (account exists, unverified), reaches `/email/verify`, hits "Resend" — which throws again. | Fix the mailer. Until then, set `MAIL_MAILER=log` so registration completes cleanly even with no real outbound email. |

A 500 on registration is bad UX, but Martis intentionally does not swallow mailer exceptions: a silent send failure would leave the operator thinking the system worked while every newly registered user is stuck. Loud failure surfaces the misconfiguration immediately. If you want a custom recovery path (e.g. queue the email and retry), bind your own `SendsEmailVerification` implementation — see "Customising the verification email" below.

Once `enabled=true`, the package:

- Registers the `martis.verified` middleware alias and applies it to every protected Martis route. Unverified users hitting `/martis`, `/martis/profile`, `/martis/resources/...` etc. are redirected to `/{martis-path}/email/verify` (or the URL set in `notice_url`).
- Renders the themed notice page at `/{martis-path}/email/verify`. Override via `martis:component MyVerifyNotice --type=email-verify-notice-page`.
- Handles the signed verify link at `/{martis-path}/email/verify/{id}/{hash}` — marks `email_verified_at`, fires `Verified`, redirects to the dashboard.
- Exposes `POST /{martis-path}/api/auth/email/verification-notification` so the notice page can offer a "resend" button.
- Overrides Laravel's `VerifyEmail::createUrlUsing()` so the bundled notification builds its signed link against the Martis-named route (`martis.email.verify`) instead of the framework default `verification.verify` (which Martis does not register). Without this override registration would 500 with `Route [verification.verify] not defined` as soon as the bundled listener runs. The override is gated on the same `enabled` flag and skips when a consumer has already installed its own callback. (v1.8.13+)
- The shared SPA `request()` interceptor watches for `409 { message: "Your email address is not verified." }` and forces a hard navigation to `/{martis-path}/email/verify`. The middleware itself only redirects HTML requests, so without this hook a logged-in unverified user who reached the dashboard via client-side navigation would see every API call 409 in place. Mirrors the existing 401 → `/login?expired=1` behaviour. (v1.8.13+)
- The verification link route (`/email/verify/{id}/{hash}`) is **public** — only the `signed` URL middleware applies. The signed signature plus the `sha1(email)` hash baked into the path are unforgeable proofs of intent on their own, and gating the route behind `martis.auth` broke the most common case (user opens the email on their phone, clicks the link from a logged-out tab, gets bounced to `/login`, signature lost). Logged-out clicks now redirect to `/{martis-path}/login?verified=1` so the SPA shows a "Email verified. You can sign in now." toast. Logged-in clicks land on the dashboard as before. (v1.8.16+)
- Resend-verification throttle dropped from `6/min` to `3/min` (matches the conventional ceiling for password-reset and verification-resend flows). (v1.8.16+)
- Post-register UX: when `email_verification.enabled=true`, the SPA surfaces "Check your inbox to verify your email before signing in." (i18n key `register_success_verify`) and routes to `/email/verify`. Previously the SPA showed "Account created. Please sign in." and routed to `/login`, where the v1.8.14 login gate immediately bounced the user back. The verify-notice page also detects guest visits (no session) and renders i18n key `verify_sub_guest` with a "Back to sign in" CTA in place of the auth-only Resend / Sign-out buttons. The blade exposes `auth.emailVerification.enabled` so the SPA can branch deterministically. (v1.8.16+)

### What if the user loses the verification email?

The shipped notice page (`/{martis-path}/email/verify`) has a "Resend verification link" button. Clicking it calls `POST /{martis-path}/api/auth/email/verification-notification`, which resolves the bound `SendsEmailVerification` implementation and re-dispatches the email.

The endpoint is throttled to 6 attempts per minute per user (`throttle:6,1`) so a stuck loop or a malicious actor cannot flood the inbox. Any consumer-side override can keep its own throttle on top.

If the user cannot reach the page (e.g. signed out completely), they can re-trigger the email by signing in again — the middleware redirects them to the notice page automatically as long as their account is unverified.

### Customising the verification email

Bind your own implementation of `Martis\Contracts\SendsEmailVerification`:

```php
// app/Providers/MartisServiceProvider.php
use Martis\Contracts\SendsEmailVerification;
use App\Auth\BrandedVerificationMailer;

public function register(): void
{
    $this->app->bind(SendsEmailVerification::class, BrandedVerificationMailer::class);
}
```

```php
// app/Auth/BrandedVerificationMailer.php
namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Martis\Contracts\SendsEmailVerification;

class BrandedVerificationMailer implements SendsEmailVerification
{
    public function send(Authenticatable $user): void
    {
        $user->notify(new \App\Notifications\BrandedVerifyEmail);
    }
}
```

The shipped `EmailVerificationController::send()` resolves this contract from the container, so the consumer's class transparently takes over the resend flow.

### Customising the notice page (Layer 2)

```bash
php artisan martis:component MyVerifyNotice --type=email-verify-notice-page
```

Same mechanism as Login/Register/Forgot/Reset — see the table in "Customising auth surfaces" → Layer 2.

### Surface lifecycle

| Flag state | Middleware behaviour | Notice page | Verify link | Resend endpoint |
|---|---|---|---|---|
| `enabled=false` (default) | Pass-through, no gating | 404 | 404 | 404 |
| `enabled=true`, user verified | Pass-through | 302 → dashboard | Marks verified, 302 → dashboard | 200 (re-sends; harmless) |
| `enabled=true`, user unverified | 302 → notice (or `notice_url`) | 200 (themed page) | Marks verified, 302 → dashboard | 200 |
| `enabled=true`, JSON request | 409 with `{message}` | 409 | normal | normal |

## Password rules

Every Martis surface that sets a password validates it with your app's password policy, `Password::defaults()`, as Nova 5 does (v2.3.0): Profile, registration, password reset, invitation accept, the [forced password change](#forced-password-change) and `martis:user`. Without app defaults the rule is Laravel's `Password::min(8)`. Declare yours once, in a service provider:

```php
use Illuminate\Validation\Rules\Password;

public function boot(): void
{
    Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->uncompromised());
}
```

The password checklist of those pages draws the same rules. The Blade shell sends them to the SPA as `window.MartisConfig.auth.passwordRequirements`: minimum and maximum length, mixed case, letters, numbers, symbols, and "not in a known data leak", which only the server checks. A rule of your own that implements `Illuminate\Contracts\Validation\Rule` shows no checklist; the server still enforces it, and its message shows under the field. Anything else (an array of rules, a string, a `ValidationRule`, what `php artisan make:rule` generates) is what Laravel's `Password::default()` silently replaces with `Password::min(8)`, so Martis refuses it: `PasswordPolicy` throws an `InvalidArgumentException` naming what `Password::defaults()` gave. A resource's `Password` field follows the policy with `->defaultRules()` (see [Fields → Password](fields.md#password)).

### `PasswordPolicy`

`Martis\Auth\PasswordPolicy` is the single source of that policy: every Martis surface, and your own code, reads it from there instead of calling `Password::default()` again.

- `PasswordPolicy::rule()` returns the rule a new password must pass (`Illuminate\Contracts\Validation\Rule`): your `Password::defaults()` result, else (no defaults, or a closure that returns `null`) `Password::min(8)`. It throws an `InvalidArgumentException` when `Password::defaults()` gives anything that is not a `Rule`. Use it in your own validation to follow the same policy: `'password' => ['required', 'confirmed', PasswordPolicy::rule()]`.
- `PasswordPolicy::requirements()` returns the same policy as the array the SPA's checklist reads (`minLength`, `maxLength`, `uppercase`, `lowercase`, `letters`, `number`, `symbol`, `uncompromised`, each present only when the rule demands it), or `null` when the app's default is a rule other than `Password`. It is what the shell sends as `passwordRequirements`. The custom rules of a `Password` (`->rules([...])`) are not in it; the server still enforces them.

The SPA reads two keys of `window.MartisConfig.auth`: `passwordRequirements` (the array above, or `null`) and `passwordChange`, `{ enabled, url }`: whether the [forced password change](#forced-password-change) gate is on, and where a held user goes (the built-in `/{martis-path}/password/change` page unless `MARTIS_AUTH_PASSWORD_CHANGE_URL` names another one, which may be an absolute URL on another origin).

Every password is hashed with your app's hasher (`HASH_DRIVER`), `Hash::make()`.

## Forced password change

Hold a user on a password change page until they choose a new password, for example after an administrator set a temporary one (v2.3.0). Off by default. Nova 5 has no equivalent; the gate mirrors the [email verification](#email-verification) gate.

### Enable it

1. Publish the column and migrate:

   ```bash
   php artisan vendor:publish --tag=martis-password-change-migration
   php artisan migrate
   ```

   The migration adds the boolean column `must_change_password` (`MARTIS_AUTH_PASSWORD_CHANGE_COLUMN`), default `false`, to the table of the users the Martis guard signs in.

2. Turn the gate on: `MARTIS_AUTH_PASSWORD_CHANGE_ENABLED=true`.

   If you published `config/martis.php` before v2.3.0, it has no `password_change` entry under `auth`, so the variable is never read and the gate stays off: copy the `password_change` block from the package's `config/martis.php` into yours (or republish the config and merge your changes).

3. Flag a user: `$user->forceFill(['must_change_password' => true])->save()`. An action can do it while it sets a temporary password, and show that password once with a [custom modal response](actions.md#custom-modal-responses).

Without the column, and without the contract below, the gate holds nobody: a missing column never locks your users out.

### Your own rule: `MustChangePassword`

A user model that implements `Martis\Contracts\MustChangePassword` decides itself, and Martis then ignores the column:

```php
use Martis\Contracts\MustChangePassword;

class User extends Authenticatable implements MustChangePassword
{
    public function mustChangePassword(): bool
    {
        return $this->password_expires_at?->isPast() ?? false;
    }

    public function markPasswordChanged(): void
    {
        // Martis saves the user right after this call, with the new password.
        $this->password_expires_at = now()->addDays(90);
    }
}
```

### What the gate does

- **Where it runs.** `martis.password.changed` runs last in the stack of every protected route, after the 2FA challenge, email verification and the panel gate (see [Middleware](#middleware)), so a user with 2FA passes the challenge first.
- **What a held user gets.**
  - A JSON request answers `409 {"password_change_required": true, "message": "Password change required."}`, and the SPA leaves for the change page.
  - A page redirects to `/{martis-path}/password/change`, or to `MARTIS_AUTH_PASSWORD_CHANGE_URL` when you host your own page.
  - The sign-in answer carries `password_change_required`, and so does the 2FA challenge answer. `GET /api/auth/user` carries `password_change_pending`. The dashboard never paints behind the gate.
- **The page.** `/{martis-path}/password/change` asks for the current password and a new one that follows your [password rules](#password-rules) and differs from the current one. Replace it under `auth:password-change` (`php artisan martis:component --type=password-change-page`).
- **The endpoint.** `POST /{martis-path}/api/auth/password/change` takes `current_password`, `password` and `password_confirmation`. It answers `403` to a user the gate does not hold, and shares the sign-in throttle.
- **Who is never held.** An impersonation (the operator must never choose the user's password), and a session opened through SSO (the user knows no password), also after the session ends and the remember-me cookie the SSO sign-in set signs the user back in: the SSO origin is kept in an encrypted cookie of that user (see [SSO → Federated logout](sso.md#federated-logout-single-sign-out)). Sign-out, `GET /api/auth/user` and the translations stay reachable.
- **Limitation: the gate is per session.** The bypass for impersonation and SSO is a marker in the session, not a property of the user.
  - A flagged user who signs in through SSO or a magic link knows no password: do not flag such users.
  - A remember-me restore opens a fresh session without the SSO marker. A flagged user who signed in through SSO is then held, and cannot satisfy `current_password`, which they never knew.
- **What clears the flag.** The change itself, a change from Profile, and a password reset by email: a listener on `Illuminate\Auth\Events\PasswordReset` clears it, so it keeps working if you rebind `ResetsUserPasswords` and still fire the event.
- **After a change.** As Fortify (behind Nova 5's User Security page) does:
  - the user's pending reset tokens are deleted;
  - `Martis\Events\PasswordChanged` fires, with `$event->user` and `$event->forced` (true on the change page, false from Profile);
  - the remember token is not rotated;
  - other sessions are not signed out unless `auth.session` (Laravel's `AuthenticateSession`) is in `martis.auth_middleware`, which signs them out on their next request.

## Magic-link (passwordless) sign-in

Off by default. When enabled, the Login page exposes a "Email me a sign-in link" button that issues a one-shot token and emails it. The link opens a confirmation page ("Sign in as x@y") and the sign-in happens when the user confirms it: no password required.

### Enable

```dotenv
MARTIS_AUTH_MAGIC_LINK_ENABLED=true
# Optional — defaults shown
MARTIS_AUTH_MAGIC_LINK_TTL=15
MARTIS_AUTH_MAGIC_LINK_AUTO_REGISTER=false   # auto-create users for unknown emails
```

Or directly in `config/martis.php`:

```php
'magic_link' => [
    'enabled' => env('MARTIS_AUTH_MAGIC_LINK_ENABLED', false),
    'ttl_minutes' => (int) env('MARTIS_AUTH_MAGIC_LINK_TTL', 15),
    'auto_register' => (bool) env('MARTIS_AUTH_MAGIC_LINK_AUTO_REGISTER', false),
],
```

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/martis/api/auth/magic-link/request` | `{ email }` → issues a token and emails it. Returns `200 { ok: true }` whether or not the email exists (account-enumeration safe). |
| `GET` | `/martis/magic-link/confirm#email=…&token=…` | The emailed link (v2.4.0; email and token in the URL fragment since v2.6.0, so the server never receives them on this request). Serves the SPA confirmation page, which signs nobody in, so a mail scanner, a link preview, a prefetch or an `<img>` that loads it does nothing (twice over: the token survives). An expired or invalid token is refused by the `POST` below, and the page then goes to `/{martis-path}/login?magic_link=expired` (or `=invalid`); with magic links off the `GET` redirects to `?magic_link=disabled`. A link with the email and token in the query string (mailed by v2.4.0 to v2.5.x) is redirected to the fragment form. The response is `no-store` with `Referrer-Policy: no-referrer`. |
| `POST` | `/martis/api/auth/magic-link/consume` | `{ email, token, replace_session? }`, CSRF-protected. Consumes the token and signs the user in; answers `200 { redirect }`. `422` for an invalid or expired token. `409 { code: "session_conflict" }`, token untouched, when the browser is signed in as another user and `replace_session` is not `true`: the page then asks before replacing that session. |

Up to v2.3.x the emailed link was `GET /api/auth/magic-link/consume` and signed in on load: it let anyone who could make a victim's browser load a URL (an `<img>`, a redirect, a chat link) swap the victim into the attacker's account, and a mail scanner burned the token. That URL now signs in only on `POST`; a `GET` of it (a link mailed before the upgrade) is redirected to the confirmation page with its email and token in the fragment, so the link still works and nothing happens until the user confirms.

### Storage

Tokens are persisted in the same `password_reset_tokens` table Laravel ships with, scoped under an `email` value prefixed with `martis-magic:` so they never clash with reset-password rows. Tokens are hashed before storage; the plaintext token only exists in the email body. Issuing a fresh token deletes any prior token for the same email.

### Security envelope

- **One-shot.** Consuming a token deletes the row, and the delete decides who got it: two simultaneous requests cannot both sign in. Replays return `expired`.
- **On `APP_URL`.** The emailed URL is built from `APP_URL`, never the request's host (see [Password-reset URL routing](#password-reset-url-routing-v183)).
- **No silent session swap.** A browser signed in as another user keeps that session until the confirmation page's explicit `replace_session`.
- **Short TTL.** Default 15 minutes — a magic-link is mailbox-equivalent, so the leak window matches the threat profile.
- **Per-email throttle.** The request endpoint sits behind both per-IP `throttle:N,1` and the `martis-login` named limiter (per-email + IP). A flood against `victim@example.com` is blocked even when distributed across IPs.
- **No account enumeration.** When the email is unknown the endpoint still returns `200 { ok: true }` and sends nothing. The frontend toast is identical to the success path.
- **Auto-register opt-in.** Default false. When you flip it on, an unknown email triggers a user create with a random password before the login completes — useful for invite-by-link flows.
- **The Martis guard's users.** The email is looked up, and auto-registered, in the user provider of the Martis guard (`MARTIS_GUARD`, else the app's default guard), the guard the link signs into (v2.0.0+). v1.x looked it up in `users`: with a custom guard whose model has its own table, the link of a site account signed in the panel user with the same id. A Martis user model without `Illuminate\Notifications\Notifiable` is mailed at the address the link was asked for.

### Customising the email

Bind a subclass of `Martis\Auth\MagicLinkNotification` against the FQCN, or override `MagicLinkController` entirely if you need to swap the storage strategy. The notification uses the standard `MailMessage` shape so consumer-side `php artisan vendor:publish --tag=laravel-notifications` to publish the email template still applies.

## Browser sessions

Profile page gets a "Browser sessions" surface (`<BrowserSessionsSection />`) that lists every active session for the current user — IP, user agent, last activity, and a "This device" badge on the current row. Two revoke flows let the user kick stale devices: per-row trash icon (skipped on the current row to prevent accidental self-logout) and a one-click "Sign out everywhere else" button that drops every other session.

### Enabling

The section is mounted by default when `martis.profile.sections` includes `'sessions'`. Since v1.8.8 the bundled default is `['avatar', 'account', 'password', 'security', 'sessions']`. Older installs that pinned the list to four entries need to add `'sessions'` to the published `config/martis.php` to see the panel.

#### Requirements

The Browser sessions section has an external host dependency (unlike the other profile sections):

| Requirement | Detail |
|---|---|
| **Session driver** | `SESSION_DRIVER=database`. On `file` / `cookie` / `array` / unknown the API returns `supported: false` and the UI renders a one-line hint instead of an empty list. |
| **`sessions` table** | The standard Laravel session table must exist. |
| **One user table** | Laravel writes `sessions.user_id` with the id of the request's guard and no table. When the session guards of `config/auth.php` (with the Martis and the default guard) sign in users of more than one table, as a custom `MARTIS_GUARD` with its own model beside the site's `users` does, an id there can be another person's: the API returns `supported: false` with a `reason` the UI shows, and revokes nothing (v2.0.0+). Guards that share one table, through any provider or model, are supported. |

**Provisioning (since v1.30.0):** run `php artisan martis:install --with-sessions` to publish a **key-type-aware** sessions migration (published under the `martis-sessions-migration` tag, folded into `--with-profile` when the section is active). Its `user_id` column matches your `users.id` shape — a UUID/ULID-keyed host gets a `uuid`/`ulid` column instead of a `bigint`, avoiding the Postgres `invalid input syntax for type bigint` failure that Laravel's stock `php artisan session:table` (`foreignId('user_id')`) causes on non-integer-keyed users tables. The migration is idempotent (skipped when the table already exists) and carries no FK constraint, matching Laravel's own session table. You may still use `php artisan session:table` + `php artisan migrate` if your `users.id` is a `bigint`.

**Install-time preflight:** when `'sessions'` is in `profile.sections` but `SESSION_DRIVER` isn't `database`, `martis:install` prints an actionable warning (it never fails the install) so the dependency surfaces at setup time rather than only at runtime. To hide the section entirely, remove `'sessions'` from `profile.sections`.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/martis/api/profile/sessions` | `{ sessions: [...], supported, driver }`, plus `reason` when the session rows cannot be attributed (see the requirements). Each session row carries `id` (an opaque handle, see below), `ip_address`, `user_agent`, `last_active` (unix seconds), and `is_current`. |
| `DELETE` | `/martis/api/profile/sessions/others` | Revokes every session except the current one. Returns `{ revoked, supported }` (204 when unsupported). |
| `DELETE` | `/martis/api/profile/sessions/{id}` | Revokes a single session by the `id` handle of the list (never the raw session id). Targeting the current session, or a handle that names none of the user's sessions, is a no-op (`revoked: 0`) so the call cannot accidentally sign the user out of the device issuing the request. |

**The `id` of a session is an opaque handle (v2.4.0).** A session's `sessions.id` is the server-side credential of that device's session; the list used to send it to the browser so the client could revoke it, which hands it to anything that can read the response (a script injected in the panel, a logged HAR), and takes the session over in an app that excludes the session cookie from encryption. The `id` the API gives each row is now an HMAC (SHA-256) of the session id and the user with the app key, 64 hex characters: it cannot be turned back into the session id, it differs per user and per `APP_KEY`, and `DELETE /profile/sessions/{id}` resolves it on the server among the user's own sessions (`BrowserSessionsService::handle()`). The raw session id revokes nothing. A custom `martis:profile-sessions` component keeps working: it only has to pass the row's `id` back. Rotating `APP_KEY` changes every handle, which a list loaded before the rotation does not survive (reload it).

### Customisation

- **Backend**: bind your own subclass of `Martis\Profile\BrowserSessionsService` against the FQCN to extend with geo-IP enrichment, audit logging, or cross-device push notifications. The service exposes `forUser(Authenticatable, Request)`, `revokeOthers(Authenticatable, Request)`, and `revoke(Authenticatable, Request, string $id)`.
- **UI**: register a custom React component under the `martis:profile-sessions` registry key from your consumer extension bundle (`resources/js/martis-extensions/`) to swap the bundled `BrowserSessionsSection`. When unset, the bundled component renders.
- **Translations**: keys live under the `profile` namespace: `sessions_title`, `sessions_subtitle`, `sessions_loading`, `sessions_empty`, `sessions_unsupported`, `sessions_unsupported_guards` (the `reason` above), `sessions_current_badge`, `sessions_unknown_ip`, `sessions_revoke`, `sessions_revoke_success`, `sessions_revoke_others`, `sessions_revoke_others_confirm`, `sessions_revoke_others_success`, `sessions_revoking`. All shipped in en, pt_PT, pt_BR.

## Error pages

Three themed error screens are wired into the router:

| Path | Page | Crumb translation key |
|------|------|------------------------|
| `*` (catch-all) | `pages/NotFound.tsx` | `navigation.error_not_found` |
| `/403` | `pages/Forbidden.tsx` | `navigation.error_forbidden` |
| `/500` | `pages/ServerError.tsx` | `navigation.error_server_error` |

Each page renders the shared `components/auth/ErrorScreen.tsx` component. Server-side 500s should redirect to `/{martis-path}/500?incident=inc_...` — the `ServerErrorPage` reads the query string (`incidentId` prop) or falls back to a random placeholder so production installs display the incident id alongside the copy button.

## 2FA challenge redesign

The 2FA challenge at `{martis-path}/2fa/challenge` now follows the design-system spec:

- Back arrow that cancels the challenge (logs the user out) and email label rendered muted next to it.
- Six `.martis-auth-otp-input` cells with auto-advance on input, arrow-key navigation, backspace backtracking, and paste-to-fill support.
- 30-second visual countdown + "Use a backup code" toggle that swaps the OTP row for a plain recovery-code input.
- Auto-submit once all six cells are filled, with inline error on failure and the existing 5-minute inactivity timeout preserved.

## Configuration

In `config/martis.php`:

```php
'guard' => env('MARTIS_GUARD', null),  // null = Laravel default guard
'middleware' => ['web'],                // Applied to all routes
'auth_middleware' => ['martis.auth'],   // Applied to protected routes
```

### Custom Guard

To use a separate guard for Martis:

```php
// config/auth.php
'guards' => [
    'martis' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
],

// config/martis.php
'guard' => 'martis',
```

The panel then runs as that guard: `MartisAuthenticate` makes it the request's guard (v2.0.0+), so `$request->user()`, `auth()->user()`, the gates, the policies and the protected routes' throttle see the user it signed in, an instance of its provider's model. Type policies and gate closures for that model (or `Authenticatable`), give the model `Illuminate\Notifications\Notifiable` for the notification bell, and see [Upgrading → A custom Martis guard](upgrading.md#a-custom-martis-guard).

The auth flows use that guard's provider: the login, the magic link, the registration and invitation accept (the email is unique among that guard's users), the email verification link, SSO and `php artisan martis:user`. Password reset uses the password broker of that guard's users (see [Which password broker resets a password](#which-password-broker-resets-a-password)). When that provider's model has its own table (`admins`), the Martis migrations reference it (the preferences and invitations foreign keys) and add the two-factor and avatar columns to it, and the Browser sessions section reads as unsupported, since the `sessions` table cannot tell an admin's id from a site user's (see [Browser sessions](#browser-sessions)).

## User Profile

The profile page is accessible at `/{martis-path}/profile` and provides:

- **Account Information** — Edit name and email
- **Change Password** — Update password with current password confirmation
- **Profile Picture** — Upload, preview, and remove avatar
- **Two-Factor Authentication** — Enable/disable TOTP-based 2FA

### Profile Configuration

```php
// config/martis.php
'profile' => [
    'enabled' => true,                     // Set false to disable entirely
    'resource' => null,                    // Custom ProfileResource FQCN
    'menu' => [
        'label' => null,                   // null = i18n default
        'icon' => 'user',                 // Phosphor icon name
    ],
    'avatar' => [
        'enabled' => true,
        'disk' => 'public',              // Filesystem disk
        'path' => 'avatars',             // Sub-directory
        'max_size_kb' => 2048,           // Max upload size (2MB)
        'column' => 'profile_picture',   // DB column for avatar path
        'url_resolver' => null,          // Custom URL generator (see below)
    ],
    'two_factor' => [
        'enabled' => true,
        'recovery_codes' => 8,           // Number of one-time codes
    ],
    'account' => [
        // MARTIS_PROFILE_EMAIL_EDITABLE — when false, the Account section
        // renders the e-mail field read-only. Default true.
        'email_editable' => true,
    ],
    'sections' => ['account', 'password', 'avatar', 'security', 'sessions'],
],
```

#### Custom avatar URLs

By default the avatar URL comes from the disk (`Storage::disk($disk)->url($path)`). To serve avatars from a CDN or through signed URLs, set `avatar.url_resolver` to the name of an invokable class or a `[Class::class, 'staticMethod']` array. It receives the stored path and returns the public URL, for the upload response and the profile payload alike:

```php
'avatar' => [
    'url_resolver' => \App\Martis\AvatarUrl::class, // __invoke(string $storedPath): string
],
```

Both forms survive `php artisan config:cache`; a closure does not. A value that resolves to no callable throws an `InvalidArgumentException` naming the key. See [Configuration → Config keys that take a callable](configuration.md#config-keys-that-take-a-callable).

#### Changing the email address (v2.4.0)

The address is the identity of the account: it receives the password reset and the sign-in link, and an SSO provider that matches by email adopts the local account that holds it. So `PATCH /api/profile` no longer writes a new address on the spot:

1. A request that changes the address must send `current_password` (`422` without it or with a wrong one, nothing written). The same address in another letter case is no change.
2. The name is saved at once. The address stays as it is, a temporary signed link on `APP_URL` is mailed to the NEW address and a notice to the OLD one, and the answer carries `pending_email`. The profile page then shows "check your new inbox".
3. Opening the link only shows a confirmation page (`GET`, signed): a mail scanner, a link preview or a prefetch loads a mailed link, and an attacker may name someone else's address as the new one, so a bare `GET` never applies the change. Clicking the button on that page sends a CSRF-protected `POST` to the same signed URL, and that checks again that the address is still free, switches it, resets `email_verified_at` for an app that verifies email (and sends the verification link to the new address) and notifies the old address. The link works once; one issued before another change is dead. `martis.profile.email_change.ttl_minutes` (env `MARTIS_PROFILE_EMAIL_CHANGE_TTL`, default 60) sets its lifetime.

**The mail is limited** (v2.4.0): the request mails an address of the user's choosing, so `martis.profile.email_change.throttle_attempts` (env `MARTIS_PROFILE_EMAIL_CHANGE_ATTEMPTS`, default 5) per `throttle_minutes` (`MARTIS_PROFILE_EMAIL_CHANGE_ATTEMPTS_MINUTES`, default 60) bounds both how many a user may ask for and how many one address may be sent, whoever asks (so several accounts cannot mail one address without bound). Past it the profile answers `429` with `Retry-After` before it saves anything. A save that changes no address is never limited; `0` turns the limit off.

Nothing is stored for the pending change (no migration): the link carries the user's id, the new address and a fingerprint of the old one.

#### Locking the e-mail field

The e-mail is often the acting identity, so a deployment may want name, avatar,
and password editable while the e-mail stays fixed. Set
`profile.account.email_editable` to `false` (env `MARTIS_PROFILE_EMAIL_EDITABLE`)
and the built-in Account section renders the e-mail field read-only.

This flag is the **UI half only**. Pair it with a custom `ProfileResource` that
also rejects e-mail changes server-side (see [Custom Profile Resource](#custom-profile-resource)),
so a hand-crafted `PATCH /martis/api/profile` request cannot bypass the locked field.

### Profile API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/martis/api/profile` | Get current user profile data |
| `PATCH` | `/martis/api/profile` | Update the name. A new email needs `current_password` and is applied only after the confirmation link is followed (see [Changing the email address](#changing-the-email-address-v240)) |
| `GET` | `/martis/profile/email/confirm/{id}` | The signed, temporary link mailed to the new address: opens the confirmation page, changes nothing |
| `POST` | `/martis/profile/email/confirm/{id}` | Applies the change (same signed URL and query string, CSRF-protected). Answers `{outcome, redirect}`: `changed`, `invalid` or `rejected` |
| `POST` | `/martis/api/profile/password` | Change password (validated with your [password rules](#password-rules)) |
| `POST` | `/martis/api/profile/avatar` | Upload avatar (multipart/form-data) |
| `DELETE` | `/martis/api/profile/avatar` | Remove avatar |

### Custom Profile Resource

The profile resource is the class behind the profile page. `Martis\Contracts\ProfileResourceContract` has three methods:

| Method | Role |
|--------|------|
| `toArray(Authenticatable $user): array` | The profile data of `GET` and `PATCH /martis/api/profile`: the page reads `name`, `email`, `avatar_url` and `two_factor_enabled`, and the avatar's `avatar_initials` and `avatar_palette`. `/martis/api/auth/user` and the login response take the three avatar keys from it too, so the Topbar shows the avatar the profile page shows. When the array has no `avatar_initials` or `avatar_palette`, Martis adds the initials and palette slot of the user's name (or e-mail), as the `Avatar` and `UiAvatar` fields compute them. |
| `updateRules(Authenticatable $user): array` | The validation rules of `PATCH /martis/api/profile`. |
| `applyUpdate(Authenticatable $user, array $data): void` | Saves the validated data. |

Extend the default `Martis\Profile\ProfileResource` and override what you need, then name the class in `profile.resource`. This one keeps the e-mail fixed server-side, the other half of [Locking the e-mail field](#locking-the-e-mail-field):

```php
namespace App\Martis;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Martis\Profile\ProfileResource;

class CustomProfileResource extends ProfileResource
{
    public function updateRules(Authenticatable $user): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function applyUpdate(Authenticatable $user, array $data): void
    {
        /** @var Model&Authenticatable $user */
        $user->forceFill(['name' => $data['name']])->save();
    }
}

// config/martis.php
'profile' => [
    'resource' => \App\Martis\CustomProfileResource::class,
],
```

The request is validated against `updateRules()` and only the validated keys reach `applyUpdate()`, so an `email` sent to this resource is dropped.

`profile.resource` must name a class that implements the contract. Any other value (a misspelt class, a class that does not implement it) throws an `InvalidArgumentException` naming the key; `null` keeps the default. Up to v1.39.1, a class that did not exist fell back to the default without a word, and `/martis/api/auth/user` always used the default resource, so the Topbar could show another avatar than the profile page.

## Two-Factor Authentication (2FA)

Martis includes TOTP-based two-factor authentication with a guided setup wizard.

### Setup Flow

1. User clicks "Enable 2FA" on the profile page
2. Backend generates a TOTP secret and QR code SVG
3. User scans the QR code with an authenticator app (Google Authenticator, Authy, etc.)
4. User enters the 6-digit verification code
5. Backend verifies the code and generates recovery codes
6. 2FA is now active

### 2FA API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/martis/api/profile/2fa/setup` | Initialize 2FA (returns QR code SVG + secret) |
| `POST` | `/martis/api/profile/2fa/confirm` | Verify OTP code and activate 2FA |
| `POST` | `/martis/api/profile/2fa/recovery-codes` | Regenerate the recovery-code set for an already-active 2FA account. Takes `current_password` (v2.4.0), and the user is told by email |
| `DELETE` | `/martis/api/profile/2fa` | Disable 2FA for current user. Takes `current_password` |
| `POST` | `/martis/api/2fa/challenge` | Submit 2FA code during login. Rate limited per user and per IP (`MARTIS_2FA_THROTTLE_*`, 5 a minute per user by default) and locks the user out after consecutive wrong codes (`MARTIS_2FA_LOCKOUT_*`), v2.4.0. `422` wrong code, `429` rate limited, `403` locked out |

### 2FA Challenge on Login

When a user with 2FA enabled logs in:

1. Initial login returns `{ "two_factor_required": true, "message": "..." }` instead of the user object
2. The frontend redirects to the 2FA challenge screen
3. User enters their 6-digit TOTP code (or a recovery code)
4. On success, the session is fully authenticated

### The 2FA challenge is rate limited and locks out (v2.4.0)

The challenge guards a 6-digit code (with the one step of tolerance either side, about 3 valid codes in a million at any moment), so it has a limiter and a lockout of its own, both in the `throttle` block of `config/martis.php` ([2FA challenge throttle](configuration.md#2fa-challenge-throttle)):

1. **A limiter** (`martis-2fa-challenge`): 5 requests a minute per user and 15 per IP by default. Past either limit the route answers `429` and does not look at the code, so a right code sent at the 6th attempt is not accepted either. Up to v2.3.0 the challenge shared the login throttle, 20 a minute per user.
2. **A lockout.** After 5 consecutive wrong codes (a TOTP code or a recovery code; a right code starts the count over) the pending session ends (the user is signed out, the session is invalidated) and the route answers `403` with `{"two_factor_locked": true, "message": "..."}`. The SPA shows the message and goes to the login page. The user is then **locked out of the challenge for 15 minutes**: a fresh password sign-in is needed, and during the lockout the challenge refuses every code, the right one and the recovery codes included, and ends the session again. The count belongs to the user, not to the session, so signing in again does not start it over: that is what gives guessing a ceiling for someone who holds the password. The lockout is written to the log (`warning`).

The lockout trades availability for that ceiling: whoever holds the password can keep the owner out of the challenge by failing five times every 15 minutes. If that is a worse risk for your users than the guessing it stops, raise `MARTIS_2FA_LOCKOUT_ATTEMPTS`, shorten `MARTIS_2FA_LOCKOUT_MINUTES`, or set the attempts to `0` to turn the lockout off and keep the limiter. The counters live in the cache (the rate limiter's store), so a cache flush clears them, and a lockout can be cleared by hand with `RateLimiter::clear('martis-2fa-lockout:{guard}:{user id}')`.

### The 2FA pass is bound to the user (v2.4.0)

Completing the challenge (or confirming the setup on the profile page) leaves a **pass** in the session: the auth identifier of the user who earned it, under the `martis_two_factor_passed_for` session key (`Martis\Auth\TwoFactorPass`). `martis.2fa` and `GET /api/auth/user` accept the pass only for that user, and every sign-in of the Martis guard forgets it: the password sign-ins (`POST /login` and `POST /api/auth/login`), a magic link, an invitation accept, SSO, the remember-me cookie and the start and stop of an impersonation all fire Laravel's `Illuminate\Auth\Events\Login`, which `Martis\Auth\Listeners\ResetTwoFactorPass` listens to. A user who has 2FA therefore meets the challenge again on every sign-in, and a pass earned by one account never reaches another account signed in later from the same browser session.

Up to v2.3.0 the session held a bare `martis_two_factor_passed` flag that only `POST /api/auth/login` reset: someone who held a victim's password (or mailbox, with magic links on) and any panel account of their own could pass 2FA on their own account, then sign in as the victim through `POST /login` from the same browser session and skip the victim's challenge. A session that still carries the old flag is not trusted: its user meets the challenge once after the upgrade.

An impersonation hands the operator's pass to the target while it lasts (the operator passed their own challenge to start it, and a target who has 2FA cannot be asked for a code the operator does not hold) and gives it back to the operator on stop. An operator the challenge has not cleared (a programmatic `ImpersonationManager::start()`) hands over nothing: the target meets their own challenge.

If you write your own sign-in route, sign the user in through the Martis guard (`Auth::guard(config('martis.guard'))->login($user)`) and the pass is reset for you. If you grant a pass yourself, for instance in a test, use `TwoFactorPass::grant($request->session(), $user)`, or put `[TwoFactorPass::SESSION_KEY => (string) $user->getAuthIdentifier()]` in the session.

### Recovery Codes

When 2FA is enabled, the system generates one-time recovery codes (default: 8). These codes can be used instead of the TOTP code if the user loses access to their authenticator app. Each recovery code can only be used once.

Recovery codes stand in for the TOTP factor at the challenge, so changing them is as sensitive as disabling 2FA (v2.4.0):

- **`POST /api/profile/2fa/recovery-codes` needs `current_password`**, as `DELETE /api/profile/2fa` does. A wrong or missing password answers `422` and leaves the codes alone. The profile page asks for the password in a dialog before it calls the endpoint. Before v2.4.0 the session alone was enough, so whoever held a session that was not the owner's (a stolen or left-open one) could mint a permanent second factor and lock the owner out of their saved codes.
- **The user is told by email** that their recovery codes were regenerated (`Martis\Auth\RecoveryCodesRegeneratedNotification`, a mail notification: extend it to change the wording). The mail goes to the user when the model uses `Illuminate\Notifications\Notifiable`, else to the `email` of the account. A mailer that is down never breaks the request: the codes are already regenerated, and the failure is reported to the exception handler. The subject and lines are the `2fa_regen_mail_*` keys of the `profile` translations (en, pt_PT, pt_BR).
- **The factor- and password-changing endpoints refuse an impersonation.** While an operator impersonates a user ([Impersonation](impersonation.md)), `POST /api/profile/2fa/setup`, `POST /api/profile/2fa/confirm`, `DELETE /api/profile/2fa`, `POST /api/profile/2fa/recovery-codes` and `POST /api/profile/password` answer `403` (`refused_while_impersonating`) and change nothing. They act on whoever the guard signed in, which is the target during an impersonation, and a recovery code, an authenticator secret or a password the operator set would outlive the session.

### Database Requirements

2FA requires the following columns on the table of the Martis guard's users (`users` unless `MARTIS_GUARD` names a guard whose model has its own table; `martis:install --with-2fa` publishes a migration that adds them there):

```php
Schema::table('users', function (Blueprint $table) {
    $table->text('two_factor_secret')->nullable();
    $table->text('two_factor_recovery_codes')->nullable();
    $table->timestamp('two_factor_confirmed_at')->nullable();
    // Replay protection — records the last successful TOTP step so a
    // compromised code cannot be reused within the ±30 s tolerance.
    $table->timestamp('two_factor_last_used_at')->nullable();
});
```

The `martis:install` command includes this migration automatically. Installs that predate the `two_factor_last_used_at` column still verify codes — `TwoFactorService::verifyAndTrack()` probes the schema on first use and skips replay tracking when the column is missing, **logging a warning** (`the two_factor_last_used_at column is missing ...`) so the gap is visible: without the column a TOTP code can be used again for as long as it is valid (about 90 seconds). Add the column to close it. The check does not fail closed: a missing column never locks users out.

### A code is single-use (v2.4.0)

- **A TOTP code.** `two_factor_last_used_at` holds the **start time of the 30-second step** the last accepted code was for (`step * 30`), and any step at or before it is refused. Up to v2.3.0 it held the wall-clock time of the acceptance, so a code accepted for the step after the current one (a client clock running ahead, a code seen before it became current) was accepted again once that step was current. The step start is stored **in UTC**, whatever `app.timezone` is, and read back as UTC: a local wall-clock string is ambiguous for the hour a daylight-saving change repeats, where a newer step would sort before an older one and a valid code be refused. A value written by an earlier version reads as the step it fell in (as UTC, so with an `app.timezone` east of UTC a user who signed in with 2FA in the hours before the upgrade may wait for the next code), and the next acceptance rewrites it. Keep the database session time zone at UTC (the default of most MySQL, PostgreSQL and SQLite setups) so the `timestamp` column holds the string as written.
- **The step is consumed with one conditional update** (`UPDATE ... WHERE two_factor_last_used_at IS NULL OR two_factor_last_used_at < step`). Two requests that carry the same code at once both find it valid, but only one update changes a row; the other is refused. An error while recording the step also refuses the code: a replay guard that cannot record is not one to authenticate through.
- **A recovery code** is consumed inside a transaction that reads the user's row again `FOR UPDATE`, and removes the matching hash only if it is still in the list. Two requests with one recovery code cannot both pass, and a request that read the list before another consumed a different code cannot write the old list back. On SQLite the lock is a no-op (writes are serialised anyway).

## User Menu

The user dropdown menu in the topbar is configurable:

```php
// config/martis.php
'user_menu' => [
    'showThemeToggle' => true,      // Dark/light mode toggle
    'showProfile' => true,          // Profile page link
    // 'customItems' => [
    //     ['label' => 'Settings', 'icon' => 'gear', 'url' => '/settings'],
    //     ['separator' => true],
    //     ['label' => 'Docs', 'icon' => 'book-open', 'url' => 'https://docs.example.com'],
    // ],
],
```

### Custom items

Each entry in `customItems` accepts `label`, `icon`, `url`, and an optional
`position`. Use `['separator' => true]` for a divider between groups.

| Key | Description |
|-----|-------------|
| `label` | Menu text. Resolved through i18n: when the value matches a translation key it is translated (and follows the active locale); otherwise it renders verbatim. Config files can't call `__()`, so pass the key here and it behaves like every other Martis surface. |
| `icon` | **Phosphor icon name** (the same names the sidebar uses, e.g. `key`, `gear`, `book-open`), rendered as an inline SVG through the shared icon path. |
| `url` | Internal path (navigated via the SPA router, like Profile) or a full `https://…` URL (opens in a new tab). A protocol-relative `//host/…` value, or one with a backslash or a control character, is not an internal path: since v2.0.1 it renders as a plain link instead of going through the router. |
| `position` | `'before'` (default) or `'after'`, relative to the built-in Profile entry. `'before'` keeps the item above Profile; `'after'` places it below. |

> **Breaking change (v1.29.0):** `icon` is now a Phosphor icon name, not a
> PrimeIcons class. Replace legacy values like `'pi pi-key'` with the bare
> Phosphor name (`'key'`). This aligns the custom-item icons with the built-in
> Profile / Sign-out entries and fixes the label misalignment caused by the
> PrimeIcons font-glyph box metrics.

```php
'user_menu' => [
    'customItems' => [
        // Translation key → follows the active locale.
        ['label' => 'menu.api_keys', 'icon' => 'key', 'url' => '/api-keys'],
        // Literal label, placed below the Profile entry.
        ['label' => 'Billing', 'icon' => 'credit-card', 'url' => '/billing', 'position' => 'after'],
        ['separator' => true],
        ['label' => 'Docs', 'icon' => 'book-open', 'url' => 'https://docs.example.com'],
    ],
],
```

## On the roadmap: WebAuthn / Passkeys

The current 2FA layer is TOTP-only. Passkey support (WebAuthn ceremony, credential storage, browser API) is a substantial addition that pulls in a third-party library (`web-auth/webauthn-lib`) and a new migration — both decisions that warrant their own design pass. Tracked separately; not shipped in v1.8.8.

The contract layout above (bind your own `Martis\Contracts\*` implementations) is the same surface the future WebAuthn flow will use, so consumer overrides written for TOTP today do not need to change when passkeys ship.

## Middleware

Martis registers these middleware:

| Middleware | Description |
|-----------|-------------|
| `martis.auth` | Authenticates the user and checks the configured guard. Applied to all protected routes. |
| `martis.impersonation.duration` | Stops an impersonation that ran past `MARTIS_IMPERSONATION_MAX_DURATION` minutes. Applied to all protected routes. |
| `martis.2fa` | Ensures users with 2FA enabled have completed the challenge: `423` for a JSON request, a redirect to the challenge screen otherwise. Applied to every protected route but the challenge itself. |
| `martis.locale` | Applies the user's saved language before the controller runs. |
| `martis.verified` | When email verification is enabled, refuses an unverified user: `409` for a JSON request, a redirect to the notice otherwise. |
| `martis.authorize` | When the app defines the `viewMartis` gate, refuses a user it denies (`403`). |
| `martis.password.changed` | When the forced password change is enabled, holds a flagged user: `409` for a JSON request, a redirect to the change page otherwise (v2.3.0). |
| `martis.tool:{uriKey}` | Answers `404` to a user the tool `{uriKey}` is hidden from, and `403` with its lock payload to one it is soft-locked for (v2.4.0). Applied to a Tool's routes (v2.0). |
| `martis.gate` | Answers `403` with the lock payload when a route names a resource, lens, card, dashboard or tool the user is soft-locked from (`lockedFor()`, `requirePlan()`). Applied to the package's API routes that name an entity, not to the two page endpoints that answer the lock themselves, and not part of `martis.api` (v2.4.0, see [Soft-gates](gates.md#what-a-lock-stops-on-the-server)). |
| `martis.api` (group) | The whole stack of a protected API route, from `martis.middleware` to the API throttle, built when the application boots (v2.0). |

These are applied automatically by the Martis route definitions. You do not need to register them manually. The stack of a protected API route is built in one place, `Martis\Http\RouteMiddleware::api()`: `martis.middleware`, `martis.auth_middleware`, `martis.impersonation.duration`, `martis.2fa`, `martis.locale`, `martis.verified`, `martis.authorize`, `martis.password.changed`, then the API throttle. A Tool's routes run it too, followed by `martis.tool:{uriKey}` (see [Tools → Tool routes and their middleware](tools.md#tool-routes-and-their-middleware)). It is also the `martis.api` middleware group (v2.0), so a route of your own gets the same guard with `Route::middleware('martis.api')`: `martis.auth` alone lets a user who has not passed the 2FA challenge through.

## Next Steps

- [Resources](resources.md) — Define admin resources
- [Authorization](resources.md#authorization) — Control access with policies
- [Configuration](configuration.md) — Full config reference
