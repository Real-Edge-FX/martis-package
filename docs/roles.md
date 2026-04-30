# Roles & Permissions Admin

Martis ships a one-shot generator that scaffolds an admin UI for users, roles, and permissions on top of [`spatie/laravel-permission`](https://spatie.be/docs/laravel-permission). The generated resources land in your app's `app/Martis/Resources/` directory and live in the **System** sidebar group alongside the audit log and the Cache admin link.

## TL;DR

```bash
php artisan martis:roles
```

The command:

1. Runs `composer require spatie/laravel-permission` if it is missing.
2. Publishes Spatie's config + migrations and runs them.
3. Adds the `HasRoles` trait to your `User` model.
4. Scaffolds three resources: `UserResource`, `RoleResource`, `PermissionResource`.
5. Generates three policies: `UserPolicy`, `RolePolicy`, `PermissionPolicy` (admin-only by default).
6. Registers the policies in `App\Providers\AuthServiceProvider`.
7. Emits a seeder (`MartisRolesSeeder`) that creates the `admin` role.

After it finishes:

```bash
php artisan db:seed --class=MartisRolesSeeder
```

Then promote yourself in `tinker`:

```php
\App\Models\User::where('email', 'you@example.com')->first()->assignRole('admin');
```

You should now see a **System** entry in the Martis sidebar with **Users**, **Roles**, **Permissions**, the **Audit log**, and the **Cache** admin all in one place.

## Why a generator instead of bundled resources

The User model lives in your app, not in the package. Multi-tenant scoping, custom guards, password-less SSO accounts, and the question of which fields you want exposed in the admin UI all vary too much to ship a one-size-fits-all resource. The generator gives you the working baseline; the files belong to you and stay editable.

Spatie itself is intentionally a soft dependency. If you do not run `martis:roles`, the package never loads Spatie classes, and you can keep using a custom permission stack.

## Command flags

| Flag | Purpose |
|---|---|
| `--user=` | Fully-qualified User model class. Default: `App\Models\User`. |
| `--namespace=` | Namespace for the generated resources. Default: `App\Martis\Resources`. |
| `--no-install` | Assume Spatie is already installed. Skips the `composer require` step. |
| `--no-migrate` | Publish migrations but do not run them. Useful when other migrations are pending review. |
| `--no-publish-spatie` | Skip publishing Spatie's config + migrations. Use after a manual publish. |
| `--force` | Overwrite resource / policy files that already exist. |

The command is idempotent — re-running it without `--force` skips files already on disk.

## What the generated UI looks like

Each resource lands in the **System** sidebar group via `belongsToSystemSection() === true`. The group also contains the audit log (`Martis\Resources\ActionEventResource`) and the Cache admin link.

### `UserResource`

- Index columns: `id`, `name`, `email`, `email_verified` badge, `created_at`.
- Detail / edit form: `name`, `email`, `Roles` BelongsToMany picker.
- The Roles picker excludes any role with a non-null `provider_group_name` (set by `martis:sso ... --with-migration`). Those roles are owned by the IdP and would be overwritten on the next sign-in. The field's help text explains the behaviour to the operator.

### `RoleResource`

- Index columns: `id`, `name`, `guard_name`, count of users, count of permissions.
- Detail / edit form: `name`, `guard_name`, optional `provider_group_name` (read-only when the SSO migration ran), `Permissions` BelongsToMany picker.

### `PermissionResource`

- Index columns: `id`, `name`, `guard_name`, count of roles.
- Detail / edit form: `name`, `guard_name`, `Roles` (read-only relation).

### Recommended fields (v1.8.0)

The default scaffold uses `Text::make('name')` and `Text::make('guard_name')` — fast to wire, but typo-prone. Two newer fields surface stronger guarantees with no breaking change:

| Original | Replacement | Why |
|---|---|---|
| `Text::make('name')` | `Slug::make('name')->separator('.')->reserved(['*'])` | Live preview, transliteration (`São Paulo` → `sao.paulo` with separator `.`), reserved-word guard, locked once the row exists. The dot separator matches the Spatie `dashboard.home.view` convention; pass `->separator('-')` for kebab style. |
| `Text::make('guard_name')` | `GuardSelect::make('guard_name')` | Dropdown populated from `config('auth.guards')` at schema-render time — eliminates `'web'` typos. Default value comes from `config('auth.defaults.guard')`. Subset with `->only(['web', 'api'])` if a Resource only manages one guard. |

Example PermissionResource form:

```php
use Martis\Fields\GuardSelect;
use Martis\Fields\ID;
use Martis\Fields\Slug;

public function fields(Request $request): array
{
    return [
        ID::make()->sortable(),

        Slug::make('name')
            ->separator('.')
            ->reserved(['*'])              // wildcard sentinel
            ->help(__('martis::permissions.name_help'))
            ->required(),

        GuardSelect::make('guard_name')
            ->help(__('martis::permissions.guard_help'))
            ->required(),
    ];
}
```

#### Why does `guard_name` exist on BOTH Permission and Role?

Spatie enforces that a Role can only carry Permissions of the same guard. If your app has `web` and `api` guards, a Role `admin` registered against `web` cannot receive a Permission registered against `api` — Spatie rejects the assignment at runtime. `guard_name` ties each row to one guard so the integrity check has something to compare. **99% of apps run on a single guard (`web`)**, in which case the field is just paperwork — set it once, never look at it again. Multi-guard setups are rare and typically split admin (`web`) from a public API (`api`).

#### Meta endpoint (v1.8.0)

The same guard list is also served as JSON at `GET /martis/api/_meta/guards` (auth-protected). Useful when you build a custom admin UI outside the Martis Resource layer:

```json
{
  "guards": ["api", "web"],
  "default": "web"
}
```

## Customising the resources

Open the file under `app/Martis/Resources/UserResource.php` (or wherever you scaffolded it) and edit. The package never reads these files directly — Martis loads them through the registry, which means you have full control:

- Add `Boolean::make('Subscription tier', ...)` if your app has tiered access.
- Tighten the policy methods to scope per-tenant.
- Drop the `Permissions` resource from the sidebar entirely if your app only ever assigns roles, never raw permissions.
- Swap the icon, add filters, attach lenses.

The generator never re-touches a file once it exists. Re-run with `--force` only when you want a fresh scaffold and have backed up your custom changes.

## Using permissions in your code

Permissions are pure DB rows. The Spatie helpers read them on demand — there is no separate registration step. Once a row exists in the `permissions` table and is attached to one of the user's roles (via `role_has_permissions` + `model_has_roles`), the standard Laravel authorisation primitives work:

```php
// Inside any controller / action
if ($request->user()->can('posts.publish')) { ... }

// Throwing variant — 403 if the check fails
$this->authorize('posts.publish');

// Direct on the user
$user->givePermissionTo('posts.publish');     // attach
$user->revokePermissionTo('posts.publish');   // detach
$user->hasPermissionTo('posts.publish');      // check

// Roles
$user->assignRole('editor');
$user->hasRole('editor');
```

### Gating a Martis Resource by a permission

The cleanest path is a Policy — the resource layer calls `Gate::allows(...)` and Laravel resolves the policy method automatically:

```php
// app/Policies/PostPolicy.php
public function viewAny(User $user): bool
{
    return $user->can('posts.view');
}

public function create(User $user): bool
{
    return $user->can('posts.create');
}

public function update(User $user, Post $post): bool
{
    // Owner exception — authors can always edit their drafts.
    if ($post->author_id === $user->id && $post->status === 'draft') {
        return true;
    }
    return $user->can('posts.update');
}

public function delete(User $user, Post $post): bool
{
    return $user->can('posts.delete');
}
```

Register the policy once in `App\Providers\AuthServiceProvider`:

```php
protected $policies = [
    \App\Models\Post::class => \App\Policies\PostPolicy::class,
];
```

That single registration covers the index page (sidebar visibility, list endpoint), the detail page, the create form, the edit form, and the delete button — Martis calls every gate before rendering each.

### Gating a route

```php
Route::get('/admin/reports', ReportController::class)
    ->middleware(['martis.auth', 'can:reports.view']);
```

### Gating an Artisan command

```php
if (! Auth::user()->can('exports.run')) {
    $this->error('Permission denied.');
    return Command::FAILURE;
}
```

### Where the permission strings come from

Whatever you typed (or scaffolded) in the Permission admin page. The string is just a label your code agrees on; no enum, no registry. A common pattern: `<resource>.<action>` (`posts.publish`, `users.delete`) or the Spatie dot-notation seeded by `Slug::make('name')->separator('.')`. Pick a convention and stick to it — typos here become silent permission failures, so a Slug-backed input on the admin form is worth the few minutes it saves.

## Customising the policies

The generated policies all return `$user->hasRole('admin')` from every method. Tighten or relax as needed:

```php
// app/Policies/UserPolicy.php
public function update(User $actor, User $target): bool
{
    if ($actor->hasRole('super-admin')) {
        return true;
    }

    // Admins cannot edit other admins.
    return $actor->hasRole('admin') && ! $target->hasRole('admin');
}
```

## Interaction with SSO group sync

When `martis:sso azure --with-spatie --with-migration` ran in the same project, Spatie roles gain a `provider_group_name` column (see [`docs/sso.md`](sso.md#7-spatie--laravel-permission-integration)). The generated `UserResource` filters out any role tied to an SSO provider from its picker, because:

1. The IdP owns those roles. The next sign-in would overwrite any manual change.
2. The operator should grant local-only roles via the admin UI; SSO-driven roles flow in automatically.

If you want to display (read-only) which SSO roles a user has, add a computed `Text` field at the top of the User resource that reads from the relation:

```php
Text::make('SSO roles', fn ($user) => $user->roles
    ->whereNotNull('provider_group_name')
    ->pluck('name')
    ->implode(', ') ?: '—'
)
    ->onlyOnDetail()
    ->help('Synced from your IdP — managed via group claims, not editable here.'),
```

## Where the System section comes from

`Martis\Http\Controllers\NavigationController` builds the sidebar by iterating the `ResourceRegistry`. Resources whose `belongsToSystemSection()` returns `true` are pulled out of the regular grouping loop and merged with the Cache admin link into a single **System** menu section. The section appears whenever there is at least one item visible to the current user — no items, no section.

Add your own resources to this section by overriding the method:

```php
class TenantResource extends Resource
{
    public function belongsToSystemSection(): bool
    {
        return true;
    }
}
```

The label "System" is published via `martis::messages.system`. Override it via `vendor:publish --tag=martis-lang` for per-locale customisation.

## Removing the generated UI

The generated files are yours, so nothing in the package keeps them alive. To remove the surface entirely:

```bash
rm -rf app/Martis/Resources/{User,Role,Permission}Resource.php
rm app/Policies/{User,Role,Permission}Policy.php
rm database/seeders/MartisRolesSeeder.php
```

Remove the `Gate::policy(...)` block from `App\Providers\AuthServiceProvider::boot()` and (optionally) the `HasRoles` trait from your User model.

## Next steps

- [Authorization](authorization.md) — policy patterns and gate basics.
- [SSO](sso.md) — wire Azure AD, Google, or a custom IdP and let it manage a subset of roles.
- [Resources](resources.md) — extend the generated User / Role / Permission classes with fields, filters, lenses.
