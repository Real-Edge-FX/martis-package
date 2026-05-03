# Authorization & Policies

Martis uses the standard Laravel policy system. Every write-side
endpoint consults a Laravel policy; the frontend receives the resolved
booleans and hides or disables controls accordingly. The backend remains
the source of truth — every request is re-authorized server-side even if
the UI was already hidden.

## At a glance

| Concern | Method on `Resource` | Policy ability | Default when policy/method absent |
|---|---|---|---|
| List / index | `authorizedToViewAny` | `viewAny` | permit |
| Detail / show | `authorizedToView` | `view` | permit |
| Create form & store | `authorizedToCreate` | `create` | permit |
| Edit form & update | `authorizedToUpdate` | `update` | permit |
| Delete / soft-delete | `authorizedToDelete` | `delete` | permit |
| Restore | `authorizedToRestore` | `restore` | deny |
| Force delete | `authorizedToForceDelete` | `forceDelete` | deny |
| Replicate | `authorizedToReplicate` | `replicate` (fallback: `create` AND `update`) | permit |
| Run action | `authorizedToRunAction` | `runAction` (fallback: `update`) | permit |
| Run destructive action | `authorizedToRunDestructiveAction` | `runDestructiveAction` (fallback: `delete`) | permit |
| Attach related | `authorizedToAttach` | `attach{Model}` | permit |
| Detach related | `authorizedToDetach` | `detach{Model}` | permit |
| Attach any (parent check) | `authorizedToAttachAny` | `attachAny{Model}` | permit |
| Add related (HasMany inline create) | `authorizedToAdd` | `add{Model}` | permit |
| Update pivot row | `authorizedToUpdatePivot` | `updatePivot{Model}` (fallback: `update`) | permit |

Default behaviour:

- If no policy class is registered for the model, every ability is
  permitted. This keeps local development frictionless.
- Once a policy class exists, missing *methods* follow the defaults
  matrix (most deny; `viewAny` permits; relation abilities are permissive
  by design because attaching a model the user can already update is
  usually the right default).

## Writing a policy

Martis looks for policies in two places, in order:

1. The class declared on the resource via
   `public static ?string $policy = \App\Policies\PostPolicy::class;`.
2. Laravel's auto-resolved policy for the model
   (`AuthServiceProvider::$policies` or Laravel's auto-discovery).

Example:

```php
<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Post $post): bool
    {
        return $user->id === $post->author_id || $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function update(User $user, Post $post): bool
    {
        return $user->id === $post->author_id;
    }

    public function delete(User $user, Post $post): bool
    {
        return $user->is_admin;
    }

    // Optional — action authorization
    public function runAction(User $user, Post $post): bool
    {
        return $this->update($user, $post);
    }

    public function runDestructiveAction(User $user, Post $post): bool
    {
        return $this->delete($user, $post);
    }

    // Optional — pivot table writes on a belongsToMany / morphToMany
    public function updatePivotTag(User $user, Post $post, \App\Models\Tag $tag): bool
    {
        return $this->update($user, $post);
    }

    // Optional — attach / detach on belongsToMany / morphToMany
    public function attachTag(User $user, Post $post, \App\Models\Tag $tag): bool
    {
        return $this->update($user, $post);
    }

    public function detachTag(User $user, Post $post, \App\Models\Tag $tag): bool
    {
        return $this->update($user, $post);
    }
}
```

## HTTP responses

Every Martis controller returns **HTTP 403** with a JSON body of
`{ "message": "This action is unauthorized.", "errors": [] }` when the
policy denies. The frontend translates the generic 403 into a
**localised "Not authorized"** toast via `error_forbidden` in the
`messages.php` language files.

Use `ApiError#isForbidden()` in custom frontend code to branch on
authorization failures specifically.

## Visibility of non-record primitives

Every dashboard primitive supports a `canSee(Closure)` callback.

| Class | canSee? | Exposed to UI |
|---|---|---|
| `Martis\Filters\Filter` | ✓ | Stripped from schema when false |
| `Martis\Metrics\Metric` | ✓ | Stripped from dashboard payload when false |
| `Martis\Dashboards\Dashboard` | ✓ | Stripped from navigation when false |
| `Martis\Cards\Card` | ✓ | Stripped from dashboard payload when false |
| `Martis\Lenses\Lens` | ✓ | Stripped from schema when false |

## Fields and relations

- `Field::canSee(Closure)` — hides a field from every context.
- `Field::readonly(bool|Closure)` — renders the field without an editor.
- Relation fields (`BelongsTo`, `HasMany`, `BelongsToMany`,
  `MorphTo`, `MorphToMany`, `MorphMany`, `MorphOne`, `HasOne`) emit
  `authorizedToCreate` / `authorizedToViewAny` flags **derived from the
  target resource's policy**. The inline "Create Related" button is
  automatically hidden when the current user cannot create the target
  resource, independent of the `showCreateRelationButton()` toggle.

## UI flag contract

Each record serialized for the index or detail pages carries:

```jsonc
{
  "_authorization": {
    "authorizedToView": true,
    "authorizedToUpdate": false,
    "authorizedToDelete": false,
    "authorizedToReplicate": false,
    "authorizedToRunAction": false,
    "authorizedToRunDestructiveAction": false,
    "authorizedToRestore": false,      // only on soft-deletable resources
    "authorizedToForceDelete": false   // only on soft-deletable resources
  },
  "_actionAuthorization": {
    "publish": true,
    "archive": false
  }
}
```

Top-level schema responses carry collection-level flags under
`authorization`:

```jsonc
{
  "authorization": {
    "authorizedToViewAny": true,
    "authorizedToCreate": false
  }
}
```

The UI uses these booleans to disable / hide Create, Edit, Delete,
Restore, Force Delete, Replicate, bulk and inline action buttons. When
a control must be present but non-interactive (e.g. a bulk action in a
list where only some rows allow it), the button is rendered `disabled`.

## Design notes

- **Action authorization is closure-first, policy-second.** Martis uses
  `Action::canSee()` / `Action::canRun()` closures because they compose
  better with runtime context. If you prefer a policy lookup, implement
  `runAction` / `runDestructiveAction` on your policy — Martis consults
  it as a fallback.
- **`_authorization` key prefix.** Authorization flags are namespaced
  under `_authorization` on each record to avoid collisions with user
  model attributes.
- **`updatePivot{Model}` ability** is a Martis convention for pivot
  row edits. It falls back to `update` on the parent so existing
  policies keep working.

## Per-field authorization

Most field visibility is per-request — `Field::canSee(fn (Request $r) => …)` is enough. When the decision needs to consult the row being rendered (e.g. hiding `email` for non-admins on a User index), use the v1.8.8 model-aware variant:

```php
Email::make('email')
    ->canSeeForModel(fn (Request $r, User $user) => $r->user()?->can('viewEmail', $user) ?? false);
```

Sugar over a Gate ability:

```php
Email::make('email')->canSeeUsingPolicy('viewEmail');
// Equivalent to canSeeForModel(fn (Request, Model) => Gate::forUser($request->user())->allows('viewEmail', $model))
```

The check runs at serialization time inside `serializeModel()`, so the value never reaches the wire when the closure returns false. Stripping at serialization time means even a tampered React layer cannot read the masked field — the bytes are simply not in the response payload.

## Declarative query scopes

`Resource::indexQuery()` is the imperative hook for one-off mutations. For invariants that should compose across every list endpoint (multi-tenancy, "archived = false", "subscription_active = true"), declare them with the v1.8.8 `scopes()` method:

```php
public static function scopes(Request $request): array
{
    return [
        'tenant'  => fn (Builder $q) => $q->where('tenant_id', $request->user()->tenant_id),
        'visible' => fn (Builder $q) => $q->where('archived', false),
    ];
}
```

The labels are informational (used by future debug overlays). The order is iteration order — the array key declares a stable apply order across reloads. The controller calls `applyScopes()` BEFORE `indexQuery()` so the manual hook can override scope-applied predicates when really needed. Both surfaces feed the same Builder.

The count badge on the sidebar uses the same code path, so the scoped count always agrees with the row count on the index page.

## Audit log of denied authorizations

Off by default. Flip `MARTIS_AUDIT_AUTHZ_DENIALS=true` to record every Gate denial for an authenticated user into the `martis_action_events` audit table. Each row carries:

- `name = authz.denied`
- `user_id` — the user the check ran for.
- `fields.ability` — the ability name.
- `fields.model_class` / `fields.model_id` — the target row when the gate received a Model argument.
- `status = denied`.

Repeat denials of the same `(user, ability, model)` within one request are de-duplicated to a single row, so a page that runs many redundant checks does not flood the table.

The noisy `viewAny` cascade (sidebar / navigation) is dropped by default. Toggle `MARTIS_AUDIT_AUTHZ_DENIALS_INCLUDE_VIEWANY=true` to keep it.

## Per-request Gate cache

Off by default. Flip `MARTIS_AUTHZ_REQUEST_CACHE=true` and the package memoises every Gate result keyed on `(user, ability, model_class, model_id)` for the duration of the request. Subsequent checks read from a `Map<string, bool>` instead of re-running the policy method. Useful for non-Spatie apps where the sidebar, schema authorization block, per-record `_authorization` block, and action visibility all evaluate the same gate.

The cache is request-scoped — never spans requests, never persisted. Closure-only gates that depend on `Request` state are skipped (the cache key would be ambiguous). `null` results (no policy registered) are not cached so the next call still falls through to the default behaviour.

## Revoke sessions on demote

Off by default. When `MARTIS_AUTHZ_REVOKE_SESSIONS_ON_DEMOTE=true` and the host app uses Laravel's `database` session driver, a Spatie `RoleDetachedEvent` or `PermissionDetachedEvent` triggers a session sweep on the demoted user — every active session row for that user (across all devices) is dropped. The operator (admin) stays signed in because their session row belongs to them, not to the demoted user.

Use this in regulated apps where a demotion must take immediate effect on every device the user is signed in on, without waiting for the session cookie to expire.

## Testing helpers

The `Martis\Testing\AssertsAuthorization` Pest / PHPUnit trait adds expressive helpers that route through the same Laravel Gate Martis uses internally:

```php
uses(\Martis\Testing\AssertsAuthorization::class);

it('admins can edit any post', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->create();
    $this->assertCanUpdate($admin, $post);
    $this->assertCanDelete($admin, $post);
});

it('readers cannot mutate', function () {
    $reader = User::factory()->create();
    $post = Post::factory()->create();
    $this->assertCannotUpdate($reader, $post);
    $this->assertCannotDelete($reader, $post);
});
```

Available helpers: `assertCan` / `assertCannot` (generic), plus typed shortcuts for `view`, `viewAny`, `create`, `update`, `delete`, `restore`, `forceDelete`. Failure messages name the user, ability, and target model — instead of "Failed asserting that false is true." you get "Expected user 12 to be allowed to update Post #5, but the policy denied.".

## Tips

- A single `Policy::before(User $user, string $ability): ?bool` short-circuits all checks when it returns non-null. Useful for super-admin flags.
- Every `Resource::authorizedTo*()` method delegates to Laravel's `Gate::denies()` under the hood, so consumer code that calls `$user->can('update', $post)` directly always agrees with what the admin UI hides — single source of truth, single set of policy methods.
- For testing, the package ships an `AssertsAuthorization` Pest trait. See [Testing helpers](#testing-helpers) below.
