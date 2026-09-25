# In-app Tools: when (and how) to use `boot()`

> Companion notes to [tools.md](tools.md). Focused on Tools that live INSIDE your application (not Composer packages). For the contrast with Nova 5 conventions (auto-loaded `routes/tool.php`, `Tool::menu()`, sub-nav slot, etc.), see [Differences from Nova 5](tools.md#differences-from-nova-5).
>
> Live reference: a playground demo ships a real `boot()` with all four core patterns wired up — `app/Martis/Tools/SystemStatus.php` (the Tool), `app/Http/Controllers/SystemStatusController.php` (the routes' backend), and `resources/js/tools/SystemStatusTool.tsx` (the React consumer).

An in-app Tool's setup code can live in the Tool's own `boot()` or in `AppServiceProvider::boot()`. This page says when each one runs and shows the four usual pieces (routes, gates, schedules and listeners) wired in a Tool's `boot()`.

## The question this answers

For an in-app Tool, you have two places where setup code can live:

| Option | Where | When it runs |
|---|---|---|
| **A. `Tool::boot()` on the Tool class** | `app/Martis/Tools/MyTool.php` | After Martis itself boots, scoped to the Tool |
| **B. Your `AppServiceProvider::boot()`** | `app/Providers/AppServiceProvider.php` | App boot, global |

Both work. Routes, gates, listeners, schedules — all of it can live in either place. Pick `boot()` on the Tool when the setup is meaningful **only because that Tool exists**.

## Decision rubric

```
Is the setup tied to a single Tool's existence?
  └─ Yes → put it in the Tool's boot()
       Reason: kill the Tool, kill the setup.

Is the setup app-wide?
  └─ Yes → AppServiceProvider::boot()
       Reason: it must run even if the Tool is removed.

Will multiple Tools share it?
  └─ Yes → AppServiceProvider::boot()
       Reason: a Tool's boot() runs only when that specific Tool
               is registered.
```

Concrete examples:

| Setup | Where | Why |
|---|---|---|
| `/api/tools/imports/upload` route (used only by ImportsTool) | `ImportsTool::boot()` | Tied to one Tool |
| `app.timezone` config check | `AppServiceProvider::boot()` | App-wide |
| `ticket-export.run` Gate (used by an ExportTool action) | `ExportTool::boot()` | Tied to one Tool |
| `auth-impersonate` Gate (used by 3 Tools + a Resource action) | `AuthServiceProvider::boot()` | Multi-tool |
| Schedule that prunes one Tool's cache table | `MyTool::boot()` | Tied to one Tool |
| Schedule that runs nightly DB backups | `App\Console\Kernel` | App-wide |
| Listener for `JobFailed` that updates one Tool's UI | `MyTool::boot()` | Tied to one Tool |
| Listener for `JobFailed` that pages on-call via PagerDuty | `EventServiceProvider` | App-wide |

## The patterns (with code)

The playground's `SystemStatus` Tool demonstrates the first four end-to-end. Patterns 5 and 6 are common follow-ups for richer Tools. Pick the ones you need; skip the rest.

### Pattern 1 — Tool-owned routes

```php
// app/Martis/Tools/SystemStatus.php
public function boot(): void
{
    // use Martis\Tools\ToolRoutes;
    Route::middleware(ToolRoutes::middleware($this))
        ->prefix(ToolRoutes::prefix($this))
        ->name('martis-playground.tools.system-status.')
        ->group(function (): void {
            Route::get('/snapshot', [SystemStatusController::class, 'snapshot']);
            Route::post('/health-check', [SystemStatusController::class, 'healthCheck'])
                ->middleware('can:system-status.run-health-check');
        });
}
```

**Why here vs `routes/web.php`?**
- The Tool's React component is the only consumer.
- Removing the Tool removes the routes — no orphaned endpoints.
- The route name prefix mirrors the Tool's name, making `route('martis-playground.tools.system-status.snapshot')` unambiguous.

**Watch out:** the routes register whatever `canSee()` says; the middleware chain is what gates access. `ToolRoutes::middleware($this)` (v2.0) is the chain of the Martis API routes (authentication, the 2FA challenge, email verification, the locale, the impersonation expiry, the API throttle) followed by `martis.tool:{uriKey}`, which answers 404 to a user the Tool is hidden from, as its page does. Add a `can:` gate for a finer ability, as `health-check` does above. `ToolRoutes::prefix($this)` is `{martis.path}/api/tools/{uriKey}`, where the SPA's `api` client calls the routes. A hand-written list such as `['web', 'martis.auth']` skips the 2FA challenge and the rest; since v2.0.1 a route under the tool's path with such a list logs a warning: see [Routes a tool registers in `boot()`](tools.md#routes-a-tool-registers-in-boot).

**Tip — `Tool::loadRoutes()` for richer Tools.** When the inline group exceeds three or four routes, `Tool::loadRoutes($path)` (added in v1.8.8) extracts them into a sibling file under the same prefix + middleware:

```php
public function boot(): void
{
    // Loads `app/Martis/Tools/routes/system-status.php` under
    // ToolRoutes::prefix($this), behind ToolRoutes::middleware($this).
    $this->loadRoutes(__DIR__.'/routes/system-status.php');
}
```

See [Loading routes from a sibling file](tools.md#loading-routes-from-a-sibling-file) for the full signature, including custom `middleware` and `prefix`. The inline group above remains valid; `loadRoutes()` is a readability optimisation, not a replacement.

### Pattern 2 — Per-tool Gate

```php
public function boot(): void
{
    Gate::define('system-status.run-health-check', function ($user): bool {
        return $user !== null
            && property_exists($user, 'is_admin')
            && $user->is_admin === true;
    });
}
```

**Why here vs `AuthServiceProvider`?**
- The ability is meaningless without the Tool.
- The host app can re-define the Gate in `AuthServiceProvider::boot()` AFTER package boot — last definition wins.
- When you delete the Tool, the Gate vanishes. No zombie ability cluttering the auth layer.

### Pattern 3 — Scheduled tasks

```php
use Illuminate\Console\Scheduling\Schedule;

public function boot(): void
{
    // The scheduler only runs from the console.
    if (! app()->runningInConsole()) {
        return;
    }

    $register = function (Schedule $schedule): void {
        $schedule
            ->call(function (): void {
                cache()->put(
                    'system-status:snapshot',
                    app(SystemStatusController::class)->computeSnapshot(),
                    now()->addMinutes(5),
                );
            })
            ->everyFiveMinutes()
            ->name('martis-playground:system-status:refresh-snapshot')
            ->withoutOverlapping();
    };

    // Register when the scheduler builds its Schedule, or now if it already has.
    app()->afterResolving(Schedule::class, $register);

    if (app()->resolved(Schedule::class)) {
        $register(app(Schedule::class));
    }
}
```

**Why here vs `App\Console\Kernel`?**
- The schedule entry is bound to one Tool's data model.
- Removing the Tool removes the schedule.
- Multiple Tools each ship their own schedule without anyone editing `Console\Kernel`.

**Why `afterResolving()` and not `app()->bound(Schedule::class)`.** Since Laravel 11, `FoundationServiceProvider` binds `Schedule` as a singleton on every boot, web requests included, so `app()->bound(Schedule::class)` is always `true` and never short-circuits: a Tool guarded that way builds the console schedule on every HTTP request. `boot()` runs on every request and every artisan command, so the pattern above does what Laravel's own `withSchedule()` does: it hooks the registration to the moment the scheduler resolves `Schedule` (`schedule:run`, `schedule:work`, `schedule:list`), and registers immediately when it has already been resolved. The `runningInConsole()` check keeps web requests out entirely.

`MartisManager::bootTools()` catches an exception thrown by a Tool's `boot()` and only logs it (`[martis] Tool boot() threw — registration kept, hook skipped.`), so a failing boot leaves the Tool in the sidebar with its routes, gates or schedule missing. Check `laravel.log` for that line, and `php artisan schedule:list` for the task.

### Pattern 4 — Event listeners

```php
public function boot(): void
{
    Event::listen(JobFailed::class, function (JobFailed $event): void {
        Log::channel('stack')->error('Queue job failed', [
            'connection' => $event->connectionName,
            'job' => $event->job->resolveName(),
            'exception' => $event->exception->getMessage(),
            'observed_by' => 'martis-playground:tools:system-status',
        ]);
    });
}
```

**Why here vs `EventServiceProvider`?**
- The listener exists ONLY because a Tool's UI displays its output.
- The `observed_by` marker lets the Tool filter logs without coupling to internal Laravel layout.
- Removing the Tool removes the listener.

### Pattern 5 — Tool-owned console commands

When a Tool has backend operations a developer wants to invoke from the CLI (manually triggering the snapshot refresh, dumping a debug payload, kicking a re-index), register them inside `boot()` instead of cluttering `App\Console\Kernel`:

```php
use Illuminate\Support\Facades\Artisan;

public function boot(): void
{
    Artisan::command('system-status:refresh', function (): void {
        cache()->put(
            'system-status:snapshot',
            app(SystemStatusController::class)->computeSnapshot(),
            now()->addMinutes(5),
        );
        $this->info('Snapshot refreshed.');
    })->purpose('Refresh the System Status snapshot cache (Martis Tool).');
}
```

**Why here vs `App\Console\Kernel`?**
- The command is meaningful only because the Tool exists; remove the Tool, lose the command.
- Closure commands keep the wiring inline. For full command classes, prefer `Artisan::resolve(MyCommand::class)` from `boot()` so the class still lives under `app/Console/Commands/Martis/...`.
- The Schedule pattern above can `->call(fn () => Artisan::call('system-status:refresh'))` to share the implementation between the schedule and a manual `php artisan` run.

### Pattern 6 — View / translation / migration namespaces

Tools that ship Blade templates (PDF receipts, mail layouts), translation strings, or migrations typically register them from `boot()` so the host app does not have to know they exist:

```php
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\View;

public function boot(): void
{
    // Blade templates packaged alongside the Tool.
    View::addNamespace('system-status', __DIR__.'/../../resources/views/system-status');

    // Translation strings (consumer can override via vendor:publish).
    Lang::addNamespace('system-status', __DIR__.'/../../resources/lang/system-status');

    // Migrations that ship with the Tool. `Tool` does not extend
    // ServiceProvider, so use the migrator helper directly instead
    // of `$this->loadMigrationsFrom()` (the latter only exists on
    // service providers). Composer-package Tools should subclass
    // `ToolServiceProvider` and call `loadMigrationsFrom()` there.
    app('migrator')->path(__DIR__.'/../../database/migrations/system-status');
}
```

**Why here vs the Service Provider?**
- The Tool defines its own templating / locale surface — moving the registration into `AppServiceProvider` separates "the Tool" from "the Tool's resources", and the next maintainer has to walk both files to understand what runs.
- The host app can re-`addNamespace()` later (or publish overrides via `$this->publishes(...)` from the same `boot()`) — last write wins.
- Composer-package Tools should subclass `ToolServiceProvider` instead, where the standard `loadViewsFrom` / `loadTranslationsFrom` / `loadMigrationsFrom` helpers are available. The pattern above is for in-app Tools that prefer co-location.

## Anti-patterns

- **Don't register routes in the constructor.** The constructor runs at object-construction time (before the application has fully booted) and can leak across tests.
- **Don't put cross-tool setup in a single Tool's boot().** If two Tools both need a Gate or a route, neither owns it — it belongs in a service provider.
- **Don't depend on `boot()` running multiple times.** The hook is idempotent per registration; reset is internal.
- **Don't throw from `boot()` and expect to see it.** Exceptions are caught and logged with the prefix `[martis] Tool boot() threw`. The admin panel keeps booting. Watch your `laravel.log`.

## TL;DR

`Tool::boot()` is **co-location**. It lets you keep all the setup that "exists because of this Tool" inside the Tool file — routes, gates, schedules, listeners, console commands, view / translation namespaces. Cleaner diffs, no orphaned wiring, easier to see at a glance what each Tool actually does.

For setup that's app-wide, `AppServiceProvider::boot()` is still the right home. The two complement each other; they don't compete.
