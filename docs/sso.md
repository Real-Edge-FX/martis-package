# SSO (Single Sign-On) — ⭐ Martis differential

> Pluggable single sign-on for Laravel admin panels. Azure AD, Google Workspace, GitHub, Okta — or any custom IdP — with role mapping, Spatie/laravel-permission integration, environment-aware config, and a generator command (`php artisan martis:sso <provider>`) that scaffolds everything.

This document is the long-form reference. For a 30-second TL;DR see the **Quick start** below. For step-by-step Azure setup, jump to **[Azure AD — full step-by-step](#azure-ad--full-step-by-step)**.

---

## Table of contents

1. [What is the Martis SSO subsystem?](#1-what-is-the-martis-sso-subsystem)
2. [Architecture](#2-architecture)
3. [Quick start (Azure, Spatie)](#3-quick-start-azure-spatie)
4. [Azure AD — full step-by-step](#4-azure-ad--full-step-by-step)
5. [Configuration reference](#5-configuration-reference)
6. [The three orthogonal axes](#6-the-three-orthogonal-axes)
7. [Spatie / laravel-permission integration](#7-spatie--laravel-permission-integration)
8. [Per-environment role mapping](#8-per-environment-role-mapping)
9. [Host-app hooks](#9-host-app-hooks)
10. [REST surface and routes](#10-rest-surface-and-routes)
11. [Generator command](#11-generator-command)
12. [Migration from a custom OAuth controller](#12-migration-from-a-custom-oauth-controller)
13. [Multi-provider login UI](#13-multi-provider-login-ui)
14. [Testing your SSO integration](#14-testing-your-sso-integration)
15. [Security considerations](#15-security-considerations)
16. [Troubleshooting](#16-troubleshooting)
17. [Test coverage](#17-test-coverage)

---

## 1. What is the Martis SSO subsystem?

A configurable, layered SSO stack you wire up via config + 5 optional hooks. Replaces the 200-line custom `AzureOauthController extends Nova\LoginController` pattern that most admin apps end up with.

### What it differentiates

| Feature | Martis | Typical Laravel admin |
|---|---|---|
| Multiple SSO providers active in parallel | ✅ | ⚠ usually one |
| Role mapping in 3 strategies (column / config / callable) | ✅ | ❌ DIY |
| Spatie/laravel-permission auto-detected | ✅ | ⚠ DIY |
| Per-environment role mapping via env | ✅ | ⚠ hard-coded |
| Generator command scaffolds the full provider | ✅ `martis:sso <name>` | ❌ |
| Hook surface for app-side overrides | ✅ 5 hooks | ⚠ DIY |
| Login button auto-renders from config | ✅ | ❌ DIY UI |
| `auto_create_user` opt-in | ✅ | ⚠ DIY |
| `on_no_role_match` strategy (`deny` / `guest` / callable) | ✅ | ⚠ DIY |
| Soft-deleted user restore via hook | ✅ | DIY |

### What it does NOT do

- **It is not an IdP.** Martis consumes identity from Azure / Google / GitHub / Okta — it doesn't issue tokens.
- **It does not store passwords.** When `auto_create_user = true`, new users get an unguessable random hash so the `users.password` column constraint is satisfied. Authentication always flows through the IdP.
- **It does not bypass the MFA configured at the IdP.** Whatever Azure / Google / GitHub require still applies.

---

## 2. Architecture

```
HTTP entry points (registered when `auth.sso.enabled = true`):
  GET /{martis}/sso/{provider}/redirect   → SsoController::redirect
  GET /{martis}/sso/{provider}/callback   → SsoController::callback

         │
         ▼
┌─────────────────────────────────────────────────────────────────┐
│ SsoManager (singleton, exposes the MartisSso facade)            │
│   • providers registry  (azure → AzureProvider, ...)             │
│   • adapter resolver    (auto / spatie / native / callable)      │
│   • host-app hook surface (5 closures)                           │
└────────┬────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────┐
│ SsoProviderContract                                              │
│   redirect(Request) : RedirectResponse                           │
│   resolveIdentity(Request) : SsoIdentity                         │
│                                                                  │
│   Built-in: AzureProvider                                        │
│   Built-in scaffolding: GoogleProvider, GitHubProvider           │
│   Custom: register via `MartisSso::extend('okta', OktaProvider)` │
└────────┬────────────────────────────────────────────────────────┘
         │ returns SsoIdentity { provider, externalId, email, name,
         │                       externalRoles[], raw[], accessToken? }
         ▼
┌─────────────────────────────────────────────────────────────────┐
│ IdentityResolver — find-or-create local user                    │
│   • match by `email` (default)  |  by `external_id`             │
│   • auto-create when missing (config gate)                      │
│   • sync `name` / `email` from `sync_user_attributes`           │
│   • host-app override: `MartisSso::resolveUserUsing(...)`       │
└────────┬────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────┐
│ RoleMapper — translate external groups → local role models       │
│   3 strategies:                                                  │
│     1. column   — `Role::whereIn($column, $externalNames)->get()`│
│     2. config   — `role_map` array of `slug => env_value`        │
│     3. callable — provider-config closure                        │
│   Global override: `MartisSso::resolveRolesUsing(...)`           │
└────────┬────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────┐
│ PermissionAdapter — sync resolved roles onto the user            │
│   SpatieAdapter   — calls $user->syncRoles($collection)          │
│   NativeAdapter   — direct attach/detach against model_has_roles │
│   CallableAdapter — host-app closure                             │
│   Resolution: `auto` picks Spatie when available, Native otherwise│
└────────┬────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────┐
│ MartisSso::afterLogin($user, $identity, $provider)               │
│ Auth::guard()->login($user, remember=true)                       │
│ redirect()->intended(provider.redirect_to ?? '/martis')          │
└─────────────────────────────────────────────────────────────────┘
```

Every layer is overridable via a host-app hook — the layered design is opt-in. An app that just wants "the basics" hooks nothing; an app with quirky requirements hooks one or two specific layers.

---

## 3. Quick start (Azure, Spatie)

```bash
php artisan martis:sso azure --with-spatie --with-migration
```

That's it. The command runs end-to-end:

1. ✅ `composer require laravel/socialite socialiteproviders/microsoft spatie/laravel-permission` — only for packages not already installed.
2. ✅ Inserts `auth.sso.providers.azure` block in `config/martis.php`.
3. ✅ Stubs `AZURE_*` and `MARTIS_SSO_*` env vars in `.env` and `.env.example`.
4. ✅ Auto-registers the Microsoft Socialite listener in `AppServiceProvider::boot()`.
5. ✅ Publishes Spatie's permission config + migrations (`vendor:publish` for the package).
6. ✅ Publishes the `azure_group_name` migration on the `roles` table.
7. ✅ Runs `php artisan migrate`.
8. ✅ Interactive prompt (one row at a time) to populate `azure_group_name` on each existing Spatie role.

Then complete the 2 manual steps the command prints (intrinsically Azure-portal-only):

1. Register the Azure AD app in https://portal.azure.com (Redirect URI = `/martis/sso/azure/callback`, permissions, App Roles, user assignments).
2. Fill `AZURE_CLIENT_ID` / `AZURE_CLIENT_SECRET` / `AZURE_REDIRECT_URI` / `AZURE_RESOURCE_ID` in `.env`.

Reload `/martis/login` — the **Continue with Microsoft** button is there.

### Skipping individual steps

If your CI / production deploy already manages composer / migrations / role mapping independently, opt out:

```bash
php artisan martis:sso azure --with-spatie --with-migration \
    --no-composer        \  # skip composer require
    --no-publish-spatie  \  # skip Spatie vendor:publish
    --no-listener        \  # skip auto-registering the Socialite listener
    --no-migrate         \  # skip php artisan migrate
    --no-map                # skip the interactive role-mapping prompt
```

---

## 4. Azure AD — full step-by-step

The canonical recipe. Copy/paste straight through.

### Step 1 — Run the Martis generator

```bash
php artisan martis:sso azure --with-spatie --with-migration
```

The command is idempotent and self-sufficient. It:

1. **Composer** — runs `composer require` for any missing packages: `laravel/socialite`, `socialiteproviders/microsoft`, and `spatie/laravel-permission` (with `--with-spatie`). Skips packages already declared in `composer.json`.
2. **Config** — inserts the `auth.sso.providers.azure` block in `config/martis.php`.
3. **Env** — stubs the `AZURE_*` and `MARTIS_SSO_*` env vars in `.env` and `.env.example`.
4. **Listener** — adds the `MicrosoftExtendSocialite` event listener at the top of `AppServiceProvider::boot()` (idempotent — checks if already there).
5. **Migration** — publishes `add_azure_group_name_to_roles_table` (with `--with-migration`).
6. **Migrate** — runs `php artisan migrate` (interactive prompt; non-interactive auto-runs unless `--no-migrate`).

Skip flags for CI / production deploys:

| Flag | What it skips |
|---|---|
| `--no-composer` | Composer require step (deps must already be installed) |
| `--no-publish-spatie` | Spatie's `vendor:publish` (config + migrations) |
| `--no-listener` | Auto-registering the Socialite listener in `AppServiceProvider::boot()` |
| `--no-migrate` | The `php artisan migrate` step |
| `--no-map` | The interactive role-mapping prompt |

### Step 2 — Register the app in the Azure portal

1. Go to https://portal.azure.com → **Azure Active Directory** → **App registrations** → **New registration**.

2. **Name**: a friendly name (e.g. "Acme Admin Panel").

3. **Supported account types**: pick what fits your tenant policy. For most B2B / single-tenant setups, choose **Accounts in this organizational directory only**.

4. **Redirect URI** (Web): set it to the Martis callback URL:
   ```
   https://your-app.example/martis/sso/azure/callback
   ```
   (Replace `your-app.example` with your actual host, and `martis` with whatever you set as `MARTIS_PATH`.)

5. After creation, copy the **Application (client) ID**. Paste it into `.env`:
   ```
   AZURE_CLIENT_ID=00000000-0000-0000-0000-000000000000
   AZURE_RESOURCE_ID=00000000-0000-0000-0000-000000000000
   ```
   `AZURE_CLIENT_ID` and `AZURE_RESOURCE_ID` are normally the same value (the Application ID).

6. **Certificates & secrets** → **New client secret**:
   - Description: any label.
   - Expires: pick the longest your security policy allows.
   - Click **Add**, then **immediately copy the Value** (not the Secret ID — the value is shown only once).
   - Paste it into `.env`:
     ```
     AZURE_CLIENT_SECRET=...
     ```

7. **API permissions** → **Add a permission** → **Microsoft Graph** → **Delegated permissions**:
   - `openid`
   - `profile`
   - `email`
   - `GroupMember.Read.All`
   - `User.ReadBasic.All`

   Then **Grant admin consent** for your tenant.

8. (Recommended for `app_role_assignments` strategy) **App roles** → **Create app role**:
   - Display name: a human-readable label that you'll later store in `roles.azure_group_name` (e.g. `Admin`, `Sales Rep`, `Backoffice`).
   - Allowed member types: **Users/Groups**.
   - Value: any internal value (Martis doesn't read this).
   - Description: anything.

   Repeat for every role. Names are case-sensitive when matching against `roles.azure_group_name`.

9. **Enterprise applications** → find your app → **Users and groups** → **Add user/group** → assign each user the right App Role. Users without an App Role assignment will be denied login (`on_no_role_match = 'deny'`).

### Step 3 — Run the published migration (only if you used `--no-migrate`)

```bash
php artisan migrate
```

This adds the nullable `azure_group_name` column to the existing `roles` table. (Spatie's migration must already have run — if `roles` doesn't exist, the Martis stub no-ops with a notice.)

### Step 4 — Populate `azure_group_name` on each Spatie role

For every Spatie role that should map to an Azure App Role:

```php
use Spatie\Permission\Models\Role;

Role::where('name', 'admin')->update(['azure_group_name' => 'Admin']);
Role::where('name', 'sales_rep')->update(['azure_group_name' => 'Sales Rep']);
Role::where('name', 'backoffice')->update(['azure_group_name' => 'Backoffice']);
```

The `azure_group_name` value must match the App Role display name in Azure **exactly** (case-sensitive). If you're using **Groups** instead (`role_source = 'groups'`), match against the group's `displayName`.

### Step 5 — Confirm `.env`

```env
MARTIS_SSO_ENABLED=true
MARTIS_SSO_AZURE_ENABLED=true

AZURE_CLIENT_ID=00000000-0000-0000-0000-000000000000
AZURE_CLIENT_SECRET=verysecretvalue
AZURE_REDIRECT_URI=https://your-app.example/martis/sso/azure/callback
AZURE_RESOURCE_ID=00000000-0000-0000-0000-000000000000
```

### Step 6 — Reload `/martis/login`

The **Continue with Microsoft** button is there. Click → Azure consent screen → callback → Martis logs the user in and assigns the matching Spatie roles.

### What happens on each click (full request flow)

```
1. User clicks "Continue with Microsoft"
2. Browser → /martis/sso/azure/redirect
3. SsoController::redirect → AzureProvider::redirect
4. Socialite::driver('microsoft')->scopes([...])->redirect()
5. Browser → https://login.microsoftonline.com/.../oauth2/v2.0/authorize?...
6. User authenticates against Azure tenant + grants consent (first time only)
7. Azure → /martis/sso/azure/callback?code=...&state=...
8. SsoController::callback → AzureProvider::resolveIdentity
9. Socialite exchanges code for access token
10. AzureProvider hits Microsoft Graph:
    GET /v1.0/users/{id}/appRoleAssignments?$filter=resourceId eq {AZURE_RESOURCE_ID}
    → returns each assignment.principalDisplayName
11. RoleMapper::map($externalRoles, ...)
    → Role::whereIn('azure_group_name', $externalRoles)->get()
12. IdentityResolver::resolve($identity, 'azure')
    → User::where('email', $identity->email)->first() ?? create()
    → sync 'name' and 'email' from identity
13. SpatieAdapter::syncRoles($user, $roles)
    → $user->syncRoles($collection)   ← Spatie's HasRoles method
14. SsoManager::fireAfterLogin($user, $identity, 'azure')
    → app's afterLogin hook runs (audit log, type derivation, etc.)
15. Auth::guard()->login($user, remember: true)
16. redirect()->intended('/martis')
```

---

## 5. Configuration reference

```php
// config/martis.php
'auth' => [
    'sso' => [
        'enabled' => env('MARTIS_SSO_ENABLED', false),

        'providers' => [
            'azure' => [
                // Master gate per provider.
                'enabled' => env('MARTIS_SSO_AZURE_ENABLED', false),

                // Socialite driver name. The Microsoft Socialite extension
                // registers as 'microsoft' by default — change if you renamed.
                'driver' => 'azure',

                // Login button label and Phosphor icon.
                'label' => 'Continue with Microsoft',
                'icon'  => 'microsoft-outlook-logo',

                // OAuth scopes requested at consent.
                'scopes' => [
                    'openid', 'profile', 'email',
                    'GroupMember.Read.All',
                    'User.ReadBasic.All',
                ],

                // Where the external role list comes from.
                // 'app_role_assignments' → /users/{id}/appRoleAssignments
                // 'groups'               → /users/{id}/memberOf
                // 'callable'             → role_source_callable closure
                'role_source' => 'app_role_assignments',
                'resource_id' => env('AZURE_RESOURCE_ID'),

                // How to map external names to local roles.
                // 'column'   → Role::whereIn(role_column, $externalRoles)
                // 'config'   → role_map array (slug => env_value)
                // 'callable' → role_callable closure
                'role_strategy' => 'column',
                'role_column'   => 'azure_group_name',
                'role_model'    => null, // null = auto-detect Spatie/App\Models\Role

                // Identity resolution.
                'auto_create_user'         => true,
                'identity_match_attribute' => 'email', // 'email' | 'external_id'
                'sync_user_attributes'     => ['name', 'email'],

                // Permission sync.
                'sync_roles'         => true,
                'permission_adapter' => 'auto', // 'auto' | 'spatie' | 'native' | 'callable'

                // What happens when no roles match.
                'on_no_role_match' => 'deny', // 'deny' | 'guest' | callable

                // Optional post-login redirect override.
                // Null = config('martis.path').
                'redirect_to' => null,
            ],
        ],
    ],
],
```

---

## 6. The three orthogonal axes

The three knobs (`role_source`, `role_strategy`, `permission_adapter`) compose freely. **3 × 3 × 4 = 36 combinations** cover every realistic case without writing PHP. Anything outside that — use a `callable` in the relevant axis.

### `role_source` — where external role names come from

| Value | Behaviour |
|---|---|
| `groups` | Calls `GET /v1.0/users/{id}/memberOf?$select=displayName` on Microsoft Graph. Returns each group's `displayName`. Coarser-grained but doesn't require defining App Roles in the Azure portal. |
| `app_role_assignments` | Calls `GET /v1.0/users/{id}/appRoleAssignments?$filter=resourceId eq {resource_id}`. Returns each assignment's `principalDisplayName`. Recommended — gives you per-app role granularity. |
| `callable` | Defers to the closure declared at `role_source_callable` in the provider config. Use for non-Microsoft IdPs or for custom Graph queries. |

### `role_strategy` — how to map external names to local roles

| Value | Behaviour |
|---|---|
| `column` (default) | `Role::query()->whereIn($role_column, $externalRoles)->get()`. The host app stores the IdP group/app-role name on the roles table. Most flexible — each role row decides whether and how it's mapped, without code changes. |
| `config` | `role_map` array in config maps `local_slug => env_value`. The mapper finds local roles whose `name` equals the slug for each `env_value` present in the external list. Useful when you can't add columns to `roles`. |
| `callable` | Defers entirely to the provider-config closure `role_callable(array $externalRoles, ?User $user, string $provider)`. Returns a Collection of role models. Use for arbitrarily complex business rules. |

### `permission_adapter` — how resolved roles get written onto the user

| Value | Behaviour |
|---|---|
| `auto` (default) | Picks `SpatieAdapter` when `spatie/laravel-permission` is installed, `NativeAdapter` otherwise. |
| `spatie` | Calls `$user->syncRoles($collection)` (Spatie's `HasRoles` trait). The user model must `use HasRoles`. |
| `native` | Direct attach/detach against `model_has_roles`. Pivot table name and column names are config-driven (`auth.sso.native_pivot.*`). |
| `callable` | Defers to `MartisSso::syncRolesUsing($user, $roles)` registered in your service provider. Use when you have bespoke rules (custom main_role flag, dual tables, audit triggers). |

---

## 7. Spatie / laravel-permission integration

Spatie integration is automatic. The default `permission_adapter = 'auto'` runs:

```php
return class_exists(\Spatie\Permission\Models\Role::class)
    ? new SpatieAdapter
    : new NativeAdapter;
```

The `SpatieAdapter` calls `$user->syncRoles($collection)`. The user model **must** use Spatie's trait:

```php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;
    // ...
}
```

### Multi-tenant Spatie schemas

When using Spatie's `teams = true` mode (Spatie Permission v6 with team scoping), set the team context **before** the SSO callback runs. The simplest way: a middleware that calls `setPermissionsTeamId` based on the subdomain or a session value, applied to the `/sso/{provider}/callback` route via the host app's route file.

### Mixing Spatie and a custom adapter

When the app has Spatie roles BUT also a custom `model_has_roles_extra` table (like the CEO's case with `main_role`), use the `callable` adapter:

```php
'permission_adapter' => 'callable',
```

```php
MartisSso::syncRolesUsing(function ($user, $resolvedRoles) {
    // 1. Spatie sync
    $user->syncRoles($resolvedRoles);

    // 2. Set the main_role flag (custom logic)
    if (! $user->mainRole()) {
        $user->updateMainRole($resolvedRoles->first(), $user);
    }
});
```

---

## 8. Per-environment role mapping

Roles change between environments — QA's Azure tenant uses different group identifiers than production. Three patterns to handle this cleanly.

### Pattern A — column strategy + per-env DB (preferred)

The `roles.azure_group_name` column carries the env-specific Azure name. Use `seeders` per environment:

```php
// database/seeders/QaRoleSeeder.php (or via tinker / migrations)
Role::where('name', 'admin')->update(['azure_group_name' => 'PMI ES QA ADMIN']);
Role::where('name', 'sales_rep')->update(['azure_group_name' => 'PMI ES QA SALES REP']);
```

```php
// database/seeders/ProdRoleSeeder.php
Role::where('name', 'admin')->update(['azure_group_name' => 'PMI ES PROD ADMIN']);
Role::where('name', 'sales_rep')->update(['azure_group_name' => 'PMI ES PROD SALES REP']);
```

Each environment seeds its own values. Code stays identical.

### Pattern B — config strategy + per-env env vars

When you can't add a column to `roles` (legacy schema, third-party migrations), use the config strategy:

```php
'azure' => [
    'role_strategy' => 'config',
    'role_map' => [
        'admin' => env('AZURE_GROUP_ROLE_ADMIN'),
        'sales_rep' => env('AZURE_GROUP_ROLE_SALES_REP'),
        'backoffice' => env('AZURE_GROUP_ROLE_BACKOFFICE'),
        'frontliner' => env('AZURE_GROUP_ROLE_FRONTLINER'),
    ],
],
```

`.env.qa`:
```
AZURE_GROUP_ROLE_ADMIN=PMI ES QA ADMIN
AZURE_GROUP_ROLE_SALES_REP=PMI ES QA SALES REP
AZURE_GROUP_ROLE_BACKOFFICE=PMI ES QA BACKOFFICE
AZURE_GROUP_ROLE_FRONTLINER=PMI ES QA FRONTLINER
```

`.env.production`:
```
AZURE_GROUP_ROLE_ADMIN=PMI ES PROD ADMIN
AZURE_GROUP_ROLE_SALES_REP=PMI ES PROD SALES REP
AZURE_GROUP_ROLE_BACKOFFICE=PMI ES PROD BACKOFFICE
AZURE_GROUP_ROLE_FRONTLINER=PMI ES PROD FRONTLINER
```

When a user logs in under QA, their Azure groups (`PMI ES QA ADMIN` etc.) match the env values, the mapper resolves the local slugs (`admin` etc.), and the lookup `Role::whereIn('name', $slugs)->get()` returns the local roles. Same code in both environments.

### Pattern C — both layered

You can combine: per-env DB seeding for "real" mappings AND a config fallback for special-case overrides via env. The mapper runs `column` first, falls back to `config` only when the strategy says so — they're mutually exclusive within a single provider but you can declare two providers.

---

## 9. Host-app hooks

Five hook points exposed via the `MartisSso` facade. Wire them in `app/Providers/MartisServiceProvider.php`:

```php
use Martis\Sso\Facades\MartisSso;
use Martis\Sso\SsoIdentity;
```

### Hook 1 — `resolveUserUsing`

Replace the entire user-resolution flow. Receives the resolved external identity and the provider name. Return a User instance, or null to deny login.

```php
MartisSso::resolveUserUsing(function (SsoIdentity $identity, string $provider): \App\Models\User {
    return \App\Models\User::query()
        ->withoutGlobalScopes()
        ->updateOrCreate(
            ['email' => $identity->email],
            [
                'name' => $identity->name,
                'auth_method' => 'azure',
                'password' => bcrypt(bin2hex(random_bytes(32))),
            ],
        )->tap(fn ($u) => $u->trashed() && $u->restore());
});
```

### Hook 2 — `resolveRolesUsing`

Replace the entire role-resolution flow. Receives the external role list, the user (may be null when called before user creation), and the provider name. Return an Eloquent Collection of role models.

```php
MartisSso::resolveRolesUsing(function (array $externalRoles, $user, string $provider) {
    return \App\Models\Role::query()
        ->whereIn('azure_group_name', $externalRoles)
        ->where('active', true)        // custom predicate
        ->get();
});
```

### Hook 3 — `syncRolesUsing`

Replace the role-sync step. Auto-activates the `CallableAdapter` — no need to also flip `permission_adapter`.

```php
MartisSso::syncRolesUsing(function ($user, $resolvedRoles) {
    $user->syncRoles($resolvedRoles);

    // Custom: set the main_role flag, dispatch a sync job, etc.
    if (! $user->mainRole()) {
        $user->updateMainRole($resolvedRoles->first(), $user);
    }

    \BroadcastUserSyncedJob::dispatch($user);
});
```

### Hook 4 — `afterLogin`

Side-effect hook fired after login + role sync. Audit logs, welcome emails, derived columns.

```php
MartisSso::afterLogin(function ($user, SsoIdentity $identity, string $provider) {
    \App\Models\AuditEvent::record('sso.login', $user, ['provider' => $provider]);

    $user->update([
        'type' => $user->hasRole('frontliner') ? 'frontliner' : 'backoffice',
        'last_sso_login_at' => now(),
    ]);
});
```

### Hook 5 — `onNoRoleMatchUsing`

Custom denial / guest flow when the role mapper returns an empty collection. Closure can return a redirect / response, or null to use the default deny page.

```php
MartisSso::onNoRoleMatchUsing(function (SsoIdentity $identity, string $provider) {
    \App\Notifications\AccessRequestedNotification::route('mail', 'admin@acme.com')
        ->notify(new \App\Notifications\SsoAccessRequested($identity));

    return redirect('/login')->withErrors([
        'sso' => __('Your account is not provisioned for :app yet. Admins have been notified.', [
            'app' => config('app.name'),
        ]),
    ]);
});
```

### Composing hooks

Hooks compose with each other AND with config — you can override `resolveUserUsing` while keeping the built-in `RoleMapper` (`role_strategy = 'column'`). Each layer is independent.

---

## 10. REST surface and routes

Both routes register only when `auth.sso.enabled = true`. They're unauthenticated (the user isn't logged in yet) and rate-limited via the `login_attempts` / `login_minutes` config.

| Method | Path | Description |
|---|---|---|
| `GET` | `/{martis}/sso/{provider}/redirect` | Kicks off the OAuth flow. Returns 302 to the IdP authorize URL. |
| `GET` | `/{martis}/sso/{provider}/callback` | Handles the IdP callback. Resolves identity → roles → user → login. Redirects to `provider.redirect_to` (or `/martis` by default) on success, `/martis/login` with errors on failure. |

The `provider` segment is **whatever the user registered** — `azure`, `google`, `github`, `okta`, custom names. Routes only resolve if the provider is registered AND enabled.

---

## 11. Generator command

```bash
php artisan martis:sso <provider>
    # Behaviour flags
    [--with-spatie]
    [--with-migration]
    [--strategy=column|config|callable]
    [--no-auto-create-user]
    [--custom]
    # Skip flags (opt-out of automated steps)
    [--no-composer]
    [--no-publish-spatie]
    [--no-listener]
    [--no-migrate]
    [--no-map]
```

### Behaviour flags

| Flag | Meaning |
|---|---|
| `--with-spatie` | Installs `spatie/laravel-permission` (when missing), runs Spatie's `vendor:publish`, sets `permission_adapter = 'spatie'` in the generated config. |
| `--with-migration` | Publishes a migration adding `{provider}_group_name` to `roles`. No-op if `roles` doesn't exist. |
| `--strategy` | Sets the default `role_strategy` (default `column`). |
| `--no-auto-create-user` | Sets `auto_create_user = false` in the generated config. |
| `--custom` | Skips the validation that the provider is one of `azure / google / github`. Use when registering a custom provider via `MartisSso::extend()`. |

### Skip flags

| Flag | Skips |
|---|---|
| `--no-composer` | The `composer require` step (deps must already be installed). |
| `--no-publish-spatie` | Spatie's `vendor:publish` (config + migrations). |
| `--no-listener` | Auto-registering the Socialite extension listener in `AppServiceProvider::boot()`. Azure-only — has no effect on other providers. |
| `--no-migrate` | The `php artisan migrate` step. |
| `--no-map` | The interactive prompt that maps existing Spatie roles to provider display names. |

Built-in scaffolding shapes for `azure`, `google`, `github` differ in:
- Default scopes
- Default `role_source` (Azure → `app_role_assignments`, others → `callable`)
- Button label and icon

### Idempotency

The generator is fully idempotent. Re-running with the same provider name on a fully-configured project is a no-op:

| Step | What "already done" looks like |
|---|---|
| Composer require | All packages already declared in `composer.json` |
| Config block | `'azure' => [` already present in `config/martis.php` |
| Env vars | Each `MARTIS_SSO_*` / `AZURE_*` line already in `.env` |
| Listener | `MicrosoftExtendSocialite` string already in `AppServiceProvider.php` |
| Spatie publish | `config/permission.php` AND `*_create_permission_tables.php` already exist |
| Martis migration | A matching `*_add_{provider}_group_name_to_roles_table.php` already published |
| Migrate | Standard Laravel — no pending migrations is a no-op |
| Role mapping | Empty input keeps current value; same input is no-op |

---

## 12. Migration from a custom OAuth controller

Apps that already have a custom `AzureOauthController` (often extending Nova's `LoginController`) typically have ~200 lines covering:

1. Socialite driver + scope setup.
2. Microsoft Graph `appRoleAssignments` fetch.
3. Mapping Graph `principalDisplayName` to local roles.
4. Find-or-create user with custom `auth_method`, `type`, soft-delete restore.
5. Detach old roles, attach new ones.
6. Custom `main_role` flag on a separate pivot.
7. Type derivation (e.g. `FRONTLINER` vs `BACKOFFICE` based on role).
8. Logging / audit.

The Martis subsystem replaces all of it with config + 2 hooks:

```php
// app/Providers/MartisServiceProvider.php
use Martis\Sso\Facades\MartisSso;
use Martis\Sso\SsoIdentity;
use App\Enums\AuthMethodEnum;
use App\Enums\UserTypeEnum;
use App\Models\Role;
use App\Models\User;

protected function registerSso(): void
{
    MartisSso::resolveUserUsing(function (SsoIdentity $identity, string $provider): User {
        return User::query()
            ->withoutGlobalScopes()
            ->updateOrCreate(
                ['email' => $identity->email],
                [
                    'name' => $identity->name,
                    'auth_method' => AuthMethodEnum::AZURE->value,
                    'password' => bcrypt(bin2hex(random_bytes(32))),
                ],
            )->tap(fn ($u) => $u->trashed() && $u->restore());
    });

    MartisSso::afterLogin(function (User $user, SsoIdentity $identity, string $provider) {
        $user->update([
            'type' => $user->hasRole(Role::FRONTLINER)
                ? UserTypeEnum::FRONTLINER->value
                : UserTypeEnum::BACKOFFICE->value,
        ]);

        if (! $user->mainRole()) {
            $user->updateMainRole($user->roles->first(), $user);
        }
    });
}
```

Plus the config block (~30 lines) and the `azure_group_name` migration (`--with-migration` flag). Total: ~30 lines vs 200.

The custom `AzureOauthController` and its routes can be deleted entirely.

---

## 13. Multi-provider login UI

The Login page reads `auth.sso.providers` and renders one button per enabled provider. Label and icon come from each provider's config:

```php
'providers' => [
    'azure' => [
        'enabled' => true,
        'label' => 'Continue with Microsoft',
        'icon' => 'microsoft-outlook-logo',
    ],
    'google' => [
        'enabled' => true,
        'label' => 'Continue with Google',
        'icon' => 'google-logo',
    ],
    'github' => [
        'enabled' => true,
        'label' => 'Sign in with GitHub',
        'icon' => 'github-logo',
    ],
],
```

Result: three buttons stacked above the email/password form. Click → `/sso/{name}/redirect`.

When `auth.sso.enabled = false`, no buttons render and no routes register.

---

## 14. Testing your SSO integration

### Local development with Azure

For local dev, register a separate Azure app (or use the same one with multiple Redirect URIs):

1. Azure portal → your app → **Authentication** → **Add a platform** → **Web**.
2. Add `http://localhost:8000/martis/sso/azure/callback` (or whatever your local URL is).

### Mocking the IdP for unit tests

For automated tests, bind a stub provider in the container:

```php
$identity = new \Martis\Sso\SsoIdentity(
    provider: 'azure',
    externalId: 'azure-test-1',
    email: 'test@example.com',
    name: 'Test User',
    externalRoles: ['Admin'],
);

$this->app->instance(\Martis\Sso\Providers\AzureProvider::class, new class($identity) extends \Martis\Sso\Providers\AzureProvider {
    public function __construct(private SsoIdentity $stub) {}
    public function resolveIdentity(\Illuminate\Http\Request $request): SsoIdentity { return $this->stub; }
    public function redirect(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse { return redirect('/'); }
});

// Now hit the callback — it'll use your stub identity.
$response = $this->get('/martis/sso/azure/callback');
$response->assertRedirect('/martis');
```

Look at `tests/Feature/SsoControllerTest.php` for 8 working examples.

---

## 15. Security considerations

### CSRF / state parameter

Socialite manages the OAuth `state` parameter automatically. Don't disable it.

### Token storage

Martis does **not** persist the OAuth access token. If your app needs it (e.g. to make Graph API calls on behalf of the user later), capture it in the `afterLogin` hook:

```php
MartisSso::afterLogin(function ($user, SsoIdentity $identity, string $provider) {
    if ($identity->accessToken !== null) {
        $user->update(['azure_access_token' => encrypt($identity->accessToken)]);
    }
});
```

Always encrypt at rest.

### Replay protection

The OAuth `code` is single-use — Azure invalidates it after the first exchange. The Socialite library handles this automatically.

### Soft-deleted users

Auto-create + soft-delete = a user who left the company comes back. To deny re-login of soft-deleted users:

```php
MartisSso::resolveUserUsing(function (SsoIdentity $identity) {
    $user = User::query()->where('email', $identity->email)->first();
    if ($user?->trashed()) {
        return null; // denies login
    }
    return $user ?? User::create([...]);
});
```

To allow re-login (default Nova-parity behaviour):

```php
->tap(fn ($u) => $u->trashed() && $u->restore());
```

### Email forwarding / impersonation

The IdP guarantees the email belongs to the authenticated user. Martis trusts that. Don't add extra "verify the email" hops post-SSO.

### Role escalation through `groups` strategy

When using `role_source = 'groups'`, ANY group the user belongs to is considered. Make sure non-admin distribution lists, MS Teams groups, etc., are NOT named in your `roles.azure_group_name` column. Prefer `app_role_assignments` for tighter control.

---

## 16. Troubleshooting

### Button doesn't appear on Login

| Likely cause | Fix |
|---|---|
| `auth.sso.enabled = false` | Set `MARTIS_SSO_ENABLED=true` |
| Provider's `enabled = false` | Set `MARTIS_SSO_AZURE_ENABLED=true` |
| Stale `config:cache` | `php artisan config:clear` |

### Callback fails with "Class 'Laravel\Socialite\Facades\Socialite' not found"

Run `composer require laravel/socialite`.

### Callback fails with Microsoft driver not registered

The `socialiteproviders/microsoft` package wasn't wired up. Add the listener in `AppServiceProvider::boot()`:

```php
Event::listen(SocialiteWasCalled::class, [
    \SocialiteProviders\Microsoft\MicrosoftExtendSocialite::class.'@handle',
]);
```

### Callback returns 302 to `/martis/login` with `sso_no_role_match`

The user has no Azure App Role assignment that matches a local role's `azure_group_name`. Verify:

1. The user has been assigned an App Role in **Azure portal → Enterprise applications → your app → Users and groups**.
2. The role display name in Azure exactly matches `roles.azure_group_name` (case-sensitive).
3. The `AZURE_RESOURCE_ID` env var matches the application id in Azure (most apps: same value as `AZURE_CLIENT_ID`).
4. The user actually has the assignment — query `/me/appRoleAssignments` directly in Graph Explorer to confirm.

### User created but no Spatie roles assigned

| Likely cause | Fix |
|---|---|
| `azure_group_name` column missing on `roles` | Run `php artisan migrate` |
| Column populated but values don't match | Verify exact case-sensitive match |
| User model missing `HasRoles` trait | `use Spatie\Permission\Traits\HasRoles;` |
| `permission_adapter = 'native'` set explicitly | Switch to `'spatie'` or `'auto'` |

### Logs show "Failed to fetch appRoleAssignments"

| Likely cause | Fix |
|---|---|
| Missing `AppRoleAssignment.ReadWrite.All` permission | Most setups need only `User.ReadBasic.All` + `GroupMember.Read.All`; double-check by hitting `/users/me/appRoleAssignments` in Graph Explorer with the same delegated permissions |
| `AZURE_RESOURCE_ID` mismatch | Set it to the Application ID, not the Object ID or Tenant ID |
| Token expired / wrong scopes | Ensure `openid profile email` are requested |

### "Cannot resolve user" / 500 on callback

| Likely cause | Fix |
|---|---|
| `auth.providers.users.model` config wrong | Check `config/auth.php` — should point to `App\Models\User::class` |
| User table missing `email` column | Highly unusual — check `users` schema |
| `auto_create_user = false` and user doesn't exist | Set to `true` or pre-create the user |

---

## 17. Test coverage

| File | Tests | What it covers |
|---|---|---|
| `tests/Unit/Sso/SsoIdentityTest.php` | 2 | Value object contract (full + minimal payloads). |
| `tests/Unit/Sso/RoleMapperTest.php` | 6 | All three strategies (column, config, callable) + global override + empty input + no-match. |
| `tests/Feature/SsoControllerTest.php` | 8 | Callback auto-creates user, syncs attributes, maps roles, denies on no match, allows guest mode, hook firing, disabled-provider redirect, route gating off when master switch is false. |
| **Total** | **16** | |

Combined Martis suite is 1455 / 0 with these tests included.
