# Authentication

Martis provides a complete authentication system with login, logout, two-factor authentication (2FA), and a user profile page — all configurable and overridable.

> See also: [SSO](sso.md) for OAuth/OIDC providers (Azure / Google / GitHub / custom), and [Impersonation](impersonation.md) for the v0.10 login-as-another-user subsystem.

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
    "password": "secret"
}
```

**Responses:**

| Status | Body | Description |
|--------|------|-------------|
| `200` | `{ "user": {...}, "token": "..." }` | Successful login |
| `422` | `{ "message": "...", "errors": {...} }` | Validation error |
| `429` | `{ "message": "Too many attempts" }` | Rate limited (5 attempts per minute) |

When 2FA is enabled for the user, the login response returns a `two_factor_challenge` flag instead of a token, redirecting the user to the 2FA challenge screen.

### Logout

```
POST /martis/api/auth/logout
Authorization: Bearer {token}
```

### Check Current User

```
GET /martis/api/auth/user
Authorization: Bearer {token}
```

Returns the authenticated user object or `401` if not authenticated.

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
        // Empty → Martis serves /forgot-password and /reset-password/{token}.
        // Set    → "Forgot?" link redirects off-platform.
        'url'     => env('MARTIS_AUTH_PASSWORD_RESET_URL'),
        // Laravel password broker name (config/auth.php → passwords.*).
        'broker'  => env('MARTIS_AUTH_PASSWORD_BROKER', 'users'),
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
| `passwordReset` | `enabled` + `url` + `broker` | Renders the "Forgot?" link, hosts `/forgot-password` and `/reset-password/{token}` pages, and POST endpoints. `url` redirects off-platform. `broker` selects the Laravel password broker. |
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
4. Email arrives with a link to `/reset-password/{token}?email=...`. The page asks for the new password and POSTs to `/api/auth/password/reset`.
5. Server calls `Password::broker(<broker>)->reset()`, fires `Illuminate\Auth\Events\PasswordReset`, and returns 200.
6. Client toasts success and redirects to `/login`.

When the user account is SSO-only (no password hash), Laravel's broker rejects with `Password::INVALID_USER` — Martis surfaces the localized message under the email field. To avoid account enumeration in production, override the binding (see "Customising auth surfaces" below) and force a generic "if an account exists, an email is on its way" response.

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

Use the Martis component override system to swap any of the auth pages. The artisan generator scaffolds a TSX file, registers it under a fixed key in the consumer's boot.ts, and the SPA router (`router.tsx`) resolves the override before the bundled default — exactly the same mechanism that already works for `--type=shell` / `--type=topbar` / etc.

```bash
php artisan martis:component MyLogin --type=login-page
```

Generates `resources/martis-extensions/martis/components/MyLogin.tsx` (a working starting point that calls `useAuth().login()` and renders inside `AuthFrame`), and adds these two lines to `resources/martis-extensions/martis/boot.ts`:

```typescript
import { MyLogin } from './components/MyLogin'
componentRegistry.register('auth:login', MyLogin as never)
```

Same notation extends to every auth surface:

| `--type=` value | Registry key | Replaces |
|---|---|---|
| `login-page` | `auth:login` | `pages/Login.tsx` |
| `register-page` | `auth:register` | `pages/Register.tsx` |
| `forgot-password-page` | `auth:forgot-password` | `pages/ForgotPassword.tsx` |
| `reset-password-page` | `auth:reset-password` | `pages/ResetPassword.tsx` |
| `email-verify-notice-page` | `auth:email-verify-notice` | `pages/EmailVerifyNotice.tsx` |

After generating the override, rebuild assets so the bundle picks up the new component:

```bash
cd vendor/martis/martis
MARTIS_USER_DIR=$(pwd)/../../../resources/martis-extensions npm run build
```

The build copies the rebuilt `public/` back to your app via `php artisan martis:publish-assets` (or your existing deploy pipeline). Visit `/{martis-path}/login` and the override renders instead of the bundled page.

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

Laravel's bundled `ResetPassword` notification renders the email link via `route('password.reset', ...)`. Martis nests every route under a `martis.` name prefix, so the global `password.reset` is undefined and the broker would crash with `RouteNotFoundException`. Starting with v1.8.3, `MartisServiceProvider::boot()` registers `ResetPassword::createUrlUsing(...)` automatically when `martis.auth.passwordReset.enabled === true`, pointing the link at the Martis-shipped `martis.password.reset` route (`/{martis-path}/reset-password/{token}?email=…`).

The registration is **defensive** — it skips when a callback is already configured by the host app. Consumers who want a custom URL (off-platform reset page, magic-link, deep-link to a mobile app) register their own callback in `AppServiceProvider::boot()`:

```php
use Illuminate\Auth\Notifications\ResetPassword;

public function boot(): void
{
    ResetPassword::createUrlUsing(function ($notifiable, string $token) {
        return "https://app.example.com/reset?token={$token}&email={$notifiable->getEmailForPasswordReset()}";
    });
}
```

Order of registration doesn't matter: the consumer callback wins because Martis's probe sees the property already set and bails out.

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

Once `enabled=true`, the package:

- Registers the `martis.verified` middleware alias and applies it to every protected Martis route. Unverified users hitting `/martis`, `/martis/profile`, `/martis/resources/...` etc. are redirected to `/{martis-path}/email/verify` (or the URL set in `notice_url`).
- Renders the themed notice page at `/{martis-path}/email/verify`. Override via `martis:component MyVerifyNotice --type=email-verify-notice-page`.
- Handles the signed verify link at `/{martis-path}/email/verify/{id}/{hash}` — marks `email_verified_at`, fires `Verified`, redirects to the dashboard.
- Exposes `POST /{martis-path}/api/auth/email/verification-notification` so the notice page can offer a "resend" button.

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
        'url_resolver' => null,          // Custom URL generator
    ],
    'two_factor' => [
        'enabled' => true,
        'recovery_codes' => 8,           // Number of one-time codes
    ],
    'sections' => ['account', 'password', 'avatar', 'security'],
],
```

### Profile API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/martis/api/profile` | Get current user profile data |
| `PATCH` | `/martis/api/profile` | Update name and email |
| `POST` | `/martis/api/profile/password` | Change password |
| `POST` | `/martis/api/profile/avatar` | Upload avatar (multipart/form-data) |
| `DELETE` | `/martis/api/profile/avatar` | Remove avatar |

### Custom Profile Resource

Override the default profile resource by extending `ProfileResource`:

```php
namespace App\Martis;

use Martis\Profile\ProfileResource;

class CustomProfileResource extends ProfileResource
{
    public function fields(Request $request): array
    {
        return [
            // Add custom fields to the profile page
        ];
    }
}

// config/martis.php
'profile' => [
    'resource' => \App\Martis\CustomProfileResource::class,
],
```

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
| `DELETE` | `/martis/api/profile/2fa` | Disable 2FA for current user |
| `POST` | `/martis/api/2fa/challenge` | Submit 2FA code during login (rate limited) |

### 2FA Challenge on Login

When a user with 2FA enabled logs in:

1. Initial login returns `{ "two_factor_challenge": true }` instead of a token
2. The frontend redirects to the 2FA challenge screen
3. User enters their 6-digit TOTP code (or a recovery code)
4. On success, the session is fully authenticated

### Recovery Codes

When 2FA is enabled, the system generates one-time recovery codes (default: 8). These codes can be used instead of the TOTP code if the user loses access to their authenticator app. Each recovery code can only be used once.

### Database Requirements

2FA requires the following columns on the users table:

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

The `martis:install` command includes this migration automatically. Installs that predate the `two_factor_last_used_at` column still verify codes — `TwoFactorService::verifyAndTrack()` probes the schema on first use and skips replay tracking when the column is missing.

## User Menu

The user dropdown menu in the topbar is configurable:

```php
// config/martis.php
'user_menu' => [
    'showThemeToggle' => true,      // Dark/light mode toggle
    'showProfile' => true,          // Profile page link
    'showNotifications' => true,    // Notifications (placeholder)
    // 'customItems' => [
    //     ['label' => 'Settings', 'icon' => 'pi pi-cog', 'url' => '/settings'],
    //     ['separator' => true],
    //     ['label' => 'Docs', 'icon' => 'pi pi-book', 'url' => 'https://docs.example.com'],
    // ],
],
```

## Middleware

Martis registers two middleware:

| Middleware | Description |
|-----------|-------------|
| `martis.auth` | Authenticates the user and checks the configured guard. Applied to all protected routes. |
| `martis.2fa` | Ensures users with 2FA enabled have completed the challenge. Redirects to the challenge screen if pending. |

These are applied automatically by the Martis route definitions. You do not need to register them manually.

## Next Steps

- [Resources](resources.md) — Define admin resources
- [Authorization](resources.md#authorization) — Control access with policies
- [Configuration](configuration.md) — Full config reference
