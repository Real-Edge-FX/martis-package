# Invitations

> Let a privileged operator invite a new user by email — issue a single-use link, the invitee sets a password and lands in the app — instead of leaving self-service registration open to the world.

## Why this exists

Not every app wants an open `/register` page. Invite-only onboarding (agencies, internal tools, B2B admin panels, anything with a closed user base) is a different shape: a privileged operator decides who gets in, the invitee never chooses their own email, and the whole thing has to be safe against token-guessing and link-sharing. `Martis\Invitations` is that primitive — a package-owned `InvitationManager` plus a public accept screen, wired through the same `RegistersUsers` pipeline the rest of authentication uses.

## Two-layer guard

Invitations are **opt-in**. Two independent controls decide whether the feature does anything:

1. **Master switch** — `martis.invitations.enabled` (default `false`).
   Set `MARTIS_INVITATIONS_ENABLED=true` in the env to turn it on. While off, the accept routes always 503 and the resource scaffolded by the generator (see below) hides itself from navigation and routing.
2. **Authorisation gate** — `martis-invite`.
   The package registers a **deny-by-default** definition (`Gate::define('martis-invite', fn () => false)`) so an unconfigured install cannot issue invitations by accident. Define your own in `App\Providers\AuthServiceProvider::boot()` to decide who may invite:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('martis-invite', function ($user) {
    return $user?->hasRole('admin');
    // Or: $user?->is_admin;
    // Or: $user?->email && str_ends_with($user->email, '@yourcompany.com');
});
```

The gate only guards the privileged **issue** action (wherever you call `InvitationManager::invite()` from — typically the `InviteUser` action the generator scaffolds). Accepting an invitation is **token-authorized, not gate-authorized**: the invitee has no account yet, so neither `martis.auth` nor `martis-invite` apply to the public accept endpoints.

## Configuration

```php
// config/martis.php
'invitations' => [
    'enabled' => env('MARTIS_INVITATIONS_ENABLED', false),
    'expires_after_hours' => (int) env('MARTIS_INVITATIONS_TTL_HOURS', 72),
    'single_use' => true,
    'resend_throttle_seconds' => (int) env('MARTIS_INVITATIONS_RESEND_THROTTLE', 60),
    'login_after_accept' => env('MARTIS_INVITATIONS_LOGIN_AFTER_ACCEPT', true),
    'redirect_after_accept' => env('MARTIS_INVITATIONS_REDIRECT', null),
    'signup_fields' => ['name', 'password'],
    'mark_email_verified_on_accept' => true,
],

'audit' => [
    // ... other audit toggles
    'invitations' => env('MARTIS_AUDIT_INVITATIONS', true),
],
```

| Key | Default | Purpose |
|---|---|---|
| `enabled` | `false` | Master switch. Every accept endpoint 503s while off. |
| `expires_after_hours` | `72` | TTL applied when an invitation is minted. `accept()` rejects an expired token the same way it rejects an unknown one. |
| `single_use` | `true` | Documents the invariant, not a toggle — read by no code path. Single-use is enforced structurally by the atomic pending→accepted claim in `accept()` (see [Security posture](#security-posture)), so there is nothing to turn off. |
| `resend_throttle_seconds` | `60` | Minimum gap between two `resend()` calls on the same invitation, keyed off `updated_at`. Prevents notification spam from a mis-clicking operator. |
| `login_after_accept` | `true` | When `true`, `accept()` logs the new user in and regenerates the session before redirecting. When `false`, the invitee is sent to `/login` instead. |
| `redirect_after_accept` | `null` | Where a freshly logged-in invitee lands. `null` falls back to the resource index (`route('martis.index')`). |
| `signup_fields` | `['name', 'password']` | Whitelist of client-supplied fields `accept()` will read. `email` is never in this list — it always comes from the invitation record, never from the request. Add/remove fields to match what your `createUser()` override expects. |
| `mark_email_verified_on_accept` | `true` | When `true` and the user model's table has an `email_verified_at` column, it is stamped at accept time (an emailed, single-use link is itself a verification step). |
| `audit.invitations` | `true` | Toggles whether the lifecycle events also write a `martis_action_events` row (see [Events + audit](#events--audit)). The events themselves keep firing either way. |

## Lifecycle

An invitation moves through a small state machine: `pending` → `accepted` **or** `revoked`. There is no manual `expired` transition — expiry is evaluated at read time (`isExpired()`) and enforced at claim time in `accept()`.

### Issue

```php
use Martis\Invitations\InvitationManager;

$invitation = app(InvitationManager::class)->invite(
    email: 'new.hire@example.com',
    role: 'editor',       // optional; assigned to the user on accept if the model supports assignRole()
    metadata: [],          // optional opaque bag — see below
);

// $invitation->rawToken holds the plain-text token, in memory only,
// for exactly this request — build the accept URL and send it now.
```

`invite()` generates a CSPRNG token, persists only its hash, sets `expires_at` from `expires_after_hours`, records `invited_by` from the currently authenticated guard user, and fires `InvitationCreated`.

### Accept

```php
$user = app(InvitationManager::class)->accept($rawToken, [
    'name' => 'Jane Doe',
    'password' => '...',
    'password_confirmation' => '...',
]);
```

`accept()` runs inside a single DB transaction:

1. Atomically claims the invitation (`pending` → `accepted`, compare-and-set `UPDATE`). If nothing was claimed — unknown token, already accepted, revoked, or expired — it throws `InvalidInvitationException` and the transaction rolls back.
2. Rejects if the invitation's email already belongs to a registered user (anti-takeover).
3. Filters the incoming signup payload down to `signup_fields` and calls the [`createUser()` override seam](#the-override-seam-createuser).
4. Assigns the invitation's `role`, if any, via `assignRole()` when the resulting user model supports it (Spatie soft-dependency).
5. Optionally stamps `email_verified_at`.
6. Fires `InvitationAccepted` only after the transaction actually commits (`DB::afterCommit`), so a rolled-back claim never emits the event.

A validation failure inside `createUser()` (short password, mismatched confirmation, …) propagates out of `accept()` unchanged as a `ValidationException` — the transaction rolls back and the invitation returns to `pending`, so the invitee can simply retry with a fixed payload.

### Resend

```php
app(InvitationManager::class)->resend($invitation);
// $invitation->rawToken now holds a fresh plain-text token; the old one stops working.
```

Only works on a still-`pending` invitation, and is throttled by `resend_throttle_seconds` against the row's `updated_at`. Both failure modes throw `InvalidInvitationException`.

### Revoke

```php
app(InvitationManager::class)->revoke($invitation);
```

Flips a still-`pending` invitation to `revoked`, permanently blocking its accept link, and fires `InvitationRevoked`. Revoking a non-pending invitation throws `InvalidInvitationException`.

## Security posture

- **Hashed at rest.** The plain-text token is a 32-byte CSPRNG value (`random_bytes(32)`, base64url-encoded); only its SHA-256 hash is ever written to the `token` column. The plain value lives on `$invitation->rawToken` — an in-memory-only property, never fillable, never cast — for exactly the request that minted or resent it.
- **Atomic single-use.** The pending→accepted transition is a single compare-and-set `UPDATE …WHERE status = 'pending'`, not a read-then-write. The row lock it takes serializes concurrent accept attempts on the same token, so a token cannot be claimed twice even under a race.
- **Enumeration-neutral.** `GET /invitations/accept/{token}` always returns the same `200` + SPA shell, whether the token is valid, unknown, expired, revoked, or already used — the server never signals validity from the page load. The `POST` accept endpoint collapses every unacceptable state (unknown, expired, revoked, used, email-already-registered) into the same generic `InvalidInvitationException` message, so probing the endpoint cannot distinguish "no such token" from "already claimed".
- **Signup whitelist.** `accept()` only reads keys listed in `signup_fields` (plus `password_confirmation`) from the client payload. `email` is never client-controlled — it always comes from the invitation row.
- **Existing-email guard.** An invitation whose email already belongs to a registered user is rejected (and the claim rolled back) rather than silently overwriting or duplicating an account.

## The `InvitationManager` API

| Method | Signature | Notes |
|---|---|---|
| `invite` | `invite(string $email, ?string $role = null, array $metadata = []): Invitation` | Mints and persists a new pending invitation. Fires `InvitationCreated`. |
| `findByRawToken` | `findByRawToken(string $rawToken): ?Invitation` | Hashes and looks up. Used by the accept screen's `GET` to exercise the same code path for any token state without revealing which. |
| `accept` | `accept(string $rawToken, array $signup): Authenticatable` | See [Lifecycle → Accept](#accept). Throws `InvalidInvitationException` or `ValidationException`. |
| `resend` | `resend(Invitation $invitation): void` | See [Lifecycle → Resend](#resend). Throws `InvalidInvitationException`. |
| `revoke` | `revoke(Invitation $invitation): void` | See [Lifecycle → Revoke](#revoke). Throws `InvalidInvitationException`. |
| `createUser` | `protected createUser(Invitation $invitation, array $signup): Authenticatable` | **The single override seam.** See below. |

### The override seam: `createUser()`

`InvitationManager::createUser()` is the only method meant to be overridden. Its default implementation builds an in-memory `Request` (name/password from the whitelisted signup payload, email authoritative from the invitation) and delegates to the same `Martis\Contracts\RegistersUsers` binding self-service registration uses — one registration pipeline, one place to change validation, hashing, and default-role assignment app-wide.

Subclass the manager when an invitation needs to shape the user differently from a self-registered one — for example, carrying arbitrary data through `metadata` and writing it onto the new user:

```php
namespace App\Invitations;

use Illuminate\Contracts\Auth\Authenticatable;
use Martis\Invitations\Invitation;
use Martis\Invitations\InvitationManager;

class AppInvitationManager extends InvitationManager
{
    protected function createUser(Invitation $invitation, array $signup): Authenticatable
    {
        $user = parent::createUser($invitation, $signup);

        // $invitation->metadata is whatever opaque bag invite() was called with.
        if ($departmentId = $invitation->metadata['department_id'] ?? null) {
            $user->forceFill(['department_id' => $departmentId])->save();
        }

        return $user;
    }
}
```

Rebind it in your service provider so the package resolves your subclass everywhere:

```php
$this->app->bind(InvitationManager::class, AppInvitationManager::class);
```

Do **not** reach for this seam for app-wide registration changes — rebind `RegistersUsers` instead (see [authentication.md](authentication.md)). Override `createUser()` only for behaviour specific to the invitation flow (reading `metadata`, skipping a step self-registration always does, etc).

## Events + audit

Three events, one per lifecycle transition, all carrying the `Invitation`:

| Event | Fired by | Extra payload |
|---|---|---|
| `Martis\Invitations\Events\InvitationCreated` | `invite()` | — |
| `Martis\Invitations\Events\InvitationAccepted` | `accept()`, after commit | `Authenticatable $user` |
| `Martis\Invitations\Events\InvitationRevoked` | `revoke()` | — |

The package's own `Martis\Invitations\Listeners\RecordInvitation` subscribes to all three and writes a `martis_action_events` row per transition (`invitation.created` / `invitation.accepted` / `invitation.revoked`), skipping silently when the audit table is missing. Toggle the row write off per-environment with `MARTIS_AUDIT_INVITATIONS=false` — the events still fire, so any listener you attach keeps firing; only the built-in audit row is suppressed.

Attach your own listeners the same way you would for any Laravel event:

```php
use Illuminate\Support\Facades\Event;
use Martis\Invitations\Events\InvitationAccepted;

Event::listen(InvitationAccepted::class, function (InvitationAccepted $e) {
    \App\Notifications\SecurityWebhook::send([
        'invited_email' => $e->invitation->email,
        'user_id' => $e->user->getAuthIdentifier(),
    ]);
});
```

## The accept-URL seam (`InvitationUrl`)

The URL an invite notification points at is built through a static, overridable seam, mirroring Laravel's own `Illuminate\Auth\Notifications\ResetPassword::createUrlUsing()`:

```php
use Martis\Invitations\Invitation;
use Martis\Invitations\InvitationUrl;

InvitationUrl::createUrlUsing(function (Invitation $invitation, string $rawToken): string {
    return 'https://accounts.example.com/invite/'.$rawToken;
});
```

`MartisServiceProvider::boot()` seeds the package default (`route('martis.invitations.accept', $rawToken)`) automatically, but only when `martis.invitations.enabled` is true and no callback is already registered — so a consumer's own registration (in `AppServiceProvider::boot()`, run before the package's) always wins. Reach for this when the accept link needs to point somewhere other than the bundled SPA route: an off-platform signup page, a deep link into a mobile app, or a URL that needs extra query parameters.

Pass `null` to reset to the package default.

## Public accept surface

Two routes, always registered regardless of the master switch (so the route table stays predictable across environments) — both 503 while `martis.invitations.enabled` is `false`:

| Method | Path | Handler |
|---|---|---|
| `GET` | `/{martis-path}/invitations/accept/{token}` | Renders the SPA shell — same response for any token state. |
| `POST` | `/{martis-path}/api/invitations/accept` | Validates the signup payload, delegates to `InvitationManager::accept()`, logs the invitee in (unless `login_after_accept` is `false`), and returns `{ ok, redirect, user? }` (JSON) or a redirect (non-JSON). |

`token`, plus each configured `signup_fields` entry, plus `password` (always `confirmed`, `Password::min(8)`) are the only fields the `POST` endpoint validates. An `InvalidInvitationException` from the manager becomes a neutral `422 { message, errors: { token: [message] } }` (JSON) or a redirect back to `/login` with a flashed error (non-JSON) — the same shape regardless of which invalid state it was.

## React accept screen

The package ships a working accept screen — `resources/js/pages/InvitationAccept.tsx` — mounted at `/invitations/accept/:token`. It renders the set-password form optimistically (the `GET` never reveals whether the token is valid) and only learns the token was bad from the `POST` response's `errors.token` key, at which point it swaps to a neutral "invitation link invalid" state. Every other `422` field error stays inline so the invitee can fix and retry. On success it follows the JSON envelope's `redirect` with a full page navigation (the accept call just changed session state, so a hard navigation is what picks up the new auth state).

Only the default `signup_fields` (`name`, `password`) render — matching the controller's default validation. Ships translated in `en`, `pt_PT`, and `pt_BR`.

### Overriding the screen

Register a replacement under the `auth:invitation-accept` key from your consumer extension bundle:

```ts
import { componentRegistry } from '@/lib/componentRegistry'
import { MyInvitationAccept } from './components/MyInvitationAccept'

componentRegistry.register('auth:invitation-accept', MyInvitationAccept)
```

The router resolves this key the same way it resolves `auth:login`, `auth:register`, and the other auth-page slots — a missing registration falls back to the bundled default. There is no dedicated `martis:component --type=` scaffold for this slot yet; the registry key works for any string, scaffolded or not, so registering it directly (as above) is the supported path.

### Copy overrides

Two of the same two paths every other auth screen supports (see [authentication.md § Customising the auth copy](authentication.md#customising-the-auth-copy)):

- **Path 1 (lang files)** — `invitation_accept_title` / `invitation_accept_sub` keys in the published `auth.php` lang file.
- **Path 2 (config)** — `config('martis.auth.copy.invitation_accept.title')` / `.subtitle`, string or `array<locale, string>`.

## The `martis:invitations` generator

```bash
php artisan martis:invitations
```

Everything above ships inside the package. What cannot live there — because it belongs in your application's own namespace and is meant to be edited — is scaffolded by this one-shot generator:

| Generated | Path (default) | Purpose |
|---|---|---|
| `InvitationResource` | `app/Martis/Resources/InvitationResource.php` | Index of issued invitations in the **System** sidebar group. Hides from navigation and routing while invitations are disabled. |
| `InviteUser` | `app/Martis/Resources/Actions/InviteUser.php` | Standalone action — email + optional role picker, calls `InvitationManager::invite()`, sends the notification. Gated on `martis-invite`. |
| `ResendInvitation` | `app/Martis/Resources/Actions/ResendInvitation.php` | Row action — calls `InvitationManager::resend()` and re-sends the notification for each selected pending invitation. |
| `RevokeInvitation` | `app/Martis/Resources/Actions/RevokeInvitation.php` | Row action — calls `InvitationManager::revoke()` for each selected pending invitation. |
| `InvitationPolicy` | `app/Policies/InvitationPolicy.php` | Admin-only policy, auto-registered in `AuthServiceProvider::boot()`. `create()` always returns `false` — invitations are only ever issued through `InviteUser`, never the generic resource create form. |
| `UserInvitation` | `app/Notifications/UserInvitation.php` | The invite email, delivered via an on-demand mail route (the invitee has no account to notify yet). Built from `InvitationUrl::url()`. |

It also publishes the `create_invitations_table` migration (a portable, key-type-aware stub — matches whatever primary-key shape your `users` table uses), runs it, and — if you published `config/martis.php` before the `invitations` block existed — inserts the block for you. Every step is idempotent; re-running the command is a no-op once the app is set up, and nothing is overwritten without `--force`.

**Options:**

| Option | Default | Purpose |
|---|---|---|
| `--user=` | `App\Models\User` | Fully-qualified user model class, used to type-hint the generated policy. |
| `--namespace=` | `App\Martis\Resources` | Namespace for the generated resource + actions. |
| `--no-install` | — | Skip the advisory note about the optional `spatie/laravel-permission` role picker. |
| `--no-migrate` | — | Skip running migrations after publishing them. |
| `--no-publish` | — | Skip publishing the invitations migration. |
| `--force` | — | Overwrite existing resource / action / policy / notification files. |

After the command finishes: set `MARTIS_INVITATIONS_ENABLED=true`, define the `martis-invite` gate, and visit the System sidebar group.

## Recipes

### Consumer-land recipe (not shipped in the package)

Multi-tenant SaaS admin: every invitation belongs to a tenant, the invited user must land inside that tenant, and only tenant admins may invite within their own tenant. None of this is package behaviour — it's composed entirely from the seams above.

**1. Carry the tenant on the invitation and read it back in `createUser()`:**

```php
namespace App\Invitations;

use Illuminate\Contracts\Auth\Authenticatable;
use Martis\Invitations\Invitation;
use Martis\Invitations\InvitationManager;

class TenantInvitationManager extends InvitationManager
{
    protected function createUser(Invitation $invitation, array $signup): Authenticatable
    {
        $user = parent::createUser($invitation, $signup);

        $user->forceFill([
            'tenant_id' => $invitation->metadata['tenant_id'] ?? null,
        ])->save();

        return $user;
    }
}
```

```php
// App\Providers\AppServiceProvider::register()
$this->app->bind(
    \Martis\Invitations\InvitationManager::class,
    \App\Invitations\TenantInvitationManager::class,
);
```

Issue an invitation carrying the current operator's tenant:

```php
app(InvitationManager::class)->invite(
    email: $email,
    role: $role,
    metadata: ['tenant_id' => auth()->user()->tenant_id],
);
```

**2. Scope who may invite to their own tenant:**

```php
// App\Providers\AuthServiceProvider::boot()
Gate::define('martis-invite', fn ($user) => $user?->tenant_id !== null && $user->hasRole('admin'));
```

**3. Scope the generated resource to the current tenant** (edit the generated `InvitationResource`):

```php
public static function indexQuery(Request $request, Builder $query): Builder
{
    return $query->where(
        'metadata->tenant_id',
        $request->user()->tenant_id,
    );
}
```

**4. Restrict the role picker to roles the current tenant actually uses** (edit the generated `InviteUser` action's `fields()`):

```php
Select::make('role', __('Role'))
    ->options(fn (Request $request) => \Spatie\Permission\Models\Role::query()
        ->where('tenant_id', $request->user()->tenant_id)
        ->pluck('name', 'name')
        ->all()),
```

Nothing here required forking the package: one manager subclass, one gate definition, one `indexQuery()` override, and one field option callback.

## Tests

Behaviour-level coverage lives across `tests/Feature/Invitation*Test.php`: `InvitationModelTest`, `InvitationConfigTest`, `InvitationManagerTest`, `InvitationControllerTest`, `InvitationAuditTest`, `InvitationsMigrationTest`, `InvitationsScaffoldCommandTest` — the full state-machine, security-invariant, and generator-idempotency matrix. The React accept screen has its own coverage in `resources/js/pages/InvitationAccept.test.tsx`.
