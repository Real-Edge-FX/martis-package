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

## Tips

- The test playground seeds a `readonly@martis.local` user whose every
  policy write ability returns false. Use it (together with the
  `tests/e2e/authorization.spec.ts` suite in `martis-playground`) as a
  smoke test for new features — if the readonly user can still cause
  mutations, the feature is missing an authorization gate.
- A single `Policy::before(User $user, string $ability): ?bool` short-
  circuits all checks when it returns non-null. Useful for super-admin
  flags.
