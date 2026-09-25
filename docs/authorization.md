# Authorization & Policies

Martis uses the standard Laravel policy system. Every write-side
endpoint consults a Laravel policy; the frontend receives the resolved
booleans and hides or disables controls accordingly. The backend remains
the source of truth — every request is re-authorized server-side even if
the UI was already hidden.

## At a glance

When **no policy** resolves for the resource, every row below permits. When a policy exists but does not define the method, the last column applies (`Resource::defaultForMissingAbility()` and the fallbacks):

| Concern | Method on `Resource` | Policy ability | Policy exists, method missing |
|---|---|---|---|
| List / index | `authorizedToViewAny` | `viewAny` | permit |
| Detail / show | `authorizedToView` | `view` | deny |
| Create form & store | `authorizedToCreate` | `create` | deny |
| Edit form & update | `authorizedToUpdate` | `update` | deny |
| Delete / soft-delete | `authorizedToDelete` | `delete` | deny |
| Restore | `authorizedToRestore` | `restore` | deny |
| Force delete | `authorizedToForceDelete` | `forceDelete` | deny |
| Replicate | `authorizedToReplicate` | `replicate` | falls back to `create` AND `update` |
| Run action | `authorizedToRunAction` | `runAction` | falls back to `update` |
| Run destructive action | `authorizedToRunDestructiveAction` | `runDestructiveAction` | falls back to `delete` |
| Attach related | `authorizedToAttach` | `attach{Model}` | permit |
| Detach related | `authorizedToDetach` | `detach{Model}` | permit |
| Attach any (parent check) | `authorizedToAttachAny` | `attachAny{Model}` | permit |
| Add related (HasMany inline create) | `authorizedToAdd` | `add{Model}` | permit |
| Update pivot row | `authorizedToUpdatePivot` | `updatePivot{Model}` | falls back to `update` |

Default behaviour:

- If no policy class is registered for the model, every ability is
  permitted. This keeps local development frictionless.
- Once a policy class exists, missing *methods* follow the defaults
  matrix (most deny; `viewAny` permits; relation abilities are permissive
  by design because attaching a model the user can already update is
  usually the right default).

### `viewAny` is the entry gate

`viewAny` is consulted **before the record query on every per-record
endpoint** as well as on the collection ones: detail / show (including the
`?context=update` form payload), update, destroy, restore, force-delete,
replicate, peek, single and bulk actions, pivot actions, and every
relationship endpoint that resolves a parent record (`has-many`, `has-one`,
`belongs-to-many` and its attachable picker, `morph-*`). A user the policy
does not allow to list a resource gets `403` from
`GET /api/resources/{resource}/{id}` before `find()` runs, for an existing
id and a missing one alike. Two consequences:

- Record ids cannot be probed (`404` vs `403`) through a resource the user
  is not allowed to see.
- A model scope that fails closed (throws when no tenant / owner is
  resolved) never turns a deep-link into a `500`: the request is refused at
  the collection gate, before the scope is evaluated.

The record-level abilities (`view`, `update`, `delete`, …) are still
checked after the query, exactly as before. A policy with `viewAny` denied
and `view` granted therefore never reaches a record any more; if you relied
on that combination for deep-links, grant `viewAny` and confine the
listing with `indexQuery()` instead.

A write through a relationship endpoint (`POST`, `PUT` and `DELETE` on
`has-many`, `has-one`, `morph-many` and `morph-one`) creates, updates or
deletes a record of the **related** resource, so it also needs that
resource's `viewAny` (v2.0), checked before the related record query, then
its `create` / `update` / `delete` ability as before. A user who cannot list
`Comment` cannot write a comment through a post's panel either, as in Nova.
`routable()` is not part of that check: a headless resource (v1.24.0, see
[Resources → routable](resources.md)) stays usable as a relation target.

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

    // Optional: whether the user may attach any tag to this post at all
    public function attachAnyTag(User $user, Post $post): bool
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

- `Field::canSee(Closure)`: hides a field from every context. Inside a
  `Repeater` row too (v1.38.0+): the field is left out of the row type's
  schema and of every row's values, is not validated, and a row never takes
  its value from the request (a stored row keeps it, a new row stores the
  field's `default()`). See
  [Repeater → Readonly, computed, hidden and immutable row fields](repeater.md#readonly-computed-hidden-and-immutable-row-fields).
  On a pivot field of a `BelongsToMany` / `MorphToMany` too (v1.38.0+): it
  is left out of the relationship's schema and of the pivot values sent
  back, is not validated, and is never written from the request (the attach
  stores its `default()`). See
  [Relationships → With Pivot Fields](relationships.md#with-pivot-fields).
  A field placed directly in a `Tab` is hidden like one anywhere else
  (v1.38.0+; before, a `Tab` ignored `canSee()` on the fields it holds
  directly: they were listed, sent, validated and written).
  Among an Action's fields too (v1.38.0+): the modal does not list it, the
  run does not validate it, and `handle()` receives its `default()` or
  nothing, whatever the request sends. See
  [Actions → Fields the request cannot set](actions.md#fields-the-request-cannot-set).
  The endpoints a form field asks on its own (the options of a relation
  picker, the option search of a `Select`, the Slug check, the `dependsOn`
  sync) answer for a hidden field exactly as for an undeclared one
  (v1.38.0+).
- `Field::canSeeForModel(Closure)` / `canSeeUsingPolicy(ability)`: hides a
  field on the records the callback denies, on every read and every write
  of those records (v1.38.0+ for the writes). See
  [Per-field authorization](#per-field-authorization).
- `Field::readonly(bool|Closure)`: renders the field without an editor, and
  the save never takes its value from the request, inside a `Repeater` row
  included (v1.38.0+).
- Relation fields (`BelongsTo`, `HasMany`, `BelongsToMany`,
  `MorphTo`, `MorphToMany`, `MorphMany`, `MorphOne`, `HasOne`) emit
  `authorizedToCreate` / `authorizedToViewAny` flags **derived from the
  target resource's policy**. The inline "Create Related" button is
  automatically hidden when the current user cannot create the target
  resource, independent of the `showCreateRelationButton()` toggle. A
  `MorphTo` flags each of its types, and its button follows the type the
  operator picked (v1.38.0+; before, it showed for every type as soon as
  one was creatable). `Tag` applies the same rule to its inline create
  (v1.38.0+).
- `BelongsToMany` / `MorphToMany` attach: `attachAny{Model}`
  (`authorizedToAttachAny()`) gates the attach as a whole. When it
  denies, the list of records to attach (`.../attachable`), the attach
  itself (one record or several) and the pickers of the attach modal's
  pivot fields answer 403 (v1.38.0+). When it allows, `attach{Model}`
  decides per record (a batch attach skips the records it denies),
  `detach{Model}` decides the detach, and `updatePivot{Model}` (falling
  back to `update`) the pivot update and the pickers of the pivot edit
  modal. The Attach button follows the field's `canAttach()` toggle.
  Before v1.38.0 the attach and the list of records to attach did not
  check `attachAny{Model}`, so a user it denied could still attach.

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

`_actionAuthorization` says, per action, whether it may run on that record:
the action's `canRun()`, then, unless it is `standalone()`, the
`runAction` policy (`runDestructiveAction` for a destructive action), the
same check the run applies. An index row maps every action the user can
see; a relationship panel row maps only the inline actions, and carries no
map when there is none. The policy is asked once per row, not per action:
the map reuses the row's `authorizedToRunAction` /
`authorizedToRunDestructiveAction` flags.

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

A field the callback hides for a record is hidden for that record on the server, like a field `canSee()` hides for the request:

- **Every read** of the record leaves its value out: the index, the detail, the update form's values, the replicate form, the rows and the record of a relationship panel (with the responses of its inline create and update), the rows of a lens and the peek card. The bytes are not in the response, so even a tampered React layer cannot read the masked field.
- **Every write** of the record neither validates the field nor takes its value from the request: the request is accepted and the column keeps its value. This covers the resource update and create, the inline create, the inline forms of the `HasMany` / `HasOne` / `MorphMany` / `MorphOne` panels, and the attach and pivot update of the pivot fields of a `BelongsToMany` / `MorphToMany` (the attach stores the field's `default()`), as Nova leaves a field out of the update request of the resource instance it cannot see.
- **A create** decides on the new, unsaved model before any value of the request is written to it, as Nova resolves the fields of a create on a fresh model: a callback that grants on stored values (an owner column, `$model->exists`, a policy that reads them, as `viewEmail` above may for a user not created yet) denies, and the create does not write the field. Grant on `! $model->exists` when a create should write it.
- **A pivot field** decides on the pivot row (the relationship's pivot class; `pivotParent` is the parent record): the attached row when the pivot values are read or updated, a new row on attach.
- **A relationship field** (`HasMany`, `HasOne`, `MorphMany`, `MorphOne`, `BelongsToMany`, `MorphToMany`) the callback hides for the parent record answers 404 on every endpoint of its panel, as an undeclared relationship.
- **The endpoints a form field asks on its own** (relation picker options, `Select` search, Slug check, `dependsOn` sync) answer for a field hidden for the record the update form edits, or for the new model of a create form, exactly as for an undeclared field.

The schema describes the resource, not a record, so a form still lists a field the callback hides for the record it shows: its value is empty, and the save ignores it. See [Fields → Field authorization](fields.md#field-authorization-cansee-and-canseeformodel).

Before v1.38.0 the callback only ran when the resource's own endpoints serialised a record (index, detail, update form values, replicate): every write validated the field and wrote the value the request sent, so a user who could not see a record's `email` could still set it with a `PUT` (and the update form, which sends every field it lists, emptied it); the relationship panels, the lens rows and the peek card sent the value; a relationship field it hid still served its panel; and a pivot field's callback was never asked.

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

Off by default. Flip `MARTIS_AUDIT_AUTHZ_DENIALS=true` to record every Gate denial for an authenticated user into the `martis_action_events` audit table. The recorder listens to Laravel's `GateEvaluated` event, so it sees checks that go through the Gate (Tools, Dashboards, your own `$user->can()` calls) but **not** Resource checks, which call the policy methods directly (see [How policy instances are resolved](#how-policy-instances-are-resolved)). Each row carries:

- `name = authz.denied`
- `user_id` — the user the check ran for.
- `fields.ability` — the ability name.
- `fields.model_class` / `fields.model_id` — the target row when the gate received a Model argument.
- `status = denied`.

Repeat denials of the same `(user, ability, model)` within one request are de-duplicated to a single row, so a page that runs many redundant checks does not flood the table.

The log's `user_id` names a user of the Martis guard (the `ActionEvent::user()` relation resolves that guard's model), so a denial is recorded only while the Martis guard is the request's guard: in a panel request, and in every request when the Martis guard is the app's default. With a custom `MARTIS_GUARD`, the site's own requests record none (v2.0.0+): their user belongs to another guard, whose id the log would resolve to someone else.

The noisy `viewAny` cascade (sidebar / navigation) is dropped by default. Toggle `MARTIS_AUDIT_AUTHZ_DENIALS_INCLUDE_VIEWANY=true` to keep it.

## How policy instances are resolved

Every check on a resource (`authorizedToView()`, the `_authorization` block, action visibility, relationship abilities) and on a Tool or Dashboard (`authorizedToSee()`) goes through `resolvePolicy()`:

1. `public static ?string $policy` on the class.
2. Convention: `{martis.policy_namespace}\{BaseName}Policy` (`UserResource` → `UserPolicy`, `ProLabDashboard` → `ProLabPolicy`).
3. The policy registered in Laravel's Gate (`Gate::policy(...)`, or the one Laravel guesses): for the model class on Resources, for the entity class itself on Tools and Dashboards.
4. Nothing: no policy, the [defaults](#at-a-glance) apply.

Resources call the policy methods directly (honouring the policy's own `before()`); Tools and Dashboards ask Laravel's Gate (`Gate::allows('view', [Entity::class])`), so `Gate::before()` / `after()` and `GateEvaluated` listeners (the denial audit, the per-request cache) apply to them. Since v1.36.0 Martis registers the resolved policy for the entity class with the Gate on the first check, so declaring `$policy` is enough; earlier versions hid the entity unless the host also called `Gate::policy(Entity::class, Policy::class)` by hand.

Since v1.36.0 the **outcome of that walk** (the policy class) is memoised per entity class in `Martis\Authorization\PolicyResolver`, a container-scoped service: the memo lives for one request under Octane, one job under `queue:work` and one application instance in a test suite, and dies with it. The **policy instance** is never memoised: each check asks the container for one, exactly as Laravel's Gate does on every `$user->can()`. Consequences:

- A policy may receive request-scoped dependencies through its constructor (a tenant context, the current principal, a per-request cache) and always sees the current values.
- Container bindings are honoured on the next check: `bind`, `instance()`, `singleton`, `scoped`. Want one instance per request for an expensive policy? Bind it `scoped` in your provider; Martis does not decide that for you.
- Nothing survives the application that created it, so a test suite that boots an application per test never sees a policy from an earlier test, and no `tearDown()` hook is needed.

`Resource::flushPolicyCache()` and `HasPolicy::flushPolicyCache()` still exist (they share the memo, either call clears everything). Reach for them only in a test that registers a Gate policy or swaps `$policy` *after* the class has already been resolved in that same application. Before v1.36.0 the instances themselves were cached in static arrays that outlived the application, and only Laravel Octane events flushed them; policies therefore had to be stateless to behave under PHPUnit or a queue worker.

## Per-request Gate cache

Off by default. Flip `MARTIS_AUTHZ_REQUEST_CACHE=true` and `Martis\Authorization\RequestScopedAbilityCache` records every Gate result keyed on `(user, ability, model_class, model_id)` for the duration of the request, by listening to `GateEvaluated`. It only **observes**: it does not short-circuit the Gate, and the package does not read it back yet, so enabling it does not by itself save any policy call. Resource checks (the sidebar, the schema authorization block, the per-record `_authorization` block, action visibility) call the policy directly and are not recorded at all. Host code can read the cache before a redundant check:

```php
$cached = app(\Martis\Authorization\RequestScopedAbilityCache::class)->lookup($userId, $ability, $model);

if ($cached === null) {
    // not cached yet: run the real check
}
```

The cache is request-scoped — never spans requests, never persisted. Closure-only gates that depend on `Request` state are skipped (the cache key would be ambiguous). `null` results (no policy registered) are not cached so the next call still falls through to the default behaviour.

## Revoke sessions on demote

Off by default. When `MARTIS_AUTHZ_REVOKE_SESSIONS_ON_DEMOTE=true` and the host app uses Laravel's `database` session driver, a Spatie `RoleDetachedEvent` or `PermissionDetachedEvent` triggers a session sweep on the demoted user — every active session row for that user (across all devices) is dropped. The operator (admin) stays signed in because their session row belongs to them, not to the demoted user.

Use this in regulated apps where a demotion must take immediate effect on every device the user is signed in on, without waiting for the session cookie to expire.

The sweep deletes the session rows by the demoted user's id, and Laravel's `sessions.user_id` stores no table. When the session guards of `config/auth.php` (with the Martis and the default guard) sign in users of more than one table, such as a custom `MARTIS_GUARD` whose model has its own table beside the site's `users`, that id can be another person's, so the sweep is skipped and a warning names the tables (v2.0.0+). Guards that share one table keep the sweep.

## Testing helpers

The `Martis\Testing\AssertsAuthorization` Pest / PHPUnit trait adds expressive helpers. They are thin wrappers over `$user->can()` / `$user->cannot()`, i.e. Laravel's Gate: that is what Tools and Dashboards use, while Resource checks call the policy directly, so for a Resource the helpers agree with the admin UI when the Gate resolves the same policy for the model and no `Gate::before()` / `after()` callback overrides it:

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
- `Resource::authorizedTo*()` methods call the resolved policy's methods directly (after its own `before()`), not Laravel's Gate. A host `Gate::before()` / `Gate::after()` callback (a typical super-admin shortcut) therefore does not apply to Resources: put that logic in each policy's `before()`, or in a shared base policy. The policy methods stay the single source of truth, but `$user->can('update', $post)` can differ from the admin UI when a Gate callback or a different Gate-registered policy is involved.
- For testing, the package ships an `AssertsAuthorization` Pest trait. See [Testing helpers](#testing-helpers) below.
