# SSO Subsystem (⭐ Martis differential)

> Pluggable single sign-on with Azure AD, Google, GitHub (and any custom provider) — config-driven, role-mapping built-in, Spatie/laravel-permission integration auto-detected, and a one-line generator (`martis:sso azure`).

Martis ships with first-class SSO support for the same reasons it ships notifications and cache: every admin panel needs it, but most either rebuild it from scratch (200-line custom controllers) or hard-couple to one provider. The Martis subsystem keeps each concern separately configurable.

## What's differentiated

| Feature | Martis | Typical Laravel admin |
|---|---|---|
| Per-provider config (multiple SSO active in parallel) | ✅ | ⚠ usually one provider only |
| Role mapping in 3 strategies (column / config / callable) | ✅ | ❌ DIY |
| Spatie/laravel-permission auto-detect | ✅ | ⚠ DIY |
| Per-environment role overrides via env | ✅ (resolved through Laravel env) | ⚠ DIY |
| Generator command scaffolding the full provider | ✅ `martis:sso <name>` | ❌ |
| Hook surface for app-side overrides | ✅ 5 hooks | ⚠ DIY |
| Login button auto-renders from config | ✅ | ❌ DIY UI |
| Cross-type isolation when an app has both Spatie + native | ✅ adapter selectable | n/a |

## Architecture

```
HTTP
  GET /{martis}/sso/{provider}/redirect   → SsoController::redirect
  GET /{martis}/sso/{provider}/callback   → SsoController::callback

SsoManager
  ├─ providers registry (azure → AzureProvider, google → ..., github → ...)
  ├─ adapter resolver (auto / spatie / native / callable)
  └─ host-app hooks (resolveUserUsing, resolveRolesUsing,
                     syncRolesUsing, afterLogin, onNoRoleMatchUsing)

SsoProviderContract  ← AzureProvider, GoogleProvider, GitHubProvider
  ├─ redirect()             — Socialite kick-off
  └─ resolveIdentity()      — exchange code, return SsoIdentity

IdentityResolver           — find-or-create user (email | external_id | hook)
RoleMapper                 — 3 strategies (column | config | callable | hook)
PermissionAdapter          — SpatieAdapter | NativeAdapter | CallableAdapter
```

## Quick start

### Azure AD in 90 seconds

```bash
composer require laravel/socialite socialiteproviders/microsoft

php artisan martis:sso azure --with-spatie --with-migration
```

Then:

1. Open Azure portal → App registrations → New registration.
2. Set Redirect URI: `https://your-app.test/martis/sso/azure/callback`.
3. Copy Application (client) ID → `AZURE_CLIENT_ID` and `AZURE_RESOURCE_ID`.
4. Generate a client secret → `AZURE_CLIENT_SECRET`.
5. Add `MicrosoftExtendSocialite` listener in `AppServiceProvider::boot()`:

```php
use SocialiteProviders\Manager\SocialiteWasCalled;

Event::listen(SocialiteWasCalled::class, [
    'SocialiteProviders\\Microsoft\\MicrosoftExtendSocialite@handle',
]);
```

6. Run the published migration: `php artisan migrate`.
7. Reload `/login` — the **Continue with Microsoft** button is there.

That's it. New users from your Azure tenant log in, get auto-provisioned, and receive the Spatie roles whose `azure_group_name` matches their assigned app role's display name.

## Config reference

```php
'auth' => [
    'sso' => [
        'enabled' => env('MARTIS_SSO_ENABLED', false),

        'providers' => [
            'azure' => [
                'enabled'        => env('MARTIS_SSO_AZURE_ENABLED', false),
                'driver'         => 'azure',
                'label'          => 'Continue with Microsoft',
                'icon'           => 'microsoft-outlook-logo',
                'scopes'         => ['openid', 'profile', 'email', 'GroupMember.Read.All'],

                // Where external roles come from.
                'role_source'    => 'app_role_assignments',  // 'groups' | 'app_role_assignments' | 'callable'
                'resource_id'    => env('AZURE_RESOURCE_ID'),

                // How to map external roles to local roles.
                'role_strategy'  => 'column',                // 'column' | 'config' | 'callable'
                'role_column'    => 'azure_group_name',
                'role_model'     => null,                    // null = auto-detect Spatie/App\Models\Role

                // Identity resolution.
                'auto_create_user'         => true,
                'identity_match_attribute' => 'email',       // 'email' | 'external_id'
                'sync_user_attributes'     => ['name', 'email'],

                // Permission sync.
                'sync_roles'         => true,
                'permission_adapter' => 'auto',              // 'auto' | 'spatie' | 'native' | 'callable'

                // Failure handling.
                'on_no_role_match' => 'deny',                // 'deny' | 'guest' | callable
                'redirect_to'      => null,                  // null = config('martis.path')
            ],
        ],
    ],
],
```

## The 3 axes (compose freely)

### `role_source` — where the external role list comes from

| Value | Provider behaviour |
|---|---|
| `groups` | Calls `/users/{id}/memberOf` on Microsoft Graph. Returns group `displayName`s. Coarser. |
| `app_role_assignments` | Calls `/users/{id}/appRoleAssignments?$filter=resourceId eq {resource_id}`. Returns `principalDisplayName`. Recommended. |
| `callable` | Defers to `role_source_callable` (config) — host app does the IdP call. |

### `role_strategy` — how external names → local roles

| Value | Behaviour |
|---|---|
| `column` (default) | `Role::query()->whereIn($role_column, $externalNames)->get()`. The host app stores the IdP group name on the roles table. |
| `config` | `role_map` in config maps `local_slug => env_value`. The mapper finds local roles whose name = slug for each env_value present in the external list. |
| `callable` | Defers to `role_callable` closure declared in the provider config. |

### `permission_adapter` — how roles get written onto the user

| Value | Behaviour |
|---|---|
| `auto` (default) | Uses `SpatieAdapter` when `spatie/laravel-permission` is installed, `NativeAdapter` otherwise. |
| `spatie` | Calls `$user->syncRoles($collection)` (the Spatie trait method). |
| `native` | Direct attach/detach against `model_has_roles`. Configurable table and column names. |
| `callable` | Defers to `MartisSso::syncRolesUsing(fn ($user, $roles) => ...)`. |

### Four canonical recipes

#### Recipe A — Azure + Spatie (most common)

```php
'azure' => [
    'role_source'        => 'app_role_assignments',
    'role_strategy'      => 'column',
    'role_column'        => 'azure_group_name',
    'permission_adapter' => 'spatie',
],
```

#### Recipe B — Google Workspace + config map + native pivot

```php
'google' => [
    'role_source'        => 'callable',
    'role_strategy'      => 'config',
    'role_map'           => [
        'admin'  => 'workspace-admins@acme.com',
        'editor' => 'editors@acme.com',
    ],
    'permission_adapter' => 'native',
],
```

#### Recipe C — GitHub teams via host-app callable

```php
'github' => [
    'role_source'        => 'callable',
    'role_strategy'      => 'callable',
    'permission_adapter' => 'callable',
    'auto_create_user'   => false,
],
```

#### Recipe D — Custom Okta / SAML (hypothetical)

```php
'okta' => [
    'driver'             => 'okta-saml',
    'role_source'        => 'saml_attribute',
    'role_strategy'      => 'column',
    'role_column'        => 'okta_group_dn',
    'permission_adapter' => 'spatie',
    'sync_user_attributes' => ['name', 'email', 'department'],
],
```

## Host-app hooks

Five hook points exposed via the `MartisSso` facade. Wire them up in `app/Providers/MartisServiceProvider.php`:

```php
use Martis\Sso\Facades\MartisSso;
use Martis\Sso\SsoIdentity;

protected function registerSso(): void
{
    // 1. Replace user resolution entirely.
    MartisSso::resolveUserUsing(function (SsoIdentity $identity, string $provider): \App\Models\User {
        return \App\Models\User::query()->withoutGlobalScopes()->updateOrCreate(
            ['email' => $identity->email],
            ['name' => $identity->name, 'auth_method' => 'azure'],
        )->tap(fn ($u) => $u->trashed() && $u->restore());
    });

    // 2. Replace role resolution entirely.
    MartisSso::resolveRolesUsing(function (array $externalRoles, $user, string $provider) {
        return \App\Models\Role::query()->whereIn('azure_group_name', $externalRoles)->get();
    });

    // 3. Replace role-sync entirely.
    MartisSso::syncRolesUsing(function ($user, $roles) {
        $user->syncRoles($roles);
        $user->updateMainRole($roles->first(), $user);
    });

    // 4. Side effects after a successful login.
    MartisSso::afterLogin(function ($user, SsoIdentity $identity, string $provider) {
        AuditLog::record('sso.login', $user, ['provider' => $provider]);
    });

    // 5. Custom denial handling.
    MartisSso::onNoRoleMatchUsing(function (SsoIdentity $identity, string $provider) {
        return redirect('/login')->withErrors([
            'sso' => __('Account not provisioned for :app', ['app' => config('app.name')]),
        ]);
    });
}
```

Hooks compose with config — you can use `resolveUserUsing` while keeping the built-in `RoleMapper` (`role_strategy = 'column'`).

## REST surface

Two endpoints, both registered only when `auth.sso.enabled = true`.

| Method | Path | Description |
|---|---|---|
| `GET` | `/{martis}/sso/{provider}/redirect` | Kick off OAuth flow. Throttled (`login_attempts/login_minutes`). |
| `GET` | `/{martis}/sso/{provider}/callback` | Handle IdP callback. Resolves identity → roles → user → login. |

Both routes are unauthenticated (the user isn't logged in yet).

## Generator command

```bash
php artisan martis:sso <provider> [--with-spatie] [--with-migration] [--strategy=column|config|callable] [--no-auto-create-user] [--custom]
```

Provider names with built-in scaffolding: `azure`, `google`, `github`. Pass `--custom` for a generic provider you'll wire via `MartisSso::extend(...)`.

## Migration from a custom OAuth controller

Apps that already have a custom `AzureOauthController` (often extending Nova's) typically have ~200 lines covering: Socialite scopes, Microsoft Graph appRoleAssignments fetch, role match-or-deny, find-or-create-user with soft-delete restore, role attach/detach, custom `main_role` flag, type derivation.

The Martis subsystem replaces all of it with config + hooks:

```php
// app/Providers/MartisServiceProvider.php
protected function registerSso(): void
{
    MartisSso::resolveUserUsing(fn (SsoIdentity $id, $provider) =>
        User::query()->withoutGlobalScopes()->updateOrCreate(
            ['email' => $id->email],
            ['name' => $id->name, 'auth_method' => AuthMethodEnum::AZURE->value],
        )->tap(fn ($u) => $u->trashed() && $u->restore()),
    );

    MartisSso::afterLogin(function (User $user, SsoIdentity $id, $provider) {
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

Plus the config block (10 lines), and the migration adding `azure_group_name` to roles. Total: ~30 lines vs 200.

## Login UI

The Login page reads `auth.sso.providers` and renders one button per enabled entry. Label and icon come from the provider config. Click → redirect to `/sso/{provider}/redirect`.

When `auth.sso.enabled = false`, no buttons render, no routes register.

## Diagnostic checklist

| Symptom | Likely cause | Fix |
|---|---|---|
| Button doesn't appear on Login | `enabled = false` on master or provider | Set `MARTIS_SSO_ENABLED` and `MARTIS_SSO_<NAME>_ENABLED` |
| Click button → "This sign-in method is not available" | Provider not registered | Confirm `martis:sso <name>` ran successfully |
| Callback errors out generically | Socialite missing OR provider extension not registered | `composer require laravel/socialite socialiteproviders/microsoft` + register listener |
| User created but no roles assigned | `role_strategy = column` but `azure_group_name` column missing on `roles` | `php artisan migrate` after `--with-migration` |
| Roles don't get written | `permission_adapter = spatie` but Spatie not installed | `composer require spatie/laravel-permission` or change adapter |
| Login bounces with "Account not provisioned" | `auto_create_user = false` and user doesn't exist locally | Set `auto_create_user = true` or pre-create users |

## Test coverage

- `tests/Unit/Sso/SsoIdentityTest.php` (2)
- `tests/Unit/Sso/RoleMapperTest.php` (6) — every strategy + global override
- `tests/Feature/SsoControllerTest.php` (8) — callback flow, auto-create, role mapping, deny, guest, hook firing, route gating
