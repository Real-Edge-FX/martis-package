# Impersonation

> Lets a privileged operator log in as another user for the duration of a session, then return to their own account with one click.
> Shipped in v0.10. Closes the "Impersonation" gap from the Nova v5 compatibility audit.

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
],
```

| Key | Default | Purpose |
|---|---|---|
| `enabled` | `false` | Master switch. |
| `guard` | `web` | Auth guard the impersonation operates on. Most apps stay on `web`. |
| `session_key` | `martis.impersonation` | Session bag where the operator's id is stashed. Change it for cross-tenant isolation. |

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

The banner UI is the consumer's responsibility — the package ships the data, you ship the visuals. Recommended pattern:

```tsx
// resources/js/martis/ImpersonationBanner.tsx
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

export function ImpersonationBanner() {
  const [snap, setSnap] = useState<null | {
    active: boolean
    original: { label: string | null } | null
    target: { label: string | null } | null
  }>(null)
  const { t } = useTranslation('resources')

  useEffect(() => {
    fetch('/martis/api/impersonation/status').then(r => r.json()).then(setSnap)
  }, [])

  if (!snap?.active) return null

  return (
    <div role="alert" className="martis-impersonation-banner">
      {t('impersonation.banner', {
        target: snap.target?.label ?? '?',
        original: snap.original?.label ?? '?',
      })}
      <button onClick={async () => {
        await fetch('/martis/api/impersonation/stop', { method: 'POST' })
        window.location.reload()
      }}>
        {t('impersonation.stop')}
      </button>
    </div>
  )
}
```

Mount it inside your shell layout — typically right above the topbar, so it is impossible to miss.

## Trigger UX

Two common patterns to surface the action:

1. **Inline action on the Users resource** — register an Action that posts to `/martis/api/impersonation/start/{id}` for the selected user. Restrict via `canSee()` to the gate.
2. **User-detail toolbar button** — render a button in `ResourceDetail` for the User resource, gated by `martis-impersonate`.

Either way the call is a plain POST — no special middleware.

## Audit logging

The package does not write impersonation events to the action_events table by design — the audit story is yours, because every team scopes auditing differently. Recommended hook points:

- Subclass `ImpersonationManager` and override `start()` / `stop()` to emit a Laravel event.
- Listen to `Illuminate\Auth\Events\Login` and inspect the session for the impersonation marker.

Example event-driven audit:

```php
class AuditedImpersonation extends ImpersonationManager
{
    public function start(Authenticatable $target): void
    {
        parent::start($target);
        event(new ImpersonationStarted($this->originalUser(), $target));
    }

    public function stop(): void
    {
        $original = $this->originalUser();
        $target = $this->currentTarget();
        parent::stop();
        if ($original && $target) {
            event(new ImpersonationStopped($original, $target));
        }
    }
}
```

Bind your subclass instead of the default in `AppServiceProvider`:

```php
$this->app->singleton(\Martis\Impersonation\ImpersonationManager::class, AuditedImpersonation::class);
```

## Security notes

- The package never bypasses the gate. Removing the gate definition disables impersonation entirely.
- `start()` rejects self-impersonation and chaining (impersonator A starting impersonation as B while already impersonating C). Either path is a sign of a confused state — fail loud.
- The session marker is namespace-prefixed (`martis.impersonation`) so it cannot accidentally collide with host-app session data.
- The frontend banner must read `/martis/api/impersonation/status` on every page load. Do not cache the snapshot — a session can be stopped server-side at any moment.

## Tests

Behaviour-level coverage lives in `tests/Feature/ImpersonationControllerTest.php` (10 tests). The Task-18 ParitySurface tripwire (`tests/Feature/ParitySurfaceTest.php`) asserts the public surface — `ImpersonationManager::start/stop/isActive/originalUser/currentTarget/snapshot` — keeps its contract.
