# In-app Tools: when (and how) to use `boot()`

> Companion notes to [tools.md](tools.md). Focused on Tools that live INSIDE your application (not Composer packages). For the contrast with Nova 5 conventions (auto-loaded `routes/tool.php`, `Tool::menu()`, sub-nav slot, etc.), see [Differences from Nova 5](tools.md#differences-from-nova-5).
>
> Live reference: a playground demo ships a real `boot()` with all four core patterns wired up — `app/Martis/Tools/SystemStatus.php` (the Tool), `app/Http/Controllers/SystemStatusController.php` (the routes' backend), and `resources/js/tools/SystemStatusTool.tsx` (the React consumer).

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
    Route::middleware(['web', 'martis.auth'])
        ->prefix('martis/api/tools/system-status')
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

**Watch out:** if the Tool is hidden via `canSee()`, the routes still register. The middleware chain (`martis.auth`, `can:`) is what gates access. Routes are an HTTP-level surface; the Tool's UI visibility is an **orthogonal** concern.

**Tip — `Tool::loadRoutes()` for richer Tools.** When the inline group exceeds three or four routes, `Tool::loadRoutes($path)` (added in v1.8.8) extracts them into a sibling file under the same prefix + middleware:

```php
public function boot(): void
{
    // Loads `app/Martis/Tools/routes/system-status.php` under
    // 'martis/api/tools/system-status' with ['web', 'martis.auth'].
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
public function boot(): void
{
    if (! app()->bound(Schedule::class)) {
        return;
    }

    app(Schedule::class)
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
}
```

**Why here vs `App\Console\Kernel`?**
- The schedule entry is bound to one Tool's data model.
- Removing the Tool removes the schedule.
- Multiple Tools each ship their own schedule without anyone editing `Console\Kernel`.

**The `if (! app()->bound(Schedule::class))` guard** prevents `php artisan` invocations that don't touch the scheduler from blowing up. The Schedule is only bound during `schedule:run` and `schedule:list`.

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

    // Migrations that ship with the Tool — typically only used
    // by Composer-package tools, but in-app tools may want to
    // colocate migrations next to the Tool's own logic.
    if (function_exists('database_path')) {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations/system-status');
    }
}
```

**Why here vs the Service Provider?**
- The Tool defines its own templating / locale surface — moving the registration into `AppServiceProvider` separates "the Tool" from "the Tool's resources", and the next maintainer has to walk both files to understand what runs.
- The host app can re-`addNamespace()` later (or publish overrides via `$this->publishes(...)` from the same `boot()`) — last write wins.
- Composer-package Tools should subclass `ToolServiceProvider` instead and call `loadViewsFrom` / `loadTranslationsFrom` / `loadMigrationsFrom` from there. The pattern above is for in-app Tools that prefer co-location.

> `loadMigrationsFrom()` is provided by `Illuminate\Support\ServiceProvider`. Tools are not service providers, so use the equivalent `app('migrator')->path(...)` call when registering migrations from a plain `Tool` subclass; or simply move migrations into `database/migrations/` for in-app Tools where path consolidation is fine.

## Anti-patterns

- **Don't register routes in the constructor.** The constructor runs at object-construction time (before the application has fully booted) and can leak across tests.
- **Don't put cross-tool setup in a single Tool's boot().** If two Tools both need a Gate or a route, neither owns it — it belongs in a service provider.
- **Don't depend on `boot()` running multiple times.** The hook is idempotent per registration; reset is internal.
- **Don't throw from `boot()` and expect to see it.** Exceptions are caught and logged with the prefix `[martis] Tool boot() threw`. The admin panel keeps booting. Watch your `laravel.log`.

## TL;DR

`Tool::boot()` is **co-location**. It lets you keep all the setup that "exists because of this Tool" inside the Tool file — routes, gates, schedules, listeners, console commands, view / translation namespaces. Cleaner diffs, no orphaned wiring, easier to see at a glance what each Tool actually does.

For setup that's app-wide, `AppServiceProvider::boot()` is still the right home. The two complement each other; they don't compete.
