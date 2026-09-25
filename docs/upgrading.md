# Upgrading

> **These docs describe Martis v2.** Martis v1.x read `Select` and `MultiSelect` options as `[label => value]`; v2.0 and later read `[value => label]`, Nova's order. If your app still runs v1.x, flip the option arrays in the examples, or upgrade with the steps below.

The sections below list the breaking changes of each major version and what to change in an app.

## Upgrading to v2.0 from v1.x

Require the new major; a `^1.x` constraint never installs it:

```bash
composer require martis/martis:^2.0
```

Then change the app as the sections below say, and deploy it with the steps in [Deploying the upgrade](#deploying-the-upgrade).

### Choice-field options follow Nova's order (high impact)

`Select::options()` and `MultiSelect::options()` read `[value => label]`, the order Nova uses for `Select`, `MultiSelect` and `BooleanGroup`: the array key is what the field stores, the array value is what the user sees. v1.x read the array the other way round, `[label => value]`.

| Call | v1.x stored | v2.0 stores |
|---|---|---|
| `options(['Draft' => 'draft'])` | `draft` | `Draft` |
| `options(['draft' => 'Draft'])` | `Draft` | `draft` |
| `options(fn () => User::pluck('name', 'id')->all())` | the name | the id |
| `options(['draft', 'published'])` | `draft`, `published` | `0`, `1` |

Nothing fails at runtime when an array keeps the v1 order: the field stores the label instead of the value. Review every call.

**What to change:**

1. **Flip every associative array written label first.** `['Draft' => 'draft']` becomes `['draft' => 'Draft']`, and `pluck('id', 'name')` becomes `pluck('name', 'id')`.
2. **Turn lists into maps.** A list is read as Nova reads it, keyed 0, 1, 2…, so `['draft', 'published']` now stores `0` and `1`. When each value is its own label, pass `array_combine($values, $values)`; for an enum, pass the class (`options(Status::class)`) instead of a list of its values. A list of numbers shifts by one value with no error: `options([1, 2, 3])`, `range(1, 12)` or `array_column(Rating::cases(), 'value')` stored the numbers themselves on v1.x and now store 0, 1, 2, so a stored 1 shows as "2" and picking "3" stores 2.
3. **Rewrite MultiSelect groups in Nova's format.** `['Backend' => ['PHP' => 'php']]` becomes `['php' => ['label' => 'PHP', 'group' => 'Backend']]`. The old format now throws an `InvalidArgumentException` that names the field and the option.
4. **Replace `optionsFromMap()` with `options()`.** It was removed: `options()` reads the same `[value => label]` map and also takes a closure. A call left behind fails with an undefined-method error.
5. **Check `searchOptionsUsing()` closures.** They return the same shapes as `options()`, in the same order, so a list is keyed 0, 1, 2 and stores a position in that search's results, which changes with the term. Return `[value => label]`, for example `->pluck('name', 'id')`, or `array_combine($values, $values)` when each value is its own label. A closure that returns a non-empty list logs a warning, at most once per field per request.
6. **Review what else reads a flipped array.** On v1.x one array could serve both a field and a `SelectFilter` (for example a `Post::STATUSES` constant). Filters keep `[label => value]` (see below), so once the array is flipped for the field, the filter shows `draft` as the label and applies `where status = 'Draft'`: zero results, no error. Give the filter `array_flip(Post::STATUSES)`, or its own array. Review the other readers of a flipped array the same way: a `Rule::in(array_values(...))` rule now lists the labels (use `array_keys()`), and a Badge map or a `displayUsing()` lookup built from it is now keyed by value.
7. **Update generated code.** The `BulkAssignRole` action that `martis:roles` generates builds its role picker with `pluck('id', 'name')`; with v2.0 it would receive the role name: `Role::find('Admin')` answers "That role no longer exists" on MySQL and SQLite, and throws a `QueryException` on PostgreSQL, whose integer id column rejects the text. Change it to `pluck('name', 'id')` (new scaffolds already do). If you published the stubs with `martis:stubs`, update `stubs/martis/roles-bulk-assign-role-action.stub` the same way. The `AGENTS.md` / `CLAUDE.md` primers that `martis:agents` wrote under v1.x recommend `options($enum::values())`, which v2.0 reads as a list: regenerate them with `php artisan martis:agents --force` (update `stubs/martis/agents/AGENTS.md.stub` first if you published it).
8. **Subclasses.** A subclass of `Select` or `MultiSelect` that overrides `options()` must accept `iterable|Arrayable|string|\Closure`: `options()` now takes a Collection or any other iterable, as in Nova, and `MultiSelect::options()` takes an enum class too.

**Finding the calls:**

```bash
grep -rnF -e '->options(' -e 'optionsFromMap(' -e 'searchOptionsUsing(' app/ Modules/ packages/
```

Add every other folder that holds PHP source (a `src/` or `domain/` folder, local packages, a modular layout); `grep` warns about a folder that does not exist and searches the others. Every `Select` and `MultiSelect` hit needs a look. A filter's `public function options(Request $request)` keeps its order, but check the array it returns when a field shares it (step 6). A multi-line chain shows only its `options(` line, so read each hit in its file.

**Records saved with the wrong order.** A field whose array was already written value first under v1.x (for example `['admin' => 'Administrator']`) stored the label. v2.0 reads that array correctly, so those records hold a label where a value is expected: fix the stored values with a data migration.

**The stored-label warning.** Reading a record whose stored value matches the label of a static option and the value of none logs a warning, in production too, at most once per request for each model class and field, naming the model, the record, the field and the value. It fires in both cases above: an array still written label first, and a record saved while it was. For options given as a list, it also fires for a stored value that is the label of another option (the list of numbers of step 2). It reads the stored value, before `resolveUsing()`, and skips computed fields and a `Select` with `allowCustomValues()`. It names the first matching record only and never checks options that come from a closure (the `pluck()` case), so to find every affected record, query the column for values that are labels.

Both warnings can log a false positive: a field can store a valid 0-based position that also happens to read as another option's label, for example a Nova rating that stores `0..4` on purpose and shows `"1".."5"` (`[0 => '1', 1 => '2', ...]`, `array_combine(range(0, 4), range(1, 5))`, or `options([1, 2, 3, 4, 5])` ported straight from Nova). Reading a stored `1` there is correct, not stale data, but would otherwise warn on every request. Call `withoutOptionOrderWarnings()` on that field once the array is confirmed right; it silences the plain stored-label warning and the list-shift warning for that field, and the `searchOptionsUsing()` list warning, which a resolver that filters a fixed list and keeps its keys can trigger when the search term is empty.

**What does not change:**

- **Filters** keep Nova's filter order, `[label => value]`: a filter's `options(Request $request)` is unchanged, exactly as in Nova. An array a filter shares with a field is the exception: see step 6.
- **`BooleanGroup`** already read `[flag key => label]`.
- **Enum classes** (`options(Status::class)`) store the case value, as before; `MultiSelect` now accepts them too.
- **The field payload** keeps its shape (`options: [{label, value, group?}]`), so custom frontend components keep working. `group` is new on `Select`, whose dropdown now shows grouped options under their headings.

### Relationship writes need the related resource's `viewAny`

Creating, editing or deleting a record through a relationship panel (`HasMany`, `HasOne`, `MorphMany`, `MorphOne`, and their endpoints) now needs the related resource's `viewAny`, as its own per-id endpoints do since v1.34.0 and as Nova does. v1.x checked only the parent's `viewAny` / `view` and the related record's `create` / `update` / `delete`.

A user whose policy denies `viewAny` on the related resource gets a 403 on those writes, and the panel no longer offers Create, Edit, Delete, Restore or Force delete. It still lists the records, as in v1.x.

**What to change:** if that user should keep writing through the panel, grant `viewAny` on the related resource and confine what they see with `indexQuery()`. A resource that is not `routable()` keeps working as a relation target. See [Authorization → `viewAny` is the entry gate](authorization.md#viewany-is-the-entry-gate).

### Through relationships offer no Create

`HasOneThrough` and `HasManyThrough` never offer Create, as in Nova, and a create through a Through relationship answers 403. Edit and Delete now show by default, under the related resource's policies. `canCreate()` has no effect; `canCreate(true)` logs a warning naming the field. See [Relationships → Upgrading the Through fields from 1.x](relationships.md#upgrading-the-through-fields-from-1x).

### Actions: who may run them, and on which records

An action run on records changed in v2.0:

- **`canRun()` and the policy must both allow it.** The per-row map the index and the panels send (`_actionAuthorization`) now includes the resource's `runAction` / `runDestructiveAction` policy (falling back to `update` / `delete`), as the run always did: an action the policy refuses shows disabled instead of failing when run. A `canRun()` does not replace the policy, unlike Nova 5.
- **Trashed records run.** A selected record the resource soft-deletes is looked up with the trashed ones, since the index and the panels list them.
- **A partial selection runs on what resolves.** Ids outside the resource's `scopes()` / `indexQuery()`, or that no longer exist, are left out and the action runs on the others, as in Nova.
- **404 when nothing resolves.** A run whose selected records all fail to resolve answers `404` (`One or more selected resources could not be found.`) instead of running `handle()` on nothing and answering "Done".
- **422 with an empty selection.** An action that is not `standalone()` and names no record answers `422` (`resources`), as a pivot action already did. A `standalone()` action runs on no record, whatever the request names.
- **`scopes()` apply.** A run looks records up through the resource's declarative `scopes()`, then `indexQuery()`, as the index lists them.

**What to change:** a client that posts to `/actions/{action}` without `resources` for an action that is not standalone must send the ids, or declare the action `standalone()`.

### The schema cache expires after a day

The `schema` cache layer now expires after a day by default (`MARTIS_CACHE_SCHEMA_TTL=1440`); v1.x kept it with no expiration. Every cache key also carries the installed `martis/martis` version, so an upgrade of the package rebuilds every layer on its own and leaves the previous version's entries behind.

**What to change:**

1. **Check the TTL.** An empty `MARTIS_CACHE_SCHEMA_TTL=` (the `.env` block the v1.x docs showed) and a `config/martis.php` published before v2.0 keep "no expiration". Set `MARTIS_CACHE_SCHEMA_TTL=1440`, or republish the config and merge your changes.
2. **Prune on the database and file stores.** Those stores delete an expired entry only when its key is read again, which an old version's key never is. Run `php artisan martis:cache:prune` after each upgrade, or schedule it. Redis and memcached need nothing.

See [Cache → Invalidation](cache.md#invalidation).

### Custom themes

A theme generated with `martis:theme` before v2.0 may need three edits:

1. **`--martis-dur-sm` and `--martis-brand-500` do nothing any more.** No stylesheet defined them; the package now reads `--martis-dur-fast` and `--martis-accent` where it read them, and `martis:theme:diff` lists them as *Unknown to package* (exit 2). Set `--martis-dur-fast` and `--martis-accent` instead.
2. **Delete the two logo heights**, `--martis-brand-logo-height-auth` and `--martis-brand-logo-height-menu`, unless you mean to override `MARTIS_BRAND_LOGO_HEIGHT_AUTH` / `_MENU`: the old stub declared them on `:root` (the menu one at 28px), which silenced those `.env` knobs.
3. **Set the short typography names.** The package CSS reads `--martis-text-*`, `--martis-weight-*` and `--martis-leading-*`; a theme that sets only `--martis-font-size-*`, `--martis-font-weight-*` or `--martis-line-height-*` changes almost nothing.

See [Theming](theming.md#variable-reference).

### `martis.auth` runs where Laravel's `auth` runs

`MartisAuthenticate`, the `martis.auth` middleware, implements Laravel's `AuthenticatesRequests`, as Laravel's and Nova's `Authenticate` do, so the router's middleware priority now runs it right after the session starts: before the throttle, the route bindings (`SubstituteBindings`) and any middleware that is not in the priority list, including one appended to the `web` group or listed in `martis.middleware`. v1.x ran it after them. The protected routes' throttle therefore counts per signed-in user (with a custom `MARTIS_GUARD` it counted per IP, see [A custom Martis guard](#a-custom-martis-guard)), and an unauthenticated request to a protected route is answered before the throttle counts it, as with Laravel's `auth` and `throttle`.

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

### Deploying the upgrade

Run these in each environment, after the code with the flipped arrays is deployed there, in this order:

```bash
php artisan martis:publish-assets
php artisan martis:cache:clear schema
php artisan martis:cache:prune
```

1. **Republish the assets.** The grouped `Select` dropdown and the fix for a `''` option ship in the frontend bundle; without the republish the browser keeps the v1 bundle.
2. **Clear the schema cache, after the deploy.** It holds the options as they were read, for up to a day by default, and with no expiration when `MARTIS_CACHE_SCHEMA_TTL` is empty or `config/martis.php` was published before v2.0 (see [The schema cache expires after a day](#the-schema-cache-expires-after-a-day)); a new package version does not help here, because the arrays you flipped are your app's code. Cleared before the new code is deployed, the next page opened caches the old options again, so clear it after the deploy, in every environment.
3. **Prune the old cache entries**, on the database and file stores (a no-op on the others).

## A custom Martis guard

With a custom `MARTIS_GUARD`, the panel's requests now authenticate as that guard's user everywhere (`MartisAuthenticate` calls `auth()->shouldUse()`, as Laravel's `auth` middleware and Nova's do). Policies, `Gate::before()` / `Gate::after()` callbacks, gates, `canSee()` closures, observers and anything else that reads `auth()->user()` or `auth()->id()` during a panel request receive an instance of that guard's provider model: before, they got the app's default guard's user, which was null, or the site user when both guards were signed in in the same browser (the panel's policies then evaluated the site account). A closure type-hinted on the default model (`fn (User $user)`) now throws a `TypeError`: widen it (`Authenticatable`) or check the instance. The notification bell needs that model to use `Illuminate\Notifications\Notifiable` (without it the bell reads as off and the log says why; or set `MARTIS_NOTIFICATIONS_ENABLED=false`). The action log's and the preferences' user relation resolve that model, and impersonation runs on that guard unless `MARTIS_IMPERSONATION_GUARD` names another. The protected routes' rate limit counts per Martis user rather than per IP (the throttle ran before the guard switch in v1.x, see [`martis.auth` runs where Laravel's `auth` runs](#martisauth-runs-where-laravels-auth-runs)).

The flows that find or create the Martis guard's users use that guard's provider: the magic link (v1.x looked the email up in `users` and signed that user into the Martis guard, which then loaded the admin with the same id), the registration and invitation accept (their email must be unique among that guard's users, not in `users`), the email verification link, and `php artisan martis:user`, which creates that guard's user, as Nova's `nova:user` does. With `MARTIS_GUARD` unset, all of them follow the app's default guard, as the panel does.

When the guard's model has its own table (an `admins` guard beside the site's `users`), two more things differ:

- **The Martis tables reference that table.** A fresh install creates `martis_user_preferences.user_id` and `invitations.invited_by` / `accepted_user_id` with foreign keys to it, and adds the two-factor and avatar columns to it. An install migrated before v2.0.0 has them on `users`: saving an admin's preferences fails on the foreign key, or ties the row to the site user with that id. Run the migration below.
- **Browser sessions read as unsupported.** Laravel's `sessions.user_id` holds the id of the guard that wrote the row, with no table: the same id is an admin in one row and a site user in another. When the session guards of `config/auth.php` (with the Martis and the default guard) sign in users of more than one table, the profile's Browser sessions section says so instead of listing or revoking another person's sessions, and `revoke_sessions_on_demote` is skipped with a warning in the log. Guards that share one table, through any provider or model, keep both.

Checklist for an app with `MARTIS_GUARD` set to its own guard:

- Widen closures and policy methods type-hinted on the default user model, or check the instance.
- Add `Illuminate\Notifications\Notifiable` to the Martis guard's model, or set `MARTIS_NOTIFICATIONS_ENABLED=false`.
- Leave `MARTIS_IMPERSONATION_GUARD` unset (it follows `MARTIS_GUARD`), or set it to the same guard; a config file published before v2.0.0 has `'web'` as its default, so republish it or set the variable. An operator signed in by a guard of other users is recorded in the audit row's `fields.operator_type` / `operator_id`, with no `user_id`.
- Rows the action log wrote before the upgrade with the default guard's ids now resolve through the Martis guard's model. From v2.0.0 an event caused by another guard's user records no actor: a role change or an invitation event outside the panel (in a site request, even from a browser that also holds a panel session, in a job, in a command) records none (or the inviter), and an authorization denial is recorded only while the Martis guard is the request's guard.
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
            // v1.x saved the preferences of the default guard's user (the
            // site user signed in the same browser, if any): theme, accent,
            // density and locale only, so the table starts over.
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
