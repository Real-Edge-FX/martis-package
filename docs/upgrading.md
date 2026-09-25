# Upgrading

What to do in an app when a 1.x release changes behaviour it may rely on. A patch release changes behaviour only to close a security gap or a bug; each section says what changed, who is affected and what to check.

## Upgrading to v1.39.3

v1.39.3 is a security release. After `composer update martis/martis`, in every environment:

```bash
php artisan martis:publish-assets
php artisan martis:cache:clear
```

`martis:cache:clear` matters on 1.x: the cache keys carry no package version and the `schema` layer keeps its entries with no expiration by default, so without it the cached resource schemas keep the relationship panel flags of the previous version (see [A write through a relationship needs the related `viewAny`](#a-write-through-a-relationship-needs-the-related-viewany)).

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
