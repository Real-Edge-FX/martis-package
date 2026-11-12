# SSO (Single Sign-On) — ⭐ Martis differential

> Pluggable single sign-on for Laravel admin panels. Azure AD, Google Workspace, GitHub, Okta — or any custom IdP — with role mapping, Spatie/laravel-permission integration, environment-aware config, and a generator command (`php artisan martis:sso <provider>`) that scaffolds everything.

Martis signs admin users in through an external identity provider. Azure AD ships built in; Google, GitHub, Okta or any other provider plugs in through `MartisSso::extend()`. Groups map to your roles (Spatie laravel-permission is detected) and `martis:sso` scaffolds the setup.

This document is the long-form reference. For a 30-second TL;DR see the **Quick start** below. For step-by-step Azure setup, jump to **[Azure AD — full step-by-step](#4-azure-ad--full-step-by-step)**.

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
18. [Signed token between two Martis apps (v2.21.0)](#18-signed-token-between-two-martis-apps-v2210)

---

## 1. What is the Martis SSO subsystem?

A configurable, layered SSO stack you wire up via config + 5 optional hooks. Replaces the 200-line custom `AzureOauthController extends LoginController` pattern that most admin apps end up writing by hand.

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
│   Generator stubs (config + env only, no Provider class):        │
│     `martis:sso google` and `martis:sso github`                  │
│   Custom: register via `MartisSso::extend('okta', OktaProvider)` │
└────────┬────────────────────────────────────────────────────────┘
         │ returns SsoIdentity { provider, externalId, email, name,
         │                       externalRoles[], raw[], accessToken? }
         ▼
┌─────────────────────────────────────────────────────────────────┐
│ IdentityResolver — find-or-create local user                    │
│   • match by `email` (default)  |  by `external_id`             │
│   • auto-create when missing (config gate)                      │
│   • sync `name` / `email` / `external_id`                       │
│     from `sync_user_attributes`                                 │
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
│ Auth::guard()->login($user, remember=provider.remember (false))  │
│ redirect()->intended(provider.redirect_to ?? '/martis')          │
└─────────────────────────────────────────────────────────────────┘
```

Every layer is overridable via a host-app hook — the layered design is opt-in. An app that just wants "the basics" hooks nothing; an app with quirky requirements hooks one or two specific layers.

The boxes above are the layers, not the run order inside the callback. Roles are decided **before** the local user is written (v2.21.0), so a refused identity never leaves a row behind: see [Deny before provisioning](#deny-before-provisioning-v2210). Every refusal ends the same way: one log line, one `SsoFailed` event and a redirect to the login page, see [Failed sign-ins](#failed-sign-ins-reason-log-and-event-v2210).

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
5. ✅ Publishes Spatie's permission config + migrations (`vendor:publish` for the package), in the same run when step 1 just installed Spatie. If Spatie still cannot be loaded, the command stops with an error asking you to re-run it.
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

1. **Composer** — runs `composer require` for any missing packages: `laravel/socialite`, `socialiteproviders/microsoft`, and `spatie/laravel-permission` (with `--with-spatie`). Skips packages already declared in `composer.json`. The packages it installs are loaded in the same run (their classes, and Spatie's service provider so its config + migrations can be published); if Spatie still cannot be loaded, the command exits with an error asking you to re-run it.
2. **Config** — inserts the `auth.sso.providers.azure` block in `config/martis.php`.
3. **Env** — stubs the `AZURE_*` and `MARTIS_SSO_*` env vars in `.env` and `.env.example`.
4. **Listener** — adds the `MicrosoftExtendSocialite` event listener at the top of `AppServiceProvider::boot()` (idempotent — checks if already there).
5. **Migration** — publishes `add_azure_group_name_to_roles_table` (with `--with-migration`). Its timestamp is later than every migration already in `database/migrations`, Spatie's `create_permission_tables` included, so the column is added after the `roles` table exists. When there is neither a `roles` table nor Spatie's migration (no `--with-spatie`, Spatie not set up yet), the command stops with an error instead: the migration would do nothing, be recorded as run, and the column would never be added. Pass `--with-spatie`, or publish Spatie's migration first and re-run.
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
15. Auth::guard()->login($user, remember: provider.remember)   ← default false (v2.4.0)
16. redirect()->intended('/martis')
```

---

## 5. Configuration reference

```php
// config/martis.php
'auth' => [
    'sso' => [
        'enabled' => env('MARTIS_SSO_ENABLED', false),

        // v2.22.0: the cache store of everything SSO keeps in a cache.
        // Null = the default store. See "The cache store" below.
        'cache_store' => env('MARTIS_SSO_CACHE_STORE'),

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
                // 'callable'             → role_source_callable
                'role_source' => 'app_role_assignments',
                'resource_id' => env('AZURE_RESOURCE_ID'),

                // The tenant the app registration is tied to (v2.4.0): a tenant
                // id or a verified domain. An identity whose `tid` claim names
                // another tenant is rejected, and so is one whose tenant cannot
                // be read. Empty/common/organizations/consumers checks nothing:
                // then match local accounts by 'external_id', not by email.
                // Unset, it reads services.azure.tenant of the Socialite driver.
                'tenant' => env('AZURE_TENANT_ID'),

                // How to map external names to local roles.
                // 'column'   → Role::whereIn(role_column, $externalRoles)
                // 'config'   → role_map array (slug => env_value)
                // 'callable' → role_callable
                'role_strategy' => 'column',
                'role_column'   => 'azure_group_name',
                'role_model'    => null, // null = auto-detect Spatie/App\Models\Role

                // Identity resolution.
                'auto_create_user'         => true,
                'identity_match_attribute' => 'email', // 'email' | 'external_id'
                'sync_user_attributes'     => ['name', 'email'], // also 'external_id' (v2.21.0)

                // Only with identity_match_attribute = 'external_id' (v2.21.0).
                'identity_external_id_column' => 'azure_object_id', // default '{provider}_external_id'
                'link_by_email'               => false,

                // true restores "write the user, then refuse" (v2.21.0).
                'provision_on_deny' => false,

                // Permission sync.
                'sync_roles'         => true,
                'permission_adapter' => 'auto', // 'auto' | 'spatie' | 'native' | 'callable'

                // What happens when no roles match.
                'on_no_role_match' => 'deny', // 'deny' | 'guest' | 'callable'

                // Remember-me after an SSO sign-in (v2.4.0). Off by default: the
                // session follows SESSION_LIFETIME and a fresh IdP round-trip
                // decides after it, so revoking access at the IdP ends panel
                // access. On, the remember cookie signs the user back in for
                // auth.guards.{guard}.remember minutes whatever the IdP says,
                // and only MartisSso::revokeFor() ends it: it cannot be set
                // together with the two limits below (v2.21.0, it throws).
                'remember' => false,

                // Absolute age and revalidation of an SSO-opened session
                // (v2.21.0). Both null (off) by default. Not with 'remember' => true.
                'max_session_minutes'      => env('MARTIS_SSO_AZURE_MAX_SESSION_MINUTES'),
                'revalidate_every_minutes' => env('MARTIS_SSO_AZURE_REVALIDATE_EVERY_MINUTES'),

                // Federated logout: a URL with {post_logout_redirect_uri}, or
                // (v2.21.0) the name of an invokable class.
                'logout_url' => env('MARTIS_SSO_AZURE_LOGOUT_URL'),

                // Optional post-login redirect override.
                // Null = config('martis.path').
                'redirect_to' => null,
            ],
        ],
    ],
],
```

### The cache store (v2.22.0)

`martis.auth.sso.cache_store` (env `MARTIS_SSO_CACHE_STORE`) names the Laravel cache store, from `cache.stores`, that holds everything the SSO subsystem keeps in a cache: the signed token's one-time `jti` ([section 18](#18-signed-token-between-two-martis-apps-v2210)), the revocation time and the origin nonces ([Ending an SSO session](#ending-an-sso-session-age-revocation-revalidation-v2210)), the ended-session markers, and the Azure tenant lookup. Unset (`null`, the default) it is the application's default store, as before.

The store is **security state**, so it must be shared by every server of the panel (Redis, database or Memcached; `file` is per host), must not evict early, and must not be flushed on a deploy: a store that forgets a `jti` reopens a used token, and one that forgets a revocation reopens an ended session. Naming a store of its own keeps a `cache:clear` or a short-lived default store from touching them.

How the value is read, and what is refused (v2.22.0):

- An **empty or blank** value is unset (what `env()` gives for `MARTIS_SSO_CACHE_STORE=`), so it is the default store.
- With **SSO on** (`martis.auth.sso.enabled`), a name that is not a string or is not in `cache.stores` throws an `InvalidArgumentException` naming `martis.auth.sso.cache_store`, at the first use. That first use is not only the SSO callback: sign-out and password sign-in also drop the SSO origin of the session, so they throw too, and so does the plain 401 of an unauthenticated request. A typo is loud on purpose, instead of being read as a cache outage.
- With **SSO off** the paths every panel runs (password sign-in, sign-out, the plain 401) never touch the SSO cache, so a mistake in this key cannot lock out a panel that does not use SSO.
- With SSO on and outside the test suite, a store that uses the `array` driver (per process) or the `null` driver (holds nothing) is refused with an exception naming `martis.auth.sso.cache_store` (and `cache.default` when the key is unset and the default store is the weak one): replay protection would otherwise be void with no error. In the test suite (`app()->runningUnitTests()`) it is accepted, so an app's own tests keep using the `array` store.
- **Changing the store on a running panel orphans what the old one held**: the revocation times of `revokeFor()` (a user revoked a moment ago can use an older session again), the origin nonces (remembered SSO sessions lose their SSO origin) and the one-time `jti` records (a token issued just before the change can be used once more, within its short life). Change it at a quiet moment, and run `MartisSso::revokeFor()` again for anyone revoked recently.

### What reaches the browser (v2.21.0)

The login page is public, so `window.MartisConfig.auth.sso` carries only what the button strip needs: `enabled`, and for each **enabled** provider `enabled`, `label` and `icon`. A disabled provider is not published, and nothing else of a provider block is (role maps, `tenant`, `resource_id`, `scopes`, `logout_url`, columns). An app whose custom login component (see [Overrides](overrides.md)) needs more lists the keys in an optional `public` array of the block:

```php
'azure' => [
    'enabled' => env('MARTIS_SSO_AZURE_ENABLED', false),
    'label'   => 'Continue with Microsoft',
    'icon'    => 'microsoft-outlook-logo',
    'tenant_label' => 'Acme Corp',
    // Published next to enabled, label and icon. Listing a key makes it public:
    // never list a secret, a tenant id or a role map.
    'public'  => ['tenant_label'],
],
```

`public` can add keys, never change `enabled`, `label` or `icon`, and is not published itself. A `public` that is not an array of key names throws an `InvalidArgumentException` naming `martis.auth.sso.providers.{provider}.public`. See [Authentication → What reaches the browser](authentication.md#what-reaches-the-browser-v2210) for the whole allow-list. For a panel that signs in through SSO only, see [Authentication → Password sign-in switch](authentication.md#password-sign-in-switch-v2210).

### Identity binding and tenants (v2.4.0)

`identity_match_attribute => 'email'` adopts the local account that holds the IdP's address, and only when it can trust that row's address (v2.4.0): the row's `email_verified_at` is set (a user model without that column cannot prove a verified address, so with registration open it counts as unverified), or `martis.auth.registration.enabled` is `false`, so an administrator provisioned every row. With self-registration open and the row unverified, anyone could have registered the IdP user's address first and kept their own password, so the sign-in is **refused** (a logged warning and the translated `sso_account_unverified` message on the login page) instead of adopting the row and syncing the IdP's roles onto it. An address that the `external_id` strategy did not link, when `auto_create_user` would otherwise create a second row beside it, is refused too, with its own reason (`unlinked_account`, v2.21.0; see [Failed sign-ins](#failed-sign-ins-reason-log-and-event-v2210)). Verify the address (email verification, or an administrator marks it), link the row by external id, or delete it. With registration enabled an app therefore needs an `email_verified_at` column (with verified rows) or the `external_id` strategy for SSO to adopt existing accounts. A closure registered through `MartisSso::resolveUserUsing()` replaces this logic and decides for itself. Two further guards close the pre-hijack route that used to exist there: the profile no longer lets a user write an address without the current password and a confirmation link sent to that address (see [Authentication → Changing the email address](authentication.md#changing-the-email-address-v240)), and the Azure provider rejects an identity whose `tid` claim differs from a configured concrete `tenant`. A multi-tenant registration (`common`, `organizations`, `consumers`, or no tenant) checks nothing: prefer `identity_match_attribute => 'external_id'` there.

### Matching by `external_id` (v2.21.0)

`identity_match_attribute => 'external_id'` matches the id the IdP issued (the Azure object id, a Google subject) against a column of your users table: `identity_external_id_column`, `{provider}_external_id` when unset (`azure_external_id`). It survives an email change at the IdP and does not depend on the address being verified, which makes it the strategy for a multi-tenant app registration.

- **The id is stored.** A user the sign-in creates gets the id written into that column, so the next sign-in finds the same row. (Before v2.21.0 the column was never written: the second sign-in of an auto-created user found no id match, saw the row holding the address and was refused.)
- **A missing id is refused.** `SsoIdentity::$externalId` is never `null`, but a provider can hand over an empty string. Querying it would match every row whose column is empty, an arbitrary account, so the sign-in is refused with reason `missing_external_id` before anything is read or written.
- **Email at the IdP changed.** The id still matches the same row. The new address is copied onto it when `sync_user_attributes` lists `email`; otherwise the row keeps its address.
- **`link_by_email` (per provider, default `false`).** An existing account that holds the IdP's address but no id yet (the accounts you provisioned before switching strategy) is refused by default, as before, with reason `unlinked_account` (see [Failed sign-ins](#failed-sign-ins-reason-log-and-event-v2210)). With `link_by_email => true` it is linked: the row must pass the same adoption rule as the `email` strategy (verified address, or `registration.enabled` false) and its id column must be empty, and then the id is written and the sign-in goes on. A row that already stores a **different** id is refused with reason `external_id_mismatch` and logged; the stored id is never overwritten. Linking applies even when `auto_create_user` is `false`: it does not create anything.
- **`sync_user_attributes` accepts `external_id`**, on either strategy: the id is written into the column when it is empty (a user created by the `email` strategy gets it too), and a different non-empty value refuses the sign-in with `external_id_mismatch`, before the name or email of the row is touched.

```php
'azure' => [
    'identity_match_attribute'    => 'external_id',
    'identity_external_id_column' => 'azure_object_id',
    'link_by_email'               => true,   // adopt the pre-provisioned rows once, by address
    'auto_create_user'            => true,
],
```

A `identity_match_attribute` value other than `email` or `external_id`, or an `identity_external_id_column` that is not a non-empty string, throws an `InvalidArgumentException` naming the key instead of matching nothing.

### Deny before provisioning (v2.21.0)

A callback used to write the local user first (create it, rewrite the name and email of an existing one) and only then map the roles and refuse an identity without a role. A refused identity therefore left a row behind, or rewrote one. Roles now come first:

| Role mapping | What runs | A refusal |
|---|---|---|
| `role_strategy` `column` or `config`, and no `MartisSso::resolveRolesUsing` closure | The roles are mapped with no user. When there is nothing to sign in as, the callback returns **before** `IdentityResolver` (and a `resolveUserUsing` closure) runs. | Nothing was written. |
| `role_strategy` `callable`, or a `resolveRolesUsing` closure (they receive the user) | The user is resolved, the roles are mapped and the decision is taken **inside a database transaction** on the user model's connection. | The transaction is rolled back: the created row is gone and a rewritten name or email is restored. This includes a `callable` `on_no_role_match` hook that returns a redirect, a `callable` hook that returns nothing, and a `resolveUserUsing` closure that returns `null`. |

`on_no_role_match => 'guest'` is not a refusal: the user is written and signed in without a role, in both rows of the table. The `SsoFailed` event is dispatched after the rollback, so a listener that writes to the same database is not rolled back with it.

`provision_on_deny` (per provider, default `false`) restores the old order, write first and refuse after, for an app that pre-provisions rows on purpose and wants them updated even when access is refused.

What a transaction cannot undo is yours to handle: a `resolveUserUsing` closure that writes **outside the user model's connection** (another database, a queue, an HTTP call), and the model events (`created`, `updated` observers) that a rolled-back row already fired. Keep those side effects in the `afterLogin` hooks or the `SsoLoggedIn` listener, which only run once the identity was admitted. An `on_no_role_match` value other than `deny`, `guest` or `callable` throws an `InvalidArgumentException` naming the key (it used to sign the user in without a role).

Because roles are decided first, an identity that has no role **and** no local user is now refused with `no_role_match` rather than `not_provisioned`.

### Failed sign-ins: reason, log and event (v2.21.0)

Every refusal of the callback (the IdP call failed, the account may not be adopted, the user is not provisioned, no role matches) goes through one path: a warning log line, an `SsoFailed` event, and a redirect to the login page. Each refusal has a `reason`:

| Reason | When |
|---|---|
| `unknown` | Anything thrown that is not a `Martis\Sso\SsoException`: the provider (an unreachable IdP, a rejected code, a malformed token), the user step (a unique index on the email, the two concurrent first sign-ins of one identity, an IdP email change onto an address another row holds), the role sync or an `afterLogin` hook. |
| `account_unverified` | A local row holds the IdP's address and the sign-in may not adopt it because the row's email is unverified while self-registration is open (`SsoAdoptionRefusedException`). Also the refusal of an address that differs from the row's only in letter case, on the `email` strategy. |
| `unlinked_account` (v2.21.0) | `identity_match_attribute => 'external_id'`, no row carries the IdP's id, and a local row holds the IdP's address while `link_by_email` is off: the sign-in neither links that row nor creates a second one beside it, whatever the row's verification. The log line names the cause and the `link_by_email` key (and the id column to fill); the login page shows the generic text, so it does not tell whoever holds an IdP account that a local row with that address exists. Before v2.21.0 this was reported as `account_unverified`. Fix: set `link_by_email` to `true` (the row must pass the adoption check below), or store the IdP's id in the id column of that account. |
| `missing_external_id`, `external_id_mismatch` | See [Matching by `external_id`](#matching-by-external_id-v2210). Both show the generic text on the login page: a more specific one would tell whoever holds an IdP account that a local account with that address exists. |
| `email_unverified` | The provider says the identity's email is not verified (`SsoIdentity::$emailVerified === false`) and a local row holds that address: the row is not adopted, on the `email` strategy or with `link_by_email`. The signed-token provider sets it from the issuer's `email_verified` claim. A provider that makes no statement (`null`, the default and Azure's case) is unaffected. The login page shows the generic text. |
| `not_provisioned` | No local user, and `auto_create_user` is off (or the user resolver returned `null`). |
| `no_role_match` | No role maps and `on_no_role_match` denies (also when a `callable` hook returned a redirect: the person is not signed in, the hook decides where they go). |

The log line (`SSO callback failed`, on the default log channel) carries `provider`, `reason`, `request_id` (the request's `X-Request-Id` header, else a UUID generated for this line), `exception` (the class name) and `error` (the message). The message is **redacted** first: HTTP client exceptions embed the request URL and the response body, so the values of `code`, `state`, `token`, `id_token`, `access_token`, `refresh_token`, `client_secret`, `code_verifier`, `client_assertion`, `assertion`, `SAMLResponse`, `SAMLRequest`, `password`, `id_token_hint` and `private_key` are replaced with `[redacted]` whether they appear as query pairs or JSON pairs, and so is a `Bearer` credential.

A refusal never becomes a 500 page: whatever the user step, the role sync or an `afterLogin` hook throws (an `InvalidArgumentException` from a hook included) is logged through the redactor, dispatched as `SsoFailed` with reason `unknown`, and shown as the generic text; nobody is signed in. The one exception is a **configuration error** of the user or role step (an `InvalidArgumentException` naming the key, such as an unsupported `on_no_role_match`): that is thrown, so a typo is loud.

What stays written when a sign-in is refused after the user step depends on the order of [Deny before provisioning](#deny-before-provisioning-v2210). The role sync and the `afterLogin` hooks run right after the user is resolved. On the **transaction** order (a role mapping that needs the user) they run inside the transaction, so a failure of either rolls the user row back too. On the **pre-check** order (and with `provision_on_deny`) there is no transaction: a row the sign-in already created or updated **stays**, the person is not signed in, and the next attempt finds the row.

The login page shows the exception's `messageKey` text when the exception sets one and the key is translated, and the generic `sso_callback_failed` text otherwise (a plain exception never shows its message). The text reaches the page as an error toast on arrival: the callback flashes it as the `sso` error of the redirect, the SPA shell publishes that one string as `window.MartisConfig.flash.sso` (`Martis\Auth\PublishedFlash`, nothing else of the error bag, once: it is read from the session flash, so a reload shows nothing) and the login page toasts it, also when password sign-in is off and the SSO buttons are all there is. The redirects of a provider that is not enabled (`sso_provider_disabled`) take the same path. A provider or a `resolveUserUsing` closure can choose both by throwing `Martis\Sso\SsoException`:

```php
use Martis\Sso\SsoException;

throw new SsoException(
    'Tenant of the token is not allowed',      // for the log
    'tenant_not_allowed',                      // reason: a snake_case slug listeners can branch on
    'messages.sso_tenant_not_allowed',         // login page text, any translation key; null = generic text
);
```

```php
use Illuminate\Support\Facades\Event;
use Martis\Events\SsoFailed;

Event::listen(SsoFailed::class, function (SsoFailed $event) {
    // $event->provider, $event->reason, $event->exception (?Throwable, the RAW exception:
    // its message and trace are not redacted, so do not log or ship it as it is)
    AuditLog::record('sso.failed', ['provider' => $event->provider, 'reason' => $event->reason]);
});
```

### Ending an SSO session: age, revocation, revalidation (v2.21.0)

Once a person is signed in through SSO, nothing asks the IdP again: the session lives until `SESSION_LIFETIME`, and with `remember` on, a cookie signs the user back in for up to 400 days (only revocation reaches a remembered session, see below). Three independent, **off by default** mechanisms end an SSO-opened session before that. They share one middleware, `martis.sso.session`, which sits right after `martis.auth` in the authenticated stack (`Martis\Http\RouteMiddleware::authenticated()`), ahead of every gate (2FA, email verification, forced password change). A session that SSO did not open (password, magic link, invitation) is **never touched** by any of them.

The middleware is also on the two routes that run `martis.auth` alone (`GET /email/verify`, `POST /api/auth/email/verification-notification`), and `GET /api/auth/user`, which is public and runs without it, answers as **signed out** (literal `null`) for a session the middleware would end, and ends it, using the same code (`Martis\Sso\SsoSessionEnforcer`). So a revoked or expired session cannot keep reading the current user or sending mail.

The session records, at sign-in, when the SSO sign-in happened (`martis_sso_at`, unix time) and the identity's external id (`martis_sso_external_id`). After a remember-me re-login, the new session gets the **original** sign-in time back from the SSO cookie (its issue time), never the time of the re-login, so that the revocation time of the next paragraph compares with the real sign-in.

**`remember` and the two limits exclude each other.** A provider with `remember => true` together with `max_session_minutes` or `revalidate_every_minutes` is a configuration error: the sign-in and every request of an SSO session throw an `InvalidArgumentException` naming the keys. The reason is that a remembered re-login (the session ended, Laravel's remember cookie signs the user in again) is recognised as an SSO one only through the `martis_sso_provider` cookie and its nonce in the cache. The server cannot rely on either: the person can delete the cookie, and a cache flush (a deploy, an eviction, a restart) drops every nonce. A re-login that is not recognised is a session no limit applies to, so the limits could not be enforced. Pick one: no remember cookie, and the session ends on its own and the limits apply, or a remember cookie and revocation (`revokeFor()`) as the way to end it. Revocation does not depend on the origin cookie: it cycles the user's remember token, so it still covers remembered sessions.

#### Absolute age: `max_session_minutes`

```php
'azure' => [
    // ...
    'max_session_minutes' => 480, // 8 hours, counted from the SSO sign-in
],
```

A session this provider opened that is that old or older ends on its next request. A session opened before v2.21.0 carries the origin but no sign-in time and counts as expired. The value is a positive whole number of minutes (an environment string such as `"480"` is fine); anything else throws an `InvalidArgumentException` naming the key.

Ending a session does what a local sign-out does on **this device** (the guard's current-device logout, session invalidated, new CSRF token, the SSO origin and the remembered 2FA device dropped) **without** the federated logout: the IdP session is the one that is gone, or the person is about to sign in at it again. It also does **not** cycle the user's remember token, so an SSO session that reaches `max_session_minutes` on a laptop does not kill the remember-me cookie of the same user's password session on a phone; `revokeFor()` cycles it on purpose. The response then sends the person back through the provider:

- a request that expects JSON gets `401 {"message": "...", "sso_reauth_url": "/martis/sso/azure/redirect"}`. The SPA's global 401 handler navigates to that URL (a full page load) instead of the session-expired login page. It follows only a path under the Martis base path on its own origin: an absolute URL, a protocol-relative one, or a path that dot segments or a backslash would move out of the base path is ignored and the normal session-expired redirect happens;
- any other request is redirected to `/{martis}/sso/{name}/redirect`.

A page fires several API requests at once, and only the first one meets the session; the rest are plain unauthenticated requests. So that they do not answer a bare 401 (which would send the SPA to the login page, past the provider), the session that ends leaves a marker for **60 seconds**: the provider's name and the time, nothing of the user. It lives in the new anonymous session and, for the requests the browser sent before it learned the new session id, in the cache under a hash of the old id. While it holds, an unauthenticated JSON request of that browser (the `martis.auth` middleware answers it) gets the same `401 {"message", "sso_reauth_url"}`. The marker is dropped when the provider's redirect route is visited, on any sign-in or sign-out, and after the minute; a browser that never had an SSO session gets the plain 401. If the cache cannot take the marker the failure is reported and only the session half answers.

On the SPA side the way back is followed **once** per page life: the 401s that come after it do nothing, and a 401 without the key waits 300 ms before it sends the page to the login page, so it can never override a way back that arrives a moment later. A page restored from the browser's back-forward cache (Back from the provider's page) follows it again.

#### Revocation: `MartisSso::revokeFor()`

```php
use Martis\Sso\Facades\MartisSso;

// The IdP told you the person left (a webhook, a SCIM delete, an HR job):
MartisSso::revokeFor($user->getKey());
```

`revokeFor(int|string $userId)` ends every SSO session of the user, now:

1. It stores a revocation time for the user in the cache (the key is scoped by the Martis guard, the TTL is the remember lifetime). The middleware ends any SSO-opened session whose sign-in is **not later** than that time. This works on every session driver, and with or without `max_session_minutes`.
2. It drops every SSO-origin nonce of the user and cycles the user's remember token, so no remember cookie or SSO origin cookie signs them back in.
3. On the **`database` session driver** it also deletes the user's rows of the `sessions` table. That ends **every** session of the user, including ones opened with a password, a magic link or an invitation, which the middleware cannot tell apart from the rest. The rows are matched by `sessions.user_id`; when the app's session guards sign in users of more than one table that column cannot be attributed to one person, so the rows are left alone (a warning is logged) and only the SSO sessions end, through step 1.

A sign-in after the revocation works normally: its sign-in time is later. That includes the one the middleware's redirect starts: while the person's session at the IdP (or, with the [signed token](#18-signed-token-between-two-martis-apps-v2210), at the issuing panel) is alive they are signed in again at once, so a revocation that must hold is done at the IdP (or the issuing panel) as well, and `revokeFor()` on one panel never ends the sessions of another. The times are whole seconds, so a sign-in in the very second of the revocation counts as revoked.

A cache that cannot be **read** while the middleware looks for the revocation is reported and treated as "revoked" (fail closed: the session ends and the person signs in again). Because the revocation read runs on every SSO request, a cache outage ends every SSO session, fresh sign-ins included (sign in, the next request ends it, and so on) until the cache is back: that is the price of a revocation that cannot be skipped. A cache that cannot be **written** makes `revokeFor()` throw a `RuntimeException`, so a revocation that did not stick never looks as if it had. While an impersonation is active the session belongs to the operator who signed in through SSO, so the checks run against the operator.

#### Revalidation: `revalidate_every_minutes`

Ask the provider whether the person may still be in. The provider class implements `Martis\Sso\Contracts\RevalidatesSessions`, next to `SsoProviderContract`:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Martis\Sso\Contracts\RevalidatesSessions;
use Martis\Sso\Contracts\SsoProviderContract;

final class OktaProvider implements SsoProviderContract, RevalidatesSessions
{
    // name(), redirect(), resolveIdentity() ...

    public function stillValid(Authenticatable $user, ?string $externalId): bool
    {
        // Ask the IdP (a user lookup, a token introspection). Keep it cheap: it runs inside a request.
        return $this->idp->isActive($externalId);
    }
}
```

```php
'okta' => [
    // ...
    'revalidate_every_minutes' => 15,
],
```

The middleware calls `stillValid()` at most once per interval per session (the last check time is kept in the session; the SSO sign-in itself counts as the first check). `externalId` is the id the IdP issued at sign-in (null for a session opened before v2.21.0).

- `true`: the session goes on.
- `false`: the session ends as above.
- **a throwable**: it is reported and the session is **kept**, and the check runs again **no sooner than one minute later** (kept in the session) instead of after a whole interval, and not on every request. An IdP outage must not sign every user out, nor turn every request of every SSO user into a synchronous IdP call; the hard bound is `max_session_minutes`, so set both when an outage must not stretch a session forever. Even so, `stillValid()` runs inside a request: give its HTTP client a **short timeout** (a few seconds), because every session waits that long once a minute while the IdP is down.

`revalidate_every_minutes` set on a provider whose class does not implement the contract throws an `InvalidArgumentException` naming the key.

#### Order of the checks

Revocation, then age, then revalidation; the first that applies ends the session. The config values are read (and validated) before any of them runs, so a bad value fails on every SSO request, not only when it would matter.

---

## 6. The three orthogonal axes

The three knobs (`role_source`, `role_strategy`, `permission_adapter`) compose freely. **3 × 3 × 4 = 36 combinations** cover every realistic case without writing PHP. Anything outside that — use a `callable` in the relevant axis.

### `role_source` — where external role names come from

| Value | Behaviour |
|---|---|
| `groups` | Calls `GET /v1.0/users/{id}/memberOf?$select=displayName` on Microsoft Graph. Returns each group's `displayName`. Coarser-grained but doesn't require defining App Roles in the Azure portal. |
| `app_role_assignments` | Calls `GET /v1.0/users/{id}/appRoleAssignments?$filter=resourceId eq {resource_id}`. Returns each assignment's `principalDisplayName`. Recommended — gives you per-app role granularity. |
| `callable` | Defers to `role_source_callable` in the provider config, called with `(string $externalId, string $accessToken)` and returning the external role names (`array<int, string>`). Use for non-Microsoft IdPs or for custom Graph queries. |

### `role_strategy` — how to map external names to local roles

| Value | Behaviour |
|---|---|
| `column` (default) | `Role::query()->whereIn($role_column, $externalRoles)->get()`. The host app stores the IdP group/app-role name on the roles table. Most flexible — each role row decides whether and how it's mapped, without code changes. |
| `config` | `role_map` array in config maps `local_slug => env_value`. The mapper finds local roles whose `name` equals the slug for each `env_value` present in the external list. Useful when you can't add columns to `roles`. |
| `callable` | Defers entirely to `role_callable` in the provider config, called with `(array $externalRoles, ?User $user, string $provider)`. Returns a Collection of role models. Use for arbitrarily complex business rules. |

`role_source_callable` and `role_callable` take the name of an invokable class (built through the container) or a `[Class::class, 'staticMethod']` array. Both forms survive `php artisan config:cache`; a closure does not.

```php
'role_source' => 'callable',
'role_source_callable' => \App\Martis\AzureRoles::class,        // __invoke(string $externalId, string $accessToken): array

'role_strategy' => 'callable',
'role_callable' => [\App\Martis\SsoRoleMapper::class, 'map'],   // public static function map(array $externalRoles, ?User $user, string $provider): Collection
```

A value that resolves to no callable throws an `InvalidArgumentException` naming the key, rather than reading no roles: with `on_no_role_match => 'guest'` and `sync_roles`, an empty role list logs the user in and syncs their local roles away. From `role_source_callable` the exception fails the callback (logged as `SSO callback failed`, and the login page shows the callback error); from `role_callable` it fails the request. See [Config keys that take a callable](configuration.md#config-keys-that-take-a-callable).

### `permission_adapter` — how resolved roles get written onto the user

| Value | Behaviour |
|---|---|
| `auto` (default) | Picks `SpatieAdapter` when `spatie/laravel-permission` is installed, `NativeAdapter` otherwise. It never picks `callable`: a `MartisSso::syncRolesUsing()` callback is ignored while this is `auto`. |
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

Side-effect hook fired after the role sync and before the sign-in. Audit logs, welcome emails, derived columns.

```php
MartisSso::afterLogin(function ($user, SsoIdentity $identity, string $provider) {
    \App\Models\AuditEvent::record('sso.login', $user, ['provider' => $provider]);

    $user->update([
        'type' => $user->hasRole('frontliner') ? 'frontliner' : 'backoffice',
        'last_sso_login_at' => now(),
    ]);
});
```

Registrations **accumulate** (v2.21.0): each call appends a callback and they run in registration order, so a package and the app can both register one without overwriting each other. Pass a provider name as the second argument to run a callback for that provider only; without it the callback runs for every provider.

```php
MartisSso::afterLogin(fn ($user, $identity, $provider) => AuditLog::record($user));            // every provider
MartisSso::afterLogin(fn ($user, $identity, $provider) => SyncAzureGroups::dispatch($user), 'azure'); // azure only
```

An exception thrown by a callback stops the sign-in (the next callbacks do not run and the user is not logged in). To react after the user is signed in, listen to `SsoLoggedIn` instead, see [Events](#events-v2210).

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

Single-valued per scope (v2.21.0): one global slot and one slot per provider (`MartisSso::onNoRoleMatchUsing($callback, 'azure')`). The last registration for a scope replaces the earlier one; for a provider its own slot wins and the global one answers every provider without a slot.

### Composing hooks

Hooks compose with each other AND with config — you can override `resolveUserUsing` while keeping the built-in `RoleMapper` (`role_strategy = 'column'`). Each layer is independent.

`MartisSso::forgetHooks()` (v2.21.0) clears every hook at once: the `afterLogin` and no-role-match callbacks (global and per provider) and the `resolveUserUsing`, `resolveRolesUsing` and `syncRolesUsing` overrides. Use it in tests and in an app that rebuilds its hooks per Octane worker; `flushHooksForTesting()` stays and calls it.

### Events (v2.21.0)

Two events complement the hooks, and the issuing side of the [signed token](#issuer-audit-events-v2220) has two of its own. Listen with `Event::listen()` or a subscriber:

| Event | Dispatched | Properties |
|---|---|---|
| `Martis\Events\SsoLoggedIn` | Exactly once per successful sign-in, after `Auth::login()` and after the SSO session marker is set (the `afterLogin` hooks run before the sign-in). | `Authenticatable $user`, `SsoIdentity $identity`, `string $provider` |
| `Martis\Events\SsoFailed` | Once per refused callback, after the log line and after any provisioning was rolled back. See [Failed sign-ins](#failed-sign-ins-reason-log-and-event-v2210). | `string $provider`, `string $reason`, `?Throwable $exception` |
| `Martis\Events\SsoTokenIssued`, `Martis\Events\SsoIssueDenied` (v2.22.0) | The signed-token issuer signed a token, or refused to. See [Issuer audit events](#issuer-audit-events-v2220). | `Authenticatable $user`, `string $audience`, `string $requestId` (and `string $reason` on the denial; its `user` may be `null`) |

---

## 10. REST surface and routes

Both routes register only when `auth.sso.enabled = true`. They're unauthenticated (the user isn't logged in yet) and rate-limited via the `login_attempts` / `login_minutes` config.

| Method | Path | Description |
|---|---|---|
| `GET` | `/{martis}/sso/{provider}/redirect` | Kicks off the OAuth flow. Returns 302 to the IdP authorize URL. |
| `GET` | `/{martis}/sso/{provider}/callback` | Handles the IdP callback. Resolves identity → roles → user → login. Redirects to `provider.redirect_to` (or `/martis` by default) on success, `/martis/login` with errors on failure. |

The `provider` segment is **whatever the user registered** — `azure`, `google`, `github`, `okta`, custom names. Routes only resolve if the provider is registered AND enabled. The segment must be the provider's **lower-case** name, exactly as its config key is spelled: `/sso/AZURE/callback` answers **404** (v2.21.0). The name used to be lowercased only to find the driver, so a path in another letter case ran the sign-in with every other setting of the provider (identity matching, role mapping, session limits, federated logout) read under a key that does not exist, that is, on the defaults.

A third route belongs to the issuing side of the [signed token](#18-signed-token-between-two-martis-apps-v2210) (v2.21.0). It is always registered, runs the full protected stack, and answers 404 while `auth.sso.issuer.enabled` is false:

| Method | Path | Description |
|---|---|---|
| `GET` | `/{martis}/sso/issue/{audience}?state=...` | A signed-in user (2FA passed, `viewMartis` allowed) is redirected to the audience's configured `callback_url` with a signed `token` and the `state`. An anonymous visitor goes to the login page first. |

The provider route `driver => 'signed-token'` uses the same two `/{martis}/sso/{provider}/...` routes as every provider.

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
    [--driver=signed-token]     # v2.21.0: a block of any name that signs in through another Martis app
    [--issuer]                  # v2.21.0: add an audience to the issuing block instead
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
| `--with-migration` | Publishes a migration adding `{provider}_group_name` to `roles`. Fails when neither the `roles` table nor Spatie's `create_permission_tables` migration exists. |
| `--strategy` | Sets the default `role_strategy` (default `column`). |
| `--no-auto-create-user` | Sets `auto_create_user = false` in the generated config. |
| `--custom` | Skips the validation that the provider is one of `azure / google / github`. Use when registering a custom provider via `MartisSso::extend()`. |
| `--driver=signed-token` | (v2.21.0) The `provider` argument is any name (`panel-a` becomes `panel_a`); the block gets `driver => 'signed-token'`, the `MARTIS_SSO_{NAME}_*` env stubs (provider disabled until filled) and `identity_match_attribute = external_id`. Nothing is installed: no Socialite, no listener. |
| `--issuer` | (v2.21.0) The argument is an audience name (kept as typed, lowercased: `panel-b`). Adds `'panel-b' => ['callback_url' => env(...)]` to `auth.sso.issuer.audiences` and the `MARTIS_SSO_ISSUER_*` env stubs. The private key stub is left empty: no command writes a key. Not combinable with `--driver` or `--custom`. |

The key pair comes from its own command, `php artisan martis:sso:keys` (v2.21.0): it prints a new Ed25519 pair as base64 with where each half goes, and writes nothing.

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
| Config block | `'azure' => [` already declared in `config/martis.php` (a commented example does not count) |
| Env vars | Each `MARTIS_SSO_*` / `AZURE_*` line already in `.env` |
| Listener | `MicrosoftExtendSocialite` string already in `AppServiceProvider.php` |
| Spatie publish | `config/permission.php` AND `*_create_permission_tables.php` already exist |
| Martis migration | A matching `*_add_{provider}_group_name_to_roles_table.php` already published |
| Migrate | Standard Laravel — no pending migrations is a no-op |
| Role mapping | Empty input keeps current value; same input is no-op |

---

## 12. Migration from a custom OAuth controller

Apps that already have a custom `AzureOauthController` (typically extending the host's `LoginController`) usually carry ~200 lines covering:

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

#### A translated button label (v2.22.0)

`label` is a string, or a map of `locale => string` that the login page resolves against the active language the way it resolves [`auth.copy`](authentication.md#customising-the-auth-copy) values: the exact locale (`pt_PT`), then its prefix (`pt`), then `en`, then the first non-empty entry. The button follows the language picker without a reload.

```php
'azure' => [
    'enabled' => true,
    'label' => [
        'en' => 'Continue with Microsoft',
        'pt_PT' => 'Continuar com a Microsoft',
    ],
],
```

Unset (`null`), an empty string, an empty map or a map with no usable entry, the button reads "Continue with `<provider name>`" from the bundled translation. A `label` that is set but is neither a string nor a map of strings (a number, a list, a map with a non-string value) throws an exception naming `martis.auth.sso.providers.{provider}.label` when the login page is built; before v2.22.0 it was dropped without a word. The label of a provider that is not enabled is not read.

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

### CSRF / state / nonce / PKCE

Socialite 5.x manages the OAuth `state`, `nonce`, and PKCE parameters automatically. ID-token signature validation is delegated to Socialite for OIDC providers. Don't disable the state check; don't drop the nonce; don't try to assemble the authorize URL by hand. The `redirect()` flow in `AzureProvider` defers entirely to `Socialite::driver(...)->redirect()`.

### Federated logout (Single Sign-Out)

By default `POST /martis/api/auth/logout` only clears the local session. The user stays signed in at the IdP, so re-clicking "Sign in with Microsoft" silently reuses the SSO session.

To opt in to federated logout, set the per-provider `logout_url` (since v1.8.8). When the user came in via SSO, logout redirects through the IdP's logout endpoint after invalidating the local session:

```php
// config/martis.php
'azure' => [
    // ... rest of the block
    'logout_url' => env('MARTIS_SSO_AZURE_LOGOUT_URL'),
],
```

```dotenv
MARTIS_SSO_AZURE_LOGOUT_URL=https://login.microsoftonline.com/{tenant}/oauth2/v2.0/logout?post_logout_redirect_uri={post_logout_redirect_uri}
```

The placeholder `{post_logout_redirect_uri}` is replaced at logout time with the urlencoded Martis login URL — Azure (and most OIDC providers) need this to know where to bounce the browser after killing the IdP session.

The marker that says "this user came in via SSO" lives in the session under `martis_sso_provider`, and in a cookie of the same name (v2.3.0). The SSO callback signs in without remember-me unless the provider sets `remember` to `true` (v2.4.0; it was always on before, so panel access outlived the IdP session and IdP-side deprovisioning). For a provider that opts in, the session can end while the remember cookie signs the user back in, into a new session: the cookie carries the origin across that re-login, so the logout still goes through the IdP, and the [forced password change](authentication.md#forced-password-change) gate still leaves the session alone. The cookie holds the user's id, the provider, the time it was issued, the identity's external id (v2.21.0) and a random nonce, encrypted by Martis (a browser cannot mint one), and is written only for a remembered sign-in. It counts only for the user it names, only while it is younger than the remember lifetime (`auth.guards.{guard}.remember`, else Laravel's 576000 minutes) and only while the server still holds its nonce for that user (cache, same lifetime; v2.4.0). A password, magic-link or invitation sign-in drops every nonce of the user, so a copy of an old cookie re-attached after a password sign-in no longer exempts the session from the forced password change; the sign-out drops the nonce of its own browser. A password, magic-link or invitation sign-in, and the sign-out, drop both, so a user who switches from SSO to password mid-stream does not get redirected to Azure on logout. `Martis\Sso\SsoSession` reads and writes them.

`logout_url` can also be the name of an **invokable class** (v2.21.0), for an IdP whose logout URL needs more than a placeholder, such as an `id_token_hint` or a signed request:

```php
'azure' => [
    'logout_url' => App\Martis\AzureLogoutUrl::class,
],
```

```php
final class AzureLogoutUrl
{
    public function __invoke(Request $request, ?Authenticatable $user, string $postLogoutRedirectUri): ?string
    {
        return 'https://idp.example/logout?'.http_build_query([
            'id_token_hint' => $this->tokens->idTokenOf($user),
            'post_logout_redirect_uri' => $postLogoutRedirectUri,
        ]);
    }
}
```

It runs **before** the local logout (the user is still signed in), receives the login page URL as it is (encode it where your URL needs that: the `{post_logout_redirect_uri}` replacement is for plain URL strings only), and answers the URL, or `null` (or an empty string) for a local logout only. A plain URL string keeps today's behaviour. A string that names a class which is not invokable, or a class that answers something other than a string or `null`, throws an `InvalidArgumentException` naming `martis.auth.sso.providers.{name}.logout_url`. A class whose `__invoke` **throws** (it asked the IdP for its discovery document and the IdP is down) is reported and the sign-out completes locally, with no federated URL: the person is always signed out of the panel.

The JSON variant of the logout endpoint surfaces the IdP URL as `logout_url`, so a SPA can drive the redirect itself if it needs to add UI between local and federated logout.

### Refresh tokens

Martis does **not** persist refresh tokens. Apps that need offline-token rotation (Graph queries fired from queued jobs, calendar sync, etc.) should capture both the access token and the refresh token in the `afterLogin` hook and run their own renewal flow. A first-class refresh-token store is on the roadmap, not in the current release.

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

To allow re-login (typical permissive behaviour):

```php
->tap(fn ($u) => $u->trashed() && $u->restore());
```

### Email forwarding / impersonation

The IdP guarantees the email belongs to the authenticated user. Martis trusts that. Don't add extra "verify the email" hops post-SSO.

### Role escalation through `groups` strategy

When using `role_source = 'groups'`, ANY group the user belongs to is considered. Make sure non-admin distribution lists, MS Teams groups, etc., are NOT named in your `roles.azure_group_name` column. Prefer `app_role_assignments` for tighter control.

---

## 16. Troubleshooting

### People are sent through the IdP again after a while

Check the provider block for `max_session_minutes` and `revalidate_every_minutes` (see [Ending an SSO session](#ending-an-sso-session-age-revocation-revalidation-v2210)). A session opened before v2.21.0 has no sign-in time and counts as expired once `max_session_minutes` is set: each such user signs in once more and the session then carries its time. Separately, a `RevalidatesSessions::stillValid()` that throws never ends the session, but it is called again on every request until it answers, so a slow IdP call shows up as slow requests: keep it cheap and give it a short timeout.

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

### Callback returns 302 to `/martis/login` and the login page shows a generic text

The reason is in the log, not on the page. Search the log for `SSO callback failed`: its `reason` (`unknown`, `account_unverified`, `unlinked_account`, `email_unverified`, `missing_external_id`, `external_id_mismatch`, `not_provisioned`, `no_role_match`), `exception` class and redacted `error` say what happened, and `request_id` ties the line to the request (send an `X-Request-Id` header from your proxy to correlate it with the access log). See [Failed sign-ins](#failed-sign-ins-reason-log-and-event-v2210).

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
| Missing read permission on `AppRoleAssignment` | The Azure app needs `User.ReadBasic.All` + `GroupMember.Read.All` granted; double-check by hitting `/users/me/appRoleAssignments` in Graph Explorer with the same delegated permissions. Martis only ever reads — the `ReadWrite.All` scope is never required. |
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

| File | What it covers |
|---|---|
| `tests/Unit/Sso/SsoIdentityTest.php` | Value object contract (full + minimal payloads). |
| `tests/Unit/Sso/RoleMapperTest.php` | All three strategies (column, config, callable) + global override + empty input + no-match. |
| `tests/Unit/Sso/IdentityResolverTest.php` | Find-or-create flow, attribute sync, soft-delete handling, host-app override hook. |
| `tests/Unit/Sso/PermissionAdaptersTest.php` | Spatie / Native / Callable / Auto adapter routing + role attach/detach semantics. |
| `tests/Unit/Sso/SsoManagerTest.php` | Singleton wiring, hook registration + dispatch, provider extension via `extend()`. |
| `tests/Feature/SsoControllerTest.php` | End-to-end callback: user create / sync, role map, deny / guest paths, hook firing, disabled-provider redirect, route gating, federated logout, password-login wipe of the SSO marker. |
| `tests/Unit/Sso/SsoLogRedactorTest.php` | The log redactor (query and JSON pairs, escaped JSON, bearer values, look-alike keys) and the `SsoException` / `SsoAdoptionRefusedException` contract. |
| `tests/Unit/Sso/SsoManagerHooksTest.php` | Accumulating `afterLogin` hooks, provider scoping, single-valued no-role-match slots, `forgetHooks()`. |
| `tests/Unit/Sso/IdentityResolverExternalIdTest.php` | `external_id` matching: id stored on create, second sign-in, `link_by_email`, mismatch, unverified refusal, empty id, `sync_user_attributes: external_id`, config errors. |
| `tests/Feature/SsoFailureReasonTest.php` | The reason, log context (request id, redaction) and `SsoFailed` event of every refusal, and the login page text. |
| `tests/Feature/SsoLoggedInEventTest.php` | `SsoLoggedIn`: once per sign-in, after the login and the SSO marker, never on a refusal. |
| `tests/Feature/SsoDenyBeforeProvisioningTest.php` | Deny before provisioning on the pre-check and the transaction path, `guest`, `provision_on_deny`, rollback of resolver writes, a refusal that is not an `SsoException` (unique index, throwing hook or role sync) is a refusal and not a 500. |
| `tests/Feature/SsoSessionMarkerTest.php` | The SSO origin across a remember-me re-login: the forced password change gate and the federated logout still see it; a cookie for another user or not encrypted by Martis counts for nothing; every other sign-in and both sign-outs drop it. |
| `tests/Feature/SsoSessionRevalidationTest.php` | The sign-in time and external id in the session (kept across a remember-me re-login), `max_session_minutes` (JSON 401 with `sso_reauth_url`, page redirect, boundary, sessions without a time), the refusal of `remember` together with a limit, the 404 for a provider name that is not lower case, `MartisSso::revokeFor()` (array and database drivers, remember cookie, nonces, later sign-in, impersonation, cache failures), `RevalidatesSessions` (false, true, throwing, interval, misconfiguration), password sessions untouched, invokable `logout_url`. |
| `resources/js/lib/api.ssoReauth.test.ts` | The SPA's 401 handler: follows a same-origin path under the Martis base path, ignores absolute, protocol-relative and base-escaping URLs; three concurrent 401s in every arrival order end in exactly one navigation; the way back is followed once and again after a back-forward cache restore. |
| `tests/Feature/LoginSsoFlashTest.php` | The flashed `sso` refusal published once as `MartisConfig.flash.sso` (and nothing else of the error bag), the disabled-provider redirects, markup-safe encoding. |
| `resources/js/pages/Login.ssoFlash.test.tsx` | The login page toasts the flashed text once, also on an SSO-only page, and nothing for an empty or non-string value. |
| `tests/Unit/Sso/SignedToken/UrlsTest.php` | The URL rule of `authorize_url` and `callback_url`: https, or http on `localhost`, `*.localhost`, `*.test`, `127.0.0.1` and `[::1]` only. |
| `tests/Unit/Sso/SignedToken/PasetoV4PublicTest.php` | The PASETO v4.public codec against the official `4-S-*` and `4-F-*` test vectors, PAE, strict base64url, trailing data, canonical encoding. |
| `tests/Feature/SsoSignedTokenProviderTest.php` | Driver resolution by block name, `redirect()`, every refusal with its own reason, key rotation, one-time `jti` (kept past the last accepted second, used up before the state is checked), the state lifetime, the environment lock, `email_verified`, fail-closed cache, config errors. |
| `tests/Feature/SsoSignedTokenIssuerTest.php` | The issue route (login, 2FA, gate, 404, state, redirect target, headers, Fetch Metadata, impersonation 403), the audit events and the denial log (v2.22.0), `TokenIssuer` claims (`email_verified`, reserved claims) and config errors. |
| `tests/Feature/SsoSignedTokenEndToEndTest.php` | One app as both panels: issue route, callback, `external_id` linking, role mapping, `SsoFailed`, no token or key in logs, the `jti` as the second lock, a provider name in another letter case (404), an unverified email never adopting a row, the state-fixation replay refused. |
| `tests/Feature/SsoCacheStoreTest.php` | `auth.sso.cache_store` (v2.22.0): the `jti`, the revocation, the origin nonce, the ended marker and the Azure tenant lookup land in the configured store and not in the default one; an unknown or unusable name throws naming the key. |
| `tests/Feature/SsoSignedTokenCommandsTest.php` | `martis:sso:keys` (never writes), `martis:sso --driver=signed-token` and `--issuer`. |
| `tests/Feature/SsoMakeCommandTest.php` | Generator idempotency: composer skip, config-block insert, env stub, listener register, Spatie publish, migration publish, role-mapping prompt. |

Run the SSO slice with:

```bash
vendor/bin/pest tests/Unit/Sso tests/Feature/SsoControllerTest.php tests/Feature/SsoFailureReasonTest.php tests/Feature/SsoLoggedInEventTest.php tests/Feature/SsoDenyBeforeProvisioningTest.php tests/Feature/SsoSessionMarkerTest.php tests/Feature/SsoSessionRevalidationTest.php tests/Feature/SsoMakeCommandTest.php
```

The signed-token slice (v2.21.0) on its own:

```bash
vendor/bin/pest tests/Unit/Sso/SignedToken tests/Feature/SsoSignedTokenProviderTest.php tests/Feature/SsoSignedTokenIssuerTest.php tests/Feature/SsoSignedTokenEndToEndTest.php tests/Feature/SsoSignedTokenCommandsTest.php
```

The counts grow with each release — see CI output for the live total.

> Looking to swap the React `LoginPage` (multi-provider button strip + email/password form) for a custom shell? See [Override System](overrides.md) — the auth pages sit under registry keys `auth:login`, `auth:register`, `auth:forgot-password`, `auth:reset-password`, `auth:email-verify-notice`.


---

## 18. Signed token between two Martis apps (v2.21.0)

Panel B signs its users in through panel A, when both are Martis apps, with one redirect and a short-lived, one-time token that panel A signs with a private key and panel B verifies with the matching public key. No OAuth server, no client secret, no new table.

It is **not OIDC** and not OAuth: there is no discovery document, no authorization code, no refresh token, and only a Martis panel issues the token. For a third-party identity provider (Microsoft, Google, GitHub, Okta) use the [Socialite providers](#4-azure-ad--full-step-by-step) above.

The token is a [PASETO](https://paseto.io) `v4.public` token (Ed25519): `v4.public.<base64url(payload || signature)>.<base64url(footer)>`. There is no algorithm header and nothing to negotiate. The codec uses `ext-sodium` only, so no Composer package is added. Without the extension the feature throws an exception that says to enable it. The codec is tested against the official PASETO v4 test vectors.

### The flow

```
 browser                  panel B (consumer)                 panel A (issuer)
    | click "Continue with Panel A"                                 |
    |--------------------->|  GET /martis/sso/panel_a/redirect      |
    |                      | random state kept in B's session       |
    |<---------------------|  302 authorize_url?audience&state      |
    |------------------------------------------------------------->|
    |                      GET /martis/sso/issue/panel-b?state=...  |
    |                      (signed in, 2FA passed, viewMartis)      |
    |<-------------------------------------------------------------|
    |                      302 callback_url?token&state             |
    |--------------------->|  GET /martis/sso/panel_a/callback      |
    |                      | verify signature, claims, jti, state   |
    |                      | find or create the local user, sign in |
```

An anonymous visitor at panel A is sent to its login page first and the issue URL is kept as the session's intended URL. The Martis login screen signs in over JSON, and the response that completes the sign-in (after the 2FA challenge, the email verification or a forced password change, when one sits in between) carries that URL, so the screen opens it and the flow carries on to panel B without a second click. Only a same-origin path under panel A's Martis base path is followed; see [Authentication](authentication.md#landing-page-after-sign-in-v2210).

### 1. Generate the key pair

```bash
php artisan martis:sso:keys
```

It prints a new Ed25519 pair as base64 and says where each half goes. It writes nothing: not to `.env`, a config file or a log. The secret key goes into the environment of panel A only (`MARTIS_SSO_ISSUER_PRIVATE_KEY`). The public key is not secret and goes to panel B. The output carries the `kid` too: the first 16 hex characters of the SHA-256 of the public key, which the token's footer names so a verifier can pick the key during a rotation.

### 2. Panel A: the issuer block

```bash
php artisan martis:sso panel-b --issuer   # adds the audience and the env stubs, writes no key
```

```php
// config/martis.php, auth.sso
'issuer' => [
    'enabled' => env('MARTIS_SSO_ISSUER_ENABLED', false),
    'issuer' => env('MARTIS_SSO_ISSUER'),                  // https://panel-a.example
    'private_key' => env('MARTIS_SSO_ISSUER_PRIVATE_KEY'),  // base64, from martis:sso:keys
    'ttl_seconds' => 60,
    'environment' => env('MARTIS_SSO_ISSUER_ENVIRONMENT'),  // 'production' | 'staging' | null
    'claims' => null,                                       // App\Martis\SsoClaims::class
    'audiences' => [
        'panel-b' => [
            'callback_url' => 'https://panel-b.example/martis/sso/panel_a/callback',
            'ability' => 'sign-in-to-panel-b',   // optional; null = any panel user
        ],
    ],
],
```

| Key | Meaning |
|---|---|
| `enabled` | Master switch of the issuing side (default `false`). The issue route answers 404 while it is off. |
| `issuer` | This panel's name in the `iss` claim. Panel B's provider block must carry the same string. |
| `private_key` | Base64 Ed25519 secret key (64 bytes). Read from the environment: no command ever writes it to a file. A key that is not 64 bytes, or whose public half does not match its seed, throws naming this key. |
| `ttl_seconds` | Token life, 1 to 600 (default 60). |
| `environment` | Optional. Added to the token as the claim `env`, so a verifier locked to `production` refuses a staging token. The lock is a pair (v2.22.0): panel B must set the same `environment` too, because a token that carries the claim is refused by a verifier with no `environment`. |
| `role_claim` | Optional claim name for the roles (default `roles`). Keep it equal to panel B's `role_claim`. |
| `environment_claim` | **Removed in v2.22.0.** The claim that carries the environment is always `env`; a block that still sets the key throws, naming it. With a configurable name, a locked issuer and an unlocked verifier could read different claim names and disagree without any error. |
| `claims` | Optional invokable class, `(Authenticatable $user, string $audience): array`, that adds claims and replaces `email`, `name` and the roles. Use the name of a class or a `[Class::class, 'method']` array, never a closure (`config:cache`). `iss`, `aud`, `sub`, `iat`, `exp`, `jti`, `state`, `email_verified` and `env` (the environment claim, always, whether or not `environment` is set) cannot be set: the class throws if it tries. |
| `audiences` | `'<audience>' => ['callback_url' => '...', 'ability' => null]`. `ability` (optional, a non-empty string; anything else throws naming the key) limits who may obtain a token for that audience: the issue route checks `Gate::forUser($user)->allows($ability)`, so an app gate or a Spatie permission name both work. A refusal answers 403 with a translated message, issues nothing and redirects nowhere. Unset, any user who can open the panel gets a token.  The audience is the name panel B uses as its `audience`. A name is 1 to 64 letters, digits, `.`, `-` or `_`, and is not `callback` or `redirect`. |

The claims of the token:

| Claim | Value |
|---|---|
| `iss` | `issuer` |
| `aud` | the audience of the request |
| `sub` | the user's id at panel A, as a string |
| `iat`, `exp` | RFC 3339 UTC; `exp` is `iat + ttl_seconds` |
| `jti` | 128 random bits (hex), used once on the other side |
| `state` | the value panel B kept in the browser's session |
| `email`, `name` | the user's attributes (a user with no email cannot be signed for: panel B requires one) |
| `email_verified` | boolean: `true` when the user model does not implement `Illuminate\Contracts\Auth\MustVerifyEmail`, else `hasVerifiedEmail()`. It describes the user's own address at panel A: a `claims` class that replaces `email` answers for the address it puts there. |
| `roles` | the user's Spatie role names when the model has `getRoleNames()`, else an empty list |
| `env` | only when `environment` is set |

`Martis\Sso\SignedToken\TokenIssuer::fromConfig()->issue($user, $audience, $state)` issues the same token from your own code.

The issue route runs the whole protected stack (signed in, 2FA passed, email verified when required, the `viewMartis` gate) and then the audience's `ability`, when it sets one. While an **impersonation** is active it answers 403 with a translated message and issues nothing: the guard then holds the impersonated user, and a token for them would open a full session at the other panel with no impersonation marker and none of its limits, which that panel's admins never granted the operator. It redirects **only** to the `callback_url` of the audience, never to a URL from the request, with `token` and `state` appended. A `callback_url` must be an absolute `https` URL without credentials or a fragment (`http` is accepted for `localhost`, `*.localhost`, `*.test`, `127.0.0.1` and `[::1]` hosts, so two apps on loopback ports work locally; no other loopback address, such as `127.0.0.2`); anything else throws naming the key. The response carries `Cache-Control: no-store` and `Referrer-Policy: no-referrer`. When the browser sends Fetch Metadata the request must be a top-level navigation (`Sec-Fetch-Mode: navigate` and `Sec-Fetch-Dest: document`): an XHR, an iframe or an image is refused with 403, so another site cannot make a signed-in browser fetch a token. The `state` parameter is required (`^[A-Za-z0-9_-]{16,128}$`, else 422). One audit line is logged per token (audience and user id, never the token), and since v2.22.0 every token and every refusal is also an event: see [Issuer audit events](#issuer-audit-events-v2220).

### Issuer audit events (v2.22.0)

The issue route reports what it signs and what its controller refuses, so panel A can keep an audit trail of who got a token for which panel:

| Event | Dispatched | Properties |
|---|---|---|
| `Martis\Events\SsoTokenIssued` | Once per token signed, before the redirect. The log line `SSO token issued` (info) is unchanged and has the same `request_id`. | `Authenticatable $user`, `string $audience`, `string $requestId` |
| `Martis\Events\SsoIssueDenied` | Once per request the controller refuses (the six reasons below), after a warning log line `SSO token issue denied` (`audience`, `user_id`, `reason`, `request_id`). The HTTP answer is the one the route always gave. | `?Authenticatable $user`, `string $audience`, `string $reason`, `string $requestId` |

The `reason` of `SsoIssueDenied` is one of its six constants:

| Reason | Answer | When |
|---|---|---|
| `unknown_audience` | 404 | The issuer is on and the audience in the URL is not one of `audiences`. |
| `not_navigation` | 403 | The browser's Fetch Metadata says the request is not a top-level navigation (an XHR, an iframe, an image). |
| `invalid_state` | 422 | The `state` parameter is missing or not `^[A-Za-z0-9_-]{16,128}$`. |
| `impersonating` | 403 | An impersonation is active. `user` is the impersonated user, the one the guard holds. |
| `forbidden` | 403 | The audience's `ability` does not allow the user. |
| `cannot_sign` | 500 | Signing failed: a user with no email, a `claims` class that throws or answers the wrong shape, an unusable issuer block. The denial is recorded first and then the exception goes on, so the 500 stays and nothing is hidden. |

Refusals by the route's **middleware** (not signed in, 2FA not passed, email not verified, the `viewMartis` gate, throttle) happen before the controller and raise no event. A **disabled** issuer stays a silent 404: no line, no event. An audience name the route's own shape constraint rejects (more than 64 characters, a character outside letters, digits, `.`, `-`, `_`) never reaches the controller, so it is a plain 404 with no event either. The `audience` of an event or a line is cut to 64 characters and anything but printable ASCII in it becomes `?`: on the unknown-audience path it is whatever the caller put in the URL. `requestId` is the request's `X-Request-Id`, else a UUID, and is the `request_id` of the log line. The header is the caller's to write, so it is bounded: anything but printable ASCII becomes `?` and at most 128 bytes are kept (the failed-callback line of `SsoFailed` uses the same bound). Listeners run **inside the request**: a throwing listener of `SsoTokenIssued` stops the sign-in (fail closed), and one of `SsoIssueDenied` turns the denial into a 500, so keep them short and let them catch their own failures. Nothing of the token or of the caller's `state` is in an event or a line.

```php
use Illuminate\Support\Facades\Event;
use Martis\Events\SsoIssueDenied;
use Martis\Events\SsoTokenIssued;

Event::listen(SsoTokenIssued::class, function (SsoTokenIssued $event) {
    AuditLog::record('sso.issued', $event->user, ['audience' => $event->audience, 'request_id' => $event->requestId]);
});

Event::listen(SsoIssueDenied::class, function (SsoIssueDenied $event) {
    AuditLog::record('sso.issue_denied', $event->user, [
        'audience' => $event->audience,
        'reason' => $event->reason,
        'request_id' => $event->requestId,
    ]);
});
```

### 3. Panel B: the provider block

```bash
php artisan martis:sso panel-a --driver=signed-token
```

```php
// config/martis.php, auth.sso.providers
'panel_a' => [
    'enabled' => env('MARTIS_SSO_PANEL_A_ENABLED', false),
    'driver' => 'signed-token',
    'label' => 'Continue with Panel A',
    'authorize_url' => 'https://panel-a.example/martis/sso/issue/panel-b',
    'public_keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('MARTIS_SSO_PANEL_A_PUBLIC_KEYS', ''))))),
    'issuer' => 'https://panel-a.example',
    'audience' => 'panel-b',
    'max_age_seconds' => 60,
    'clock_skew_seconds' => 30,
    'role_claim' => 'roles',
    // 'environment' => 'production',   // optional second lock
    'identity_match_attribute' => 'external_id',
    // ...the usual role_strategy, auto_create_user, permission_adapter, on_no_role_match keys
],
```

`MARTIS_SSO_<NAME>_PUBLIC_KEYS` (the name in upper case, here `MARTIS_SSO_PANEL_A_PUBLIC_KEYS`) holds the public key, or several separated by commas during a rotation: that is the variable the generator writes into `.env` and the one `martis:sso:keys` names. Spaces around a key and empty entries are dropped.

The block may be named anything and there may be several: `driver => 'signed-token'` selects the built-in provider (`MartisSso::extend()` registrations keep precedence). The name must be lowercase, because routes and config keys are matched in lowercase.

| Key | Meaning |
|---|---|
| `authorize_url` | Panel A's issue route for this panel's audience. The redirect appends `audience` and `state` to it and sends nothing else. |
| `public_keys` | List of base64 Ed25519 public keys. Every one is accepted, which is how a key is rotated. At least one; a value that is not 32 bytes throws naming the key. |
| `issuer`, `audience` | The `iss` and `aud` the token must carry. |
| `max_age_seconds` | A token issued longer ago than this (plus the skew) is refused, whatever its `exp` says (default 60). |
| `clock_skew_seconds` | Clock difference tolerated for `exp` and `iat` (default 30). |
| `role_claim` | The claim that holds the role names (default `roles`). The roles feed the usual `role_strategy` mapping. |
| `environment` | Optional second lock: only tokens whose `env` claim equals `environment` are accepted. With no `environment`, a token that carries the `env` claim at all is refused (reason `environment`, the text says to set `environment`): the issuer is locked and this verifier is not (v2.22.0). `environment_claim` was removed: setting it throws. |

#### The columns panel B needs

The generated block does not work until panel B's database has what it reads and writes. Panel B owns these columns, no flag creates the first one:

- **`users.<name>_external_id`** (here `panel_a_external_id`), a nullable string, ideally unique. The `external_id` strategy stores the id of the token's `sub` there on the first sign-in and finds the user by it after that. A column of another name goes in `identity_external_id_column`. Add it in a migration of your own:

  ```php
  Schema::table('users', function (Blueprint $table) {
      $table->string('panel_a_external_id')->nullable()->unique();
  });
  ```

- **`roles.<name>_group_name`** (here `panel_a_group_name`), for the generated `'role_strategy' => 'column'` (the default of `--strategy`): the role whose column equals a role name in the token's `roles` claim is the one the user gets. `php artisan martis:sso panel-a --driver=signed-token --with-migration` publishes a migration that adds the column to an existing `roles` table (Spatie's, or yours); it refuses when there is none. The `config` and `callable` strategies read no column.

The command prints both in its list of next steps. A missing column is a refused sign-in (reason `unknown`, the database error in the log line); a roles column that exists but holds no role name the token sends refuses with `no_role_match`.

#### Which adapter writes the roles

`permission_adapter => 'auto'` (what the generator writes, or `spatie` with `--with-spatie`) picks the Spatie adapter when `spatie/laravel-permission` is installed and the native adapter (direct writes to `model_has_roles`) otherwise. It does **not** look at `MartisSso::syncRolesUsing()`: registering that callback changes nothing until the block says `'permission_adapter' => 'callable'`. An app with its own role tables sets `callable` and registers the callback, or the native adapter writes to a pivot it does not have.

The provider sets `externalId = sub`, so **set `identity_match_attribute = external_id`** (see [Matching by `external_id`](#matching-by-external_id-v2210)): the id at panel A is the stable identity, an email is not. A block with a missing or unusable key throws an exception that names it.

### Rotating the key

1. Run `php artisan martis:sso:keys` for a second pair.
2. On panel B add the new public key to `public_keys`, next to the old one. Deploy.
3. On panel A switch `MARTIS_SSO_ISSUER_PRIVATE_KEY` to the new secret key. New tokens are signed with it and still verify.
4. After `ttl_seconds` plus the skew (a couple of minutes) no token signed by the old key is alive: remove the old public key from panel B.

A token signed by a key that is not in `public_keys` is refused as `bad_signature`; the footer's `kid` only picks which configured key to try first, it never adds one.

### What the verifier checks

In this order, each refusal with its own reason (they reach the log line and the [`SsoFailed`](#failed-sign-ins-reason-log-and-event-v2210) event of the callback):

| Reason | Refused because |
|---|---|
| `malformed` | Not a `v4.public` token: wrong prefix, bad or non-canonical base64url, trailing data, too long, a footer or payload that is not a JSON object, a claim of the wrong type or a time that is not RFC 3339. |
| `bad_signature` | No configured public key verifies the signature (checked before any claim is read). |
| `missing_claim` | A required claim is missing or empty: `iss`, `aud`, `sub`, `iat`, `exp`, `jti`, `state`, `email`, `email_verified` (a boolean: any other type is `malformed`). |
| `issuer` | `iss` is not the configured `issuer`. |
| `audience` | `aud` is not the configured `audience`. |
| `expired` | `exp` has passed beyond the skew, or `iat` is older than `max_age_seconds` plus the skew. |
| `not_yet_valid` | `iat` is in the future beyond the skew. |
| `environment` | The verifier has an `environment` and the token's claim differs or is absent; or the verifier has none and the token carries the environment claim (the issuer is locked, this side is not: set `environment`). |
| `replayed` | The `jti` was already used. |
| `replay_store` | The cache failed while recording the `jti`: the token is refused, never let through. |
| `state` | The state in the browser's session (taken out of it once, whatever the outcome, and no older than 10 minutes since the redirect: v2.22.0, a fixed limit with no config key, so a sign-in slower than that starts over), the `state` of the callback URL and the `state` claim are not all the same. |

The `jti` is recorded (`Cache::add`, kept until the token could not be accepted any more) **before** the state is checked (v2.22.0), so a token that is validly signed, in time and meant for this audience is used up by its first arrival, whatever the state of that request. A token refused earlier in the list (bad signature, another audience or issuer, expired, another environment) is not recorded. Comparisons of the issuer, audience, environment and state are constant time.

### Security notes

- **The token is in a URL.** It travels in the query string of the callback URL, so it can reach browser history and any access log of that path. The mitigations are built in: it lives 60 seconds by default, is accepted once, is bound to the browser session that started the flow through `state`, and the issue response says `Referrer-Policy: no-referrer` and `Cache-Control: no-store`. Operators should keep query strings out of the access logs of `/{martis}/sso/{provider}/callback` (nginx: log `$uri` instead of `$request_uri` for that location; most load balancers can drop the query from the logged path).
- **The cache must be shared.** The one-time `jti` lives in Laravel's default cache store, or in the one `martis.auth.sso.cache_store` names ([The cache store](#the-cache-store-v2220)). With several servers use a shared store (Redis, database, Memcached); the `array` and `file` stores are per process or per host, and the `null` store refuses every sign-in as `replayed`. A cache that is down refuses sign-ins.
- **Panel B trusts what panel A signs about the person.** Two things, and neither is a flaw to patch: they are what a single sign-on is.
  - *The email.* Panel A signs the address its user row holds, and panel B may adopt a local row by it (`identity_match_attribute => 'email'`, or `external_id` with `link_by_email => true`). So that an attacker cannot register at panel A with an address they do not own and be signed in as its holder at panel B, the token carries `email_verified`, and panel B **refuses to adopt** an existing local row by an email that says `false` (reason `email_unverified`; creating a new user and matching by `external_id` are not affected). Panel A needs a user model that implements `MustVerifyEmail` for the claim to mean anything: without it the claim is always `true`. The default scaffold matches by `external_id` without `link_by_email`, which does not rely on the address at all.
  - *The roles.* With `role_strategy => 'column'` panel B maps the role **names** panel A signs onto its own roles. Whoever can assign roles at panel A, or write the `claims` class, controls which roles that user gets at panel B. Treat the right to assign roles at the issuing panel as a right at every panel that trusts it.
- **State fixation: a victim's browser can be made to fetch a token for the attacker's state.** The issue route is a `GET` the browser follows with its cookies, and a top-level navigation is what the legitimate flow is. So an attacker can start the flow at panel B in their own browser (state `S` in their session), then get a signed-in user of panel A to open `/{martis}/sso/issue/{audience}?state=S` (a link, a script redirect). Panel A signs for that user with `S` and redirects to the audience's `callback_url`; there the victim's browser holds no state, so the callback is refused as `state`, but the token is now in a URL that an access log, a proxy or a browser extension may hold, and whoever reads it could present it in the attacker's browser, where `S` matches, and be signed in as the victim.
  - *What the order now guarantees (v2.22.0).* The verifier uses up the `jti` before it looks at the state, so the first arrival of a validly signed, in-time token for this audience burns it, whatever the state of that request. The victim's own failed callback is that first arrival; a replay from a log finds it used (`replayed`). Before v2.22.0 the `jti` was recorded last, so a token refused for its state stayed good for its lifetime.
  - *What is left.* A party on the path who can **block** the victim's callback request (so the token never arrives) and read the URL can still use it in the attacker's browser before it expires. That needs a position on the victim's network or at panel B's edge, not just a leaked log. Keep `ttl_seconds` short (the default is 60), and keep query strings out of the access logs and of the `Location` header logs for `/{martis}/sso/issue/*` and `/{martis}/sso/{provider}/callback` (nginx: log `$uri` instead of `$request_uri` for those locations; most load balancers can drop the query from the logged path).
  - *Why the package cannot require `Sec-Fetch-Site`.* The legitimate flow goes from panel B to panel A, two hosts, so the navigation to the issue route is itself `cross-site` whenever the panels are on different sites; refusing `cross-site` would refuse every sign-in. An app whose two panels share a site (two subdomains of one registrable domain) may refuse `Sec-Fetch-Site: cross-site` on the issue route in a middleware of its own.
  - *Why PKCE or a state that panel B signs would not help.* The attacker starts the flow, so they hold the PKCE verifier and they can produce a valid signed state for their own session. Both proofs would be in the attacker's hand when the token is replayed. The one-time `jti` is what ends the window.
  - If an audience should not be reachable this way at all, give it an `ability` the user must hold deliberately, or do not issue to it.
- **Revocation does not cross panels.** `MartisSso::revokeFor()` on panel A ends A's SSO sessions; it does **not** end the sessions panel B opened from A's tokens (the signed-token provider does not implement `RevalidatesSessions`), and on panel B it ends B's sessions but the middleware then sends the person to `/{martis}/sso/{name}/redirect`, which signs them straight back in for as long as their session at panel A (or at the IdP, for Azure) lives. Revoke at **every** layer: the IdP or the issuing panel first (`revokeFor()` on A, which also drops A's sessions), then `revokeFor()` on each consuming panel; set `max_session_minutes` on B so a token cannot keep a session going longer than you accept.
- **The private key is the trust root.** Whoever holds it can sign in to panel B as anyone panel B links by `external_id`. Keep it in the environment of panel A only, give it its own value per environment, and rotate it as above. Panel B holds public keys only. Use the `environment` lock on **both** sides so a staging key (or token) cannot sign in to production: the issuer adds the claim, the verifier refuses what does not match, and since v2.22.0 a pair where only the issuer is locked stops signing in until the verifier sets `environment` too, instead of silently ignoring the lock.
- **Use HTTPS.** `authorize_url` and `callback_url` must be `https`, except `localhost`, `*.localhost`, `*.test`, `127.0.0.1` and `[::1]` for local development.
- **Clocks.** Keep both servers on NTP; `clock_skew_seconds` is the tolerance and `max_age_seconds` the window.
- **One flow per browser session.** Starting a second flow in another tab replaces the state of the first, which then fails as `state`.
- Provider blocks, including `public_keys`, are server-side config and are not published to the browser.

### Tests

The codec is checked against the official `4-S-*` (sign and verify, byte for byte) and `4-F-*` (must fail) vectors of the PASETO test-vector repository, kept in `tests/Fixtures/Paseto/v4-public-vectors.json`. See the [test coverage](#17-test-coverage) table for the specs of the rest.
