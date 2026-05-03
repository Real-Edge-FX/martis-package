# Impersonation

> Lets a privileged operator log in as another user for the duration of a session, then return to their own account with one click.
> Shipped in v0.10.

## Why this exists

Support, debugging, and customer-success workflows often need an operator to see exactly what another user sees. The two alternatives — copying production data into staging or stashing magic links — are slow and lossy. Impersonation is the right primitive when you trust the operator and audit-log every session.

## Two-layer guard

Impersonation is **opt-in**. Both layers below must say "yes" before the start endpoint succeeds:

1. **Master switch** — `martis.impersonation.enabled` (default `false`).
   Set `MARTIS_IMPERSONATION_ENABLED=true` in the env to flip it on.
2. **Authorisation gate** — `martis-impersonate`.
   The package ships **no default**: you define it explicitly. This is on purpose — admins should not become impersonators by accident.

When the master switch is off, every endpoint returns **503**. When the gate denies the request, **403**. The two states are deliberately distinct so the audit trail can tell "globally disabled" apart from "per-user denied".

## Configuration

```php
// config/martis.php
'impersonation' => [
    'enabled' => env('MARTIS_IMPERSONATION_ENABLED', false),
    'guard' => env('MARTIS_IMPERSONATION_GUARD', 'web'),
    'session_key' => env('MARTIS_IMPERSONATION_SESSION_KEY', 'martis.impersonation'),
    'max_duration_minutes' => (int) env('MARTIS_IMPERSONATION_MAX_DURATION', 0),
],
```

| Key | Default | Purpose |
|---|---|---|
| `enabled` | `false` | Master switch. |
| `guard` | `web` | Auth guard the impersonation operates on. Most apps stay on `web`. |
| `session_key` | `martis.impersonation` | Session bag where the operator's id is stashed. Change it for cross-tenant isolation. |
| `max_duration_minutes` | `0` (disabled) | Auto-stop the session after N minutes of impersonation. The `martis.impersonation.duration` middleware (registered automatically on every protected Martis route) compares `started_at` against `now()` and calls `stop()` when the window has elapsed. Use it to prevent forgotten impersonations from leaking access. v1.8.8. |

## Defining the gate

Drop this in `App\Providers\AuthServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('martis-impersonate', function ($user) {
    return $user?->isSuperAdmin();
    // Or: $user?->hasRole('support-lead');
    // Or: $user?->email && str_ends_with($user->email, '@yourcompany.com');
});
```

The closure receives the **operator** (not the target). The package does not pass the target to the gate — that check happens server-side after the user is loaded so the gate can stay simple.

## REST surface

| Method | Path | Returns |
|---|---|---|
| `GET` | `/martis/api/impersonation/status` | Snapshot — `{ active, enabled, original, target, started_at }` |
| `POST` | `/martis/api/impersonation/start/{userId}` | Snapshot after starting (200), or `503 / 403 / 404 / 422` |
| `POST` | `/martis/api/impersonation/stop` | Snapshot after stopping (200) |

### Snapshot shape

```json
{
  "active": true,
  "enabled": true,
  "original": { "id": 1, "label": "Operator Smith" },
  "target":   { "id": 7, "label": "Target Jones" },
  "started_at": "2026-04-27T15:42:11+00:00"
}
```

`label` is the user's `name`, falling back to `email`, falling back to `null`. `started_at` is ISO 8601 UTC. The frontend banner uses these fields directly.

### Start — error matrix

| HTTP | Cause |
|---|---|
| 503 | Master switch off. |
| 403 | `martis-impersonate` gate returned false. |
| 404 | Target user id does not exist on the configured guard's user provider. |
| 422 | Operator tried to impersonate themselves, **or** impersonation is already active (chaining is not supported on purpose). |
| 200 | Started — body is the active snapshot. |

### Stop

`POST /martis/api/impersonation/stop` always returns 200 and the (now-inactive) snapshot. Calling it when no impersonation is running is a no-op.

## Frontend banner

The package ships a fully functional banner — `resources/js/components/ImpersonationBanner.tsx` — that the bundled `Layout.tsx` renders right above every page (`<main class="martis-shell-content"> > <ImpersonationBanner />`). It polls `/martis/api/impersonation/status` on every shell mount, surfaces the current target's label + a "Stop impersonating" button, and reloads the SPA on stop. Consumers do not need to wire anything for the basic flow.

### Overriding the banner

Register a custom React component under the canonical registry key from your consumer `boot.ts`:

```ts
import { componentRegistry } from '@/lib/componentRegistry'
import { MyBrandedImpersonationBanner } from './components/MyBrandedImpersonationBanner'

componentRegistry.register('impersonation:banner', MyBrandedImpersonationBanner)
```

The Layout resolves the override on every render (same pattern as `loader`, `martis:profile-sessions`, `martis:drawer-create`, `auth:login`); a missing registration falls back to the bundled default. Use this when you want to:

- Add an audit-reason chip ("Impersonating to investigate ticket #123").
- Swap the colour for a different security accent.
- Render the banner inside a different shell slot.

Your override is responsible for fetching `/martis/api/impersonation/status` itself — there is no shared store. Polling cadence and stop UX are entirely yours.

## Trigger UX

Two common patterns to surface the action:

1. **Inline action on the Users resource** — register an Action that posts to `/martis/api/impersonation/start/{id}` for the selected user. Restrict via `canSee()` to the gate.
2. **User-detail toolbar button** — render a button in `ResourceDetail` for the User resource, gated by `martis-impersonate`.

Either way the call is a plain POST — no special middleware.

## Per-target blacklist (`NotImpersonable`)

Some users must never be impersonated — system / API accounts, super-admins, anything where letting an operator borrow the identity would be a security footgun. Mark them with the `Martis\Contracts\NotImpersonable` interface:

```php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Martis\Contracts\NotImpersonable;

class SystemAccount extends Authenticatable implements NotImpersonable
{
}
```

`ImpersonationManager::start()` checks for the interface before mutating the session and rejects with `RuntimeException`, which the controller surfaces as `422 { message: "This user cannot be impersonated." }`. The check runs server-side; the operator's UI is unchanged. You typically pair this with a `canSee()` clause on the trigger button (the row never shows the option) for the cleanest UX.

## Audit logging

Since v1.8.8 every successful `start()` / `stop()` is recorded into the `martis_action_events` audit log automatically. The Martis-shipped listener subscribes to two events (also new in v1.8.8) and writes one row per dispatch:

| Event | Action `name` |
|---|---|
| `Martis\Impersonation\Events\ImpersonationStarted` | `impersonation.started` |
| `Martis\Impersonation\Events\ImpersonationStopped` | `impersonation.stopped` |

Each row carries:

- `user_id` — the operator (the user issuing the impersonation, even after the auth guard switched to the target).
- `model_id` / `target_id` — the target user.
- `fields.target_label` — the target's `name`, falling back to `email` (mirrors the snapshot label).

Browse them under `/martis/system/action-events` (or whatever URL the bundled `ActionEventResource` lives at). Toggle the audit-row write per-environment via `MARTIS_AUDIT_IMPERSONATION=false` — the events still fire so any custom listeners you attach keep firing; only the Martis row is suppressed.

### Custom listeners

The events are public, so consumer apps can attach their own observers without subclassing the manager:

```php
use Martis\Impersonation\Events\ImpersonationStarted;
use Illuminate\Support\Facades\Event;

Event::listen(ImpersonationStarted::class, function (ImpersonationStarted $e) {
    \App\Notifications\SecurityWebhook::send([
        'operator_id' => $e->operator->getAuthIdentifier(),
        'target_id' => $e->target->getAuthIdentifier(),
    ]);
});
```

The two events live under `Martis\Impersonation\Events\*` and carry both `operator` and `target` as `Authenticatable` instances. Subscribe from `AppServiceProvider::boot()` or your own `EventServiceProvider`.

## Security notes

- The package never bypasses the gate. Removing the gate definition disables impersonation entirely.
- `start()` rejects self-impersonation and chaining (impersonator A starting impersonation as B while already impersonating C). Either path is a sign of a confused state — fail loud.
- The session marker is namespace-prefixed (`martis.impersonation`) so it cannot accidentally collide with host-app session data.
- The frontend banner must read `/martis/api/impersonation/status` on every page load. Do not cache the snapshot — a session can be stopped server-side at any moment.

## Tests

Behaviour-level coverage lives in `tests/Feature/ImpersonationControllerTest.php` (14 cases — full error matrix, NotImpersonable rejection, max-duration auto-stop via the middleware, event dispatch on start + stop). The ParitySurface tripwire (`tests/Feature/ParitySurfaceTest.php`) asserts the public surface — `ImpersonationManager::start/stop/isActive/isExpired/originalUser/currentTarget/enabled/guard/snapshot` — keeps its contract.
