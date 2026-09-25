# Upgrading

What to do in an app when a 1.x release changes behaviour it may rely on. A patch release changes behaviour only to close a security gap or a bug; each section says what changed, who is affected and what to check.

## Upgrading to v1.39.3

v1.39.3 is a security release. After `composer update martis/martis`, in every environment:

```bash
php artisan martis:publish-assets
php artisan martis:cache:clear
```

`martis:cache:clear` matters on 1.x: the cache keys carry no package version and the `schema` layer keeps its entries with no expiration by default, so without it the cached resource schemas keep the relationship panel flags of the previous version (see [A write through a relationship needs the related `viewAny`](#a-write-through-a-relationship-needs-the-related-viewany)).

Then read the sections below that apply to your app: most are security fixes that refuse something a user could do before. Two things this release does not fix are in [Known issues](#known-issues-in-v1393).

### Metric results are cached per user

Both metric cache paths (the `metrics` layer and a per-class `cacheFor()`) keyed the entry without the user, so a `calculate()` scoped to the user, their tenant or their permissions served the first user's value to everyone for the TTL. The key now carries the authenticated user (the Martis guard's), by model class and identifier; guests share one entry.

- Every key changes: no previous entry is reused, and each user's first request computes the metric again.
- A metric that is the same for everyone is now computed and stored once per user. Raise `MARTIS_CACHE_METRICS_TTL` or its `cacheFor()` lifetime, or set `protected bool $cachePerUser = false;` on it (only when its `calculate()` reads nothing of the user). See [Cache](cache.md#the-four-built-in-layers).

### Actions run only on the records the index lists

An action run (bulk, single record, inline, lens or queued) looks its selected ids up through the resource's `scopes()`, then `indexQuery()`, as the index lists them, with an `orWhere()` in them grouped before the ids are added.

- A record `scopes()` keeps out is no longer processed; a run none of whose ids resolve answers `404` (`One or more selected resources could not be found.`) instead of running `handle()` on nothing, pivot actions included.
- An action that is not `standalone()` posted without `resources` answers `422` (`resources`): send the ids, or declare the action `standalone()`.
- A `standalone()` action receives an empty collection whatever ids the request names: query the records it needs itself.

See [Actions → Execution Modes](actions.md#execution-modes).

### A write through a relationship needs the related `viewAny`

`POST`, `PUT` and `DELETE` on the `has-many`, `has-one`, `morph-many` and `morph-one` endpoints now require the related resource's `viewAny`, as its own endpoints and Nova do, and answer `403` without it; the `HasMany`, `HasOne`, `MorphMany` and `MorphOne` panels no longer offer Create, Edit, Delete, Restore or Force delete to that user (they still list the records). If that user should write those records, grant `viewAny` on the related resource and confine what they see with `indexQuery()`. The panel flags live in the cached schema: run `php artisan martis:cache:clear schema` (or `martis:cache:clear`) after upgrading. See [Authorization → `viewAny` is the entry gate](authorization.md#viewany-is-the-entry-gate).

### A publish keeps your themes

`martis:publish-assets` (and `martis:install`, `martis:vendor-publish --assets`, which run it) deleted the whole `public/vendor/martis/` since v1.8.8, `themes/<name>.css` included, so each upgrade took a custom theme away. It now deletes only what the package publishes, and copies `resources/css/martis/<name>.css` to `public/vendor/martis/themes/` when that copy is missing (never over an existing one). A symlinked `public/vendor/martis` becomes a real directory at the next publish.

**A theme an earlier publish deleted does not come back by itself.** To recover it:

1. Restore `public/vendor/martis/themes/<name>.css` from version control or a backup (the edited copy, if you edited the published file).
2. Copy it to `resources/css/martis/<name>.css` as well (create the folder if needed), and make your edits there from now on: that source is what a publish restores a missing copy from.
3. Run `php artisan martis:publish-assets`, and check that `config('martis.theme.name')` (`MARTIS_THEME_NAME`) still names it.

If neither version control nor a backup has it, `php artisan martis:theme <name>` scaffolds a new theme to redo the edits in (with `--force` it overwrites an existing one). See [Theming → Theme files and asset publishes](theming.md#theme-files-and-asset-publishes).

### Commands ask only on a terminal

`martis:install`, and every other Martis command that asks (the generators' "Overwrite?", `martis:user`, `martis:agents`, `martis:sso`'s role mapping, the "Run pending migrations now?" of `martis:invitations`, `martis:roles` and `martis:sso`), asks a question only when the input is interactive **and** stdin is a real TTY. Through a pipe or `docker compose exec -T` every command does what it does with `--no-interaction`:

- `martis:install` resolves every optional feature you pass no flag for to disabled, takes `profile_picture` as the avatar column unless you pass `--avatar-column`, and `--existing-avatar-column` needs `--avatar-column`;
- a generator leaves an existing file alone unless you pass `--force`, with the line and exit code it had (`martis:component` and `martis:theme` exit 1, `martis:card`, `martis:field` and `martis:tool` exit 0);
- `martis:user` needs `--email` and `--password` (it exits 1 naming the missing one and creates no user); `--name` defaults to `Martis Admin`;
- the scaffold commands run their migrations: `yes n | php artisan martis:invitations` used to answer "no" and skip them, so pass `--no-migrate` to skip them.

`martis:install --force` also leaves an application's own `*_create_notifications_table.php` / `*_create_sessions_table.php` (from `make:notifications-table` / `make:session-table`) alone: it rewrites only a migration whose header holds a sentence of the Martis stub.

**What to check:**

1. **A `users.y` column.** `yes | php artisan martis:install --with-profile` answered `y` to the avatar column question: look for a migration adding `y` to `users` in `database/migrations` and `MARTIS_AVATAR_COLUMN=y` in `.env`. Roll the migration back (or drop the column), delete it, set `MARTIS_AVATAR_COLUMN` to the column you want and run the installer again with `--with-profile --avatar-column=<column>` (and `--with-2fa` when you use two-factor authentication).
2. **A `martis:user` run through a pipe.** `yes | php artisan martis:user` created an admin with the email, name and password `y`, its email already verified: look for a user with the email `y` and delete it.
3. **Your own notifications or sessions migration.** If you ran `martis:install --force` over a migration you created with `make:notifications-table` or `make:session-table`, it holds the Martis stub: compare it with your version control and restore it.
4. **Scripts that relied on a prompt.** Pass the flags (`--with-profile`, `--with-2fa`, `--avatar-column=…`, `martis:user --email=… --password=…`, `--no-migrate`) instead.

### The global search and the pivot routes apply `scopes()`

The global search (`/api/search`, the Cmd+K palette) and the parent lookup of a `BelongsToMany` panel and of the pivot routes (pivot actions, their fields and pickers, the pickers of the pivot fields, on `belongs-to-many` and `morph-to-many`) now run the resource's declarative `scopes()` before `indexQuery()`, as the index does. Before v1.39.3 they ran `indexQuery()` alone, so a resource that confined its tenants with `scopes()` showed another tenant's records in the palette.

- A record the scopes hide no longer shows in the palette, and the `total` of its group no longer counts it.
- A `BelongsToMany` panel, a pivot action or a pivot field picker whose parent record the scopes hide answers `404`, as it already did for a parent `indexQuery()` hides.
- On those surfaces and on an action run, an `orWhere()` in `scopes()` or `indexQuery()` is grouped before the term, the key or the selected ids are added. Before, they were appended to its last clause only, so a hook such as `where('tenant_id', 1)->orWhere('shared', true)` made the palette list the tenant's records whatever the term, a panel resolve the first record of the tenant instead of the one it names, and an action on one selected record run on every record of the tenant.

**What to change:** nothing when `scopes()` holds tenancy or visibility rules: they now apply where the docs said they would. A scope meant to trim the index page only (an `archived = false` default that users should still reach from the palette) belongs in a [filter](filters.md) instead. See [Authorization → Declarative query scopes](authorization.md#declarative-query-scopes).

### Tool routes run the Martis API middleware

`Tool::loadRoutes()` called without a middleware list gives a tool's routes `ToolRoutes::middleware($tool)` (`Martis\Tools\ToolRoutes`): the middleware of the package's protected API routes (your `martis.middleware` and `martis.auth_middleware`, the impersonation expiry, the 2FA challenge, the user's locale, email verification when it is enabled, the API throttle), then `martis.tool:{uriKey}`. It defaulted to `['web', 'martis.auth']` before v1.39.3.

- A user who signed in with a password but has not passed the 2FA challenge gets `423` from a tool route (a redirect to the challenge for a page request), and a user who has not verified an email the app requires gets `409` (a redirect to the notice), as from the rest of the API. Before, both got through.
- A user the tool is hidden from (`canSee()`, its policy) gets `404` from its routes, as from the tool's page.
- A tool's routes count toward the API throttle, `MARTIS_THROTTLE_MAX` (120) requests per `MARTIS_THROTTLE_DECAY` (1) minute per user, shared with the rest of the API. A tool that polls often answers `429` past it.
- They run in the user's locale, and an impersonation past its limit stops before they run.
- **Their URL does not move.** They stay under `/martis/api/tools/{uriKey}/...`, with the names the routes file gives them. With a `MARTIS_PATH` other than `martis` they are also served under `/{MARTIS_PATH}/api/tools/{uriKey}/...` (`ToolRoutes::prefix()`), where the SPA's `api` client calls them (it answered 404 before); the copy runs the same middleware, and a named route's copy is named `martis.tools.{uriKey}.path.{name}`. v2.0 serves them under `/{MARTIS_PATH}/api/tools/{uriKey}/...` only.

The signature keeps its type, `array $middleware`, so a tool that overrides `loadRoutes()` with the signature it had before still loads, and a list passed explicitly is used as given, as before. A list that leaves out the 2FA challenge while `MARTIS_2FA_ENABLED` is on (the default), or email verification while it is on, and the old default `['web', 'martis.auth']` itself, log a warning that names the tool, once per tool and PHP process.

**What to change:**

1. **Drop the middleware argument** of every `loadRoutes()` call that passes `['web', 'martis.auth']` (or forwards it from an override): that list keeps the old stack, without the 2FA challenge, and logs the warning. Pass a list only for another stack: `[...ToolRoutes::middleware($this), 'can:imports.run']` adds an ability, and a route that must answer before the 2FA challenge or to users the tool is hidden from keeps its own list, which is used exactly as given.
2. **Routes a tool registers in `boot()` with `Route::middleware(['web', 'martis.auth'])->prefix('martis/api/tools/...')`**, the pattern these docs showed, keep that weaker stack and that path, and Martis does not warn about them: switch them to `Route::middleware(ToolRoutes::middleware($this))->prefix(ToolRoutes::prefix($this))`. A route of your own that `martis.auth` alone guards skips the 2FA challenge the same way: use the `martis.api` middleware group.
3. **A client that calls a tool route by a hard-coded `/martis/api/tools/...` URL** keeps working, behind the new middleware. With a custom `MARTIS_PATH`, prefer the SPA's `api` client (`api.get('/api/tools/...')`), which reaches the copy under `/{MARTIS_PATH}`: v2.0 serves only that one.
4. **A tool that polls** raises `MARTIS_THROTTLE_MAX`, or passes a list without the throttle.

See [Tools → Tool routes and their middleware](tools.md#tool-routes-and-their-middleware).

### `martis.auth` runs where Laravel's `auth` runs

`MartisAuthenticate`, the `martis.auth` middleware, implements Laravel's `AuthenticatesRequests`, as Laravel's and Nova's `Authenticate` do, so the router's middleware priority now runs it right after the session starts: before the throttle, the route bindings (`SubstituteBindings`) and any middleware that is not in the priority list, including one appended to the `web` group or listed in `martis.middleware`. Before v1.39.3 it ran after them. The protected routes' throttle therefore counts per signed-in user (with a custom `MARTIS_GUARD` it counted per IP, see [A custom Martis guard](#a-custom-martis-guard)), and an unauthenticated request to a protected route is answered before the throttle counts it, as with Laravel's `auth` and `throttle`.

**What to change:** a middleware that must run before authentication, such as one that selects a tenant's database connection, must be in the app's middleware priority list, as it must for Laravel's `auth` (stancl/tenancy registers its own there):

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->prependToPriorityList(
        \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
        \App\Http\Middleware\InitializeTenant::class,
    );
})
```

**If you use the default guard** (`MARTIS_GUARD` unset, or set to the app's default guard), the only change is the order above: the panel already ran as that guard's user.

### A custom Martis guard

With a custom `MARTIS_GUARD`, the panel's requests now authenticate as that guard's user everywhere (`MartisAuthenticate` calls `auth()->shouldUse()`, as Laravel's `auth` middleware and Nova's do). Policies, `Gate::before()` / `Gate::after()` callbacks, gates, `canSee()` closures, observers and anything else that reads `auth()->user()` or `auth()->id()` during a panel request receive an instance of that guard's provider model: before, they got the app's default guard's user, which was null, or the site user when both guards were signed in in the same browser (the panel's policies then evaluated the site account). A closure type-hinted on the default model (`fn (User $user)`) now throws a `TypeError`: widen it (`Authenticatable`) or check the instance. The notification bell needs that model to use `Illuminate\Notifications\Notifiable` (without it the bell reads as off and the log says why; or set `MARTIS_NOTIFICATIONS_ENABLED=false`). The action log's and the preferences' user relation resolve that model, and impersonation runs on that guard unless `MARTIS_IMPERSONATION_GUARD` names another. The protected routes' rate limit counts per Martis user rather than per IP (the throttle ran before the guard switch before v1.39.3, see [`martis.auth` runs where Laravel's `auth` runs](#martisauth-runs-where-laravels-auth-runs)).

The flows that find or create the Martis guard's users use that guard's provider: the magic link (it looked the email up in the app's default user provider, `users`, and signed that user into the Martis guard, which then loaded the admin with the same id), the registration and invitation accept (their email must be unique among that guard's users, not in `users`), the email verification link, and `php artisan martis:user`, which creates that guard's user, as Nova's `nova:user` does. With `MARTIS_GUARD` unset, all of them follow the app's default guard, as the panel does.

When the guard's model has its own table (an `admins` guard beside the site's `users`), two more things differ:

- **The Martis tables reference that table.** A fresh install creates `martis_user_preferences.user_id` and `invitations.invited_by` / `accepted_user_id` with foreign keys to it, and adds the two-factor and avatar columns to it. An install migrated before v1.39.3 has them on `users`: saving an admin's preferences fails on the foreign key, or ties the row to the site user with that id. Run the migration below.
- **Browser sessions read as unsupported.** Laravel's `sessions.user_id` holds the id of the guard that wrote the row, with no table: the same id is an admin in one row and a site user in another. When the session guards of `config/auth.php` (with the Martis and the default guard) sign in users of more than one table, the profile's Browser sessions section says so instead of listing or revoking another person's sessions, and `revoke_sessions_on_demote` is skipped with a warning in the log. Guards that share one table, through any provider or model, keep both.

Checklist for an app with `MARTIS_GUARD` set to its own guard:

- Widen closures and policy methods type-hinted on the default user model, or check the instance.
- Add `Illuminate\Notifications\Notifiable` to the Martis guard's model, or set `MARTIS_NOTIFICATIONS_ENABLED=false`.
- Leave `MARTIS_IMPERSONATION_GUARD` unset (it follows `MARTIS_GUARD`), or set it to the same guard; a `config/martis.php` published before v1.39.3 has `env('MARTIS_IMPERSONATION_GUARD', 'web')`, so it stays on `web` until you set the variable (or change that line to `env('MARTIS_IMPERSONATION_GUARD')`). An operator signed in by a guard of other users is recorded in the audit row's `fields.operator_type` / `operator_id`, with no `user_id`.
- Rows the action log wrote before the upgrade with the default guard's ids now resolve through the Martis guard's model. From v1.39.3 an event caused by another guard's user records no actor: a role change or an invitation event outside the panel (in a site request, even from a browser that also holds a panel session, in a job, in a command) records none (or the inviter), and an authorization denial is recorded only while the Martis guard is the request's guard.
- Impersonation now acts on the Martis guard's users: the operator and the target are both, for instance, admins. To impersonate the site's users, set `MARTIS_IMPERSONATION_GUARD` to the site's guard, on which the operator must then be signed in too.
- With password reset enabled, set `MARTIS_AUTH_PASSWORD_BROKER` to a password broker (`config/auth.php` → `passwords`) whose provider is the Martis guard's: the default, `users`, resets the site's accounts.
- If a boot script runs `php artisan martis:user --if-missing`, it now checks and creates the Martis guard's user.
- When the guard's model has its own table, run this migration once (with your table in place of `admins`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $admins = 'admins'; // the table of the Martis guard's model

        if (Schema::hasTable('martis_user_preferences')) {
            // Before v1.39.3 the preferences of the default guard's user (the
            // site user signed in the same browser, if any) were saved:
            // theme, accent, density and locale only, so the table starts over.
            Schema::drop('martis_user_preferences');
            Schema::create('martis_user_preferences', function (Blueprint $table) use ($admins) {
                $table->id();
                // foreignUuid() / foreignUlid() for a UUID / ULID key.
                $table->foreignId('user_id')->unique()->constrained($admins)->cascadeOnDelete();
                $table->string('theme', 16)->default('dark');
                $table->string('accent', 16)->default('martis');
                $table->string('brand_color', 9)->nullable();
                $table->string('density', 16)->default('comfortable');
                $table->string('locale', 10)->default('en');
                $table->boolean('reduced_motion')->default(false);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('invitations')) {
            // The inviters and the accepted accounts are already the Martis
            // guard's users; an id that names none of them is cleared first.
            foreach (['invited_by', 'accepted_user_id'] as $column) {
                DB::table('invitations')
                    ->whereNotIn($column, DB::table($admins)->select('id'))
                    ->update([$column => null]);
            }

            Schema::table('invitations', function (Blueprint $table) use ($admins) {
                $table->dropForeign(['invited_by']);
                $table->dropForeign(['accepted_user_id']);
                $table->foreign('invited_by')->references('id')->on($admins)->nullOnDelete();
                $table->foreign('accepted_user_id')->references('id')->on($admins)->nullOnDelete();
            });
        }
    }
};
```

When the model's key type differs from `users.id` (a UUID model beside bigint users), change the two invitation columns' type (`$table->uuid('invited_by')->nullable()->change()`) before adding their foreign keys. If you use two-factor authentication or the avatar, add their columns to the Martis guard's table too: copy `vendor/martis/martis/stubs/add_two_factor_columns.php.stub` (and `add_profile_picture_column.php.stub`, with your avatar column in place of `profile_picture`) to a new file in `database/migrations/` and run `php artisan migrate`; both add only the columns the table lacks, to the Martis guard's table.

### Actions only a lens declares

The lens page runs its actions through new lens action routes (`/martis/api/resources/{resource}/lenses/{lens}/actions/...`), resolved from the lens as in Nova, so an action only the lens declares now works there and a resource action the lens leaves out no longer runs from it; those routes answer `403` when the lens's `canSee()` denies it. Republish the assets (`php artisan martis:publish-assets`). See [Lenses → Actions only the lens declares](lenses.md#actions-only-the-lens-declares).

## Known issues in v1.39.3

### The audit log has no policy

The built-in audit log resource (`Martis\Resources\ActionEventResource`, **System → Action Events**, `/martis/api/resources/action-events`) ships no policy on 1.x, and a resource without a policy is readable by every panel user: any signed-in user can list and open the audit rows (who did what, the changed attributes, impersonations, role changes). v2.0.1 gives it one. On 1.x, register a policy for its model in the app, for instance in `App\Providers\AppServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Gate;
use Martis\Models\ActionEvent;

Gate::policy(ActionEvent::class, \App\Policies\ActionEventPolicy::class);
```

```php
<?php

namespace App\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Martis\Models\ActionEvent;

class ActionEventPolicy
{
    // Replace the check with your own rule (a role, a permission, a gate).
    public function viewAny(Authenticatable $user): bool
    {
        return method_exists($user, 'hasRole') && $user->hasRole('admin');
    }

    public function view(Authenticatable $user, ActionEvent $event): bool
    {
        return $this->viewAny($user);
    }
}
```

`ActionEventResource` finds it through the Gate (the model's policy), so the index, the detail page, the navigation entry, its badge and the command palette's "Recent" block all follow it; the resource already refuses create, update and delete. A policy at `App\Martis\Policies\ActionEventPolicy` (the `martis.policy_namespace` convention) is found without the `Gate::policy()` line. To hide the audit log from everyone instead, set `MARTIS_ACTION_EVENTS_RESOURCE=false`: the rows are still written (`MARTIS_ACTION_EVENTS_ENABLED`), only the resource is not registered.
