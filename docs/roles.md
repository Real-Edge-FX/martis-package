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
8. **Auto-runs the seeder** so the `admin` role exists immediately (v1.8.15+ — opt out with `--no-seed`).

To promote yourself in the same call, pass `--promote=`:

```bash
# Promote a known email
php artisan martis:roles --promote=you@example.com

# Or promote the lowest-id user (typical fresh-install scenario)
php artisan martis:roles --promote=first
```

Without the flag, you still get the manual fallback in `tinker`:

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
| `--with-categories` | Adds a `category` column to `permissions` (via published migration) and surfaces it as a field + filter on the generated PermissionResource. Useful for apps with 50+ permissions. v1.8.8. |
| `--promote=` | Email of the user to seed + promote to admin in the same call. Pass `first` to grab the lowest-id user. Skips the manual `assignRole('admin')` step. v1.8.15. |
| `--no-seed` | Skip running `MartisRolesSeeder` after scaffolding. Default behaviour: seed runs automatically. v1.8.15. |
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

### Refresh the permission cache

Spatie caches permissions and roles in the application cache (default TTL 24 h) for performance. When you assign or revoke permissions outside the admin UI — from a seeder, a tinker session, a bulk-import script — the cache does not know and authorization checks may stay on the previous state until the TTL expires.

Reset the cache after any out-of-band write:

```bash
php artisan permission:cache-reset
```

The Martis admin UI calls this automatically after every Role / Permission update from the Resource forms, so the manual command is only needed when you bypass the UI.

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

The exclusion is a single `relatableQuery()` clause inside the BelongsToMany picker — the stub renders it as:

```php
BelongsToMany::make(__('Roles'), 'roles', RoleResource::class)
    ->relatableQuery(fn (Builder $query) => $query->whereNull('provider_group_name'))
    ->help(__('martis::permissions.user_roles_help')),
```

Replicate the same pattern in any custom Resource that exposes the role relation (e.g. a TenantResource that scopes roles per tenant). Drop the clause to expose every role, or tighten it (`->whereNull('provider_group_name')->where('active', true)`) to add app-specific predicates.

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

## Permission categories (`--with-categories`)

Apps with 50+ permissions get unwieldy in a flat index list. Run the generator with `--with-categories` to opt in to a tiny addition: a nullable `category` column on `permissions` plus a Field + SelectFilter on the generated `PermissionResource`.

```bash
php artisan martis:roles --with-categories
```

Effects:

- Publishes `database/migrations/{ts}_add_category_column_to_permissions_table.php` (re-runs are idempotent — only the first publish writes; subsequent runs skip with a yellow `already published` row).
- Renders `Text::make('Category', 'category')` inside the PermissionResource fields list.
- Renders a `filters()` method on the resource that surfaces every distinct category currently in use as a SelectFilter on the index page.

The column is **metadata only** — Martis never reads `permissions.category` for authorization. The string is just a UX label so the operator can group `posts.publish`, `posts.draft`, `posts.unpublish` under "Posts" and the admin index renders them grouped.

To add categories to existing scaffolds without re-running the generator: copy the field + filter snippets from `vendor/martis/martis/stubs/roles-permission-resource.stub` and re-run `php artisan migrate` after publishing the migration with `php artisan martis:stubs` (you can also write the migration by hand against your existing schema — the column is `string('category', 64)->nullable()->index()`).

To remove the feature: drop the column from `permissions`, remove the field + filter from your `PermissionResource`. No code reads the column.

## Audit log of role / permission changes

Spatie 5+ fires `RoleAttachedEvent`, `RoleDetachedEvent`, `PermissionAttachedEvent`, and `PermissionDetachedEvent` whenever a `HasRoles` model has its grants changed (`assignRole`, `removeRole`, `syncRoles`, `givePermissionTo`, `revokePermissionTo`, …). Since v1.8.8 Martis subscribes a default listener — `Martis\Auth\Listeners\RecordRoleChange` — that records each event into the `martis_action_events` audit log:

| Action name | Fires when |
|---|---|
| `role.attached` | Spatie role attached to a model |
| `role.detached` | Spatie role detached from a model |
| `permission.attached` | Spatie permission attached directly to a model (rare; usually flows via roles) |
| `permission.detached` | Spatie permission detached directly from a model |

Each row carries the acting user (from the active session, or `null` for system-level writes), the target model FQCN + id, and the list of role / permission ids in the `fields.ids` JSON column. Browse the log under `/martis/system/action-events`.

The listener is gated on a single config knob:

```dotenv
# Set false to silence the Martis-side audit row entirely. Your own
# listeners keep firing — this only stops the action_events write.
MARTIS_AUDIT_ROLE_CHANGES=false
```

When the audit table is missing (apps that opted out of the v0.7 install migrations) the listener short-circuits — the role change still happens, the audit row is just skipped. Same when Spatie is not installed: the listener is never registered.

## Bulk-assign roles to many users at once

The generated `UserResource` now ships an action — `BulkAssignRole` — that exposes a single Role dropdown in its confirmation modal and assigns the chosen role to every selected user in one click. The action lives at `app/Martis/Resources/Actions/BulkAssignRole.php`; it is yours to customise (rename the role pool, broadcast a notification, deny self-assignment, etc.).

The Role picker excludes any role whose `provider_group_name` is set — those are owned by an SSO provider and would be overwritten on the next sign-in. Drop the clause in the action's `fields()` method to expose every role, or tighten the predicate further to scope per tenant.

Each `assignRole()` call inside the action fires Spatie's `RoleAttachedEvent`, which the audit listener captures into `martis_action_events`. A bulk run of 50 users assigning `editor` produces 50 audit rows tagged with the operator and the role id — perfect for compliance review.

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

## Laravel 11+ note

On Laravel 11+, `App\Providers\AuthServiceProvider` no longer ships by default. The command emits a warning + manual instructions; register the policies inside any service provider's `boot()` method:

```php
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

public function boot(): void
{
    Gate::policy(User::class, UserPolicy::class);
    Gate::policy(Role::class, RolePolicy::class);
    Gate::policy(Permission::class, PermissionPolicy::class);
}
```

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
