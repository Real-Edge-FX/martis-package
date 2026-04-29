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

Martis ships the Login shell for SSO, password reset, and self-service registration. Each flow is gated behind a config flag so enterprise installations can hide anything they don't offer.

### `config/martis.php` → `auth`

```php
'auth' => [
    'sso' => [
        'enabled'   => env('MARTIS_SSO_ENABLED', false),
        'providers' => [
            // Per-provider blocks (azure, google, github, …) live here.
            // Each provider has its own MARTIS_SSO_<NAME>_ENABLED flag
            // plus the standard Socialite credentials. See sso.md for
            // the full schema, the four canonical recipes, and the
            // `martis:sso <provider>` scaffolding command.
        ],
    ],
    'passwordReset' => [
        'enabled' => env('MARTIS_AUTH_PASSWORD_RESET_ENABLED', false),
        'url'     => env('MARTIS_AUTH_PASSWORD_RESET_URL'),
    ],
    'registration' => [
        'enabled' => env('MARTIS_AUTH_REGISTRATION_ENABLED', false),
        'url'     => env('MARTIS_AUTH_REGISTRATION_URL'),
    ],
    'controls' => [
        'theme'  => env('MARTIS_AUTH_CONTROL_THEME', true),
        'locale' => env('MARTIS_AUTH_CONTROL_LOCALE', true),
    ],
],
```

| Block | Shape | Purpose |
|---|---|---|
| `sso` | `enabled` (master switch) + `providers` (per-provider nested map) | Renders one button per enabled provider. Each button redirects to `/{martis-path}/sso/{provider}/redirect`, which Martis owns end-to-end (no consumer-supplied URL). See [`sso.md`](sso.md). |
| `passwordReset` | `enabled` + `url` | Renders the "Forgot?" link next to the password field. Click sends the user to the configured `url` — the host app provides the reset flow (Laravel's `Password::sendResetLink` + a Martis-themed view is typical). |
| `registration` | `enabled` + `url` | Renders the "Create an account" link under the Sign in button. When `url` is empty Martis posts to its own `/{martis-path}/api/auth/register` (see [Registration](#registration) below); when `url` is set the link redirects off-platform. |
| `controls` | `theme` + `locale` | Toggles the visibility of the top-right widgets on auth surfaces. |

All flags default to `false` so a fresh `composer require martis/martis` ships a clean email+password login with no placeholder CTAs.

### SSO providers — pointer to `sso.md`

Martis owns the SSO flow end-to-end. The Login page reads `auth.sso.providers` and renders one button per enabled provider; the button redirects to the Martis-managed route `/{martis-path}/sso/{provider}/redirect`, which performs the OAuth/OIDC dance, resolves roles, and creates the session.

There is **no `auth.google`, `auth.azure`, or `auth.sso.url` config key**. Providers live nested under `auth.sso.providers.{name}`. The currently shipping providers are `azure`, `google`, and `github` — all scaffoldable via:

```bash
php artisan martis:sso azure   # or google, or github
```

For the full provider schema (role mapping, permission adapters, identity matching) see [`sso.md`](sso.md).

### Forgot password

The "Forgot?" link sits next to the password field and only renders when `auth.passwordReset.enabled=true`. The link is a redirect to `auth.passwordReset.url`, which the host app is expected to provide — Laravel's built-in password reset (`Password::sendResetLink`) plus a Martis-themed view is the usual implementation.

When a user's account is SSO-only (no password hash stored), the backend reset handler should respond with a helpful message like "This account signs in with SSO" rather than a generic "email sent" — while keeping the public response generic to avoid account enumeration.

## Registration

Martis ships a fully themed registration page at `{martis-path}/register` gated by `auth.registration.enabled`. When the flag is `false`, the route early-redirects to `/login` and the "Create an account" link under the Sign in button stays hidden. No duplicate routes, no placeholder UI.

The page posts to `/{martis-path}/api/auth/register`. Martis does **not** ship a register controller — consumers are expected to expose their own endpoint. Fields sent in the JSON body: `name`, `email`, `password`, `password_confirmation`. Expected responses:

| Status | Body | UI behaviour |
|--------|------|--------------|
| `201` / `200` | anything | Toast "Account created. Please sign in." and redirect to `/login`. |
| `422` | `{ errors: { field: [msg] } }` | Inline per-field errors via `ApiError::errorsByField()`. |
| `404` / `501` | anything | Toast "Registration endpoint is not available. Ask a workspace admin to finish setting it up." |

If the consumer prefers to redirect the user off-platform to an external signup page (common for invite-only workspaces), set `auth.registration.url` to the external URL — the "Create an account" link on Login becomes a normal `<a>` to that URL instead of the internal SPA route.

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
