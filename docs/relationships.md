# Relationships

This guide covers all relationship field types in Martis and how to use them.

## Overview

Martis provides the full set of relationship field types that map 1:1 to Laravel Eloquent relationships:

| Field | Eloquent Relationship |
|-------|-----------------------|
| `BelongsTo` | `belongsTo()` |
| `HasOne` | `hasOne()` |
| `HasOneOfMany` | `hasMany()->latestOfMany()` / `ofMany(...)` |
| `HasOneThrough` | `hasOneThrough()` |
| `HasMany` | `hasMany()` |
| `HasManyThrough` | `hasManyThrough()` |
| `BelongsToMany` | `belongsToMany()` |
| `Tag` | `belongsToMany()` (chip UI) |
| `MorphTo` | `morphTo()` |
| `MorphOne` | `morphOne()` |
| `MorphOneOfMany` | `morphMany()->latestOfMany()` / `ofMany(...)` |
| `MorphMany` | `morphMany()` |
| `MorphToMany` | `morphToMany()` |

---

## Toolbar hide flags (cross-cutting)

All relationship fields share the `ControlsRelationshipToolbar` trait, which
exposes nine `->hideXxx()` setters that let a programmer **hide** a piece of
the panel's UI. Visibility composes with authorization as:

    visible = authorized AND NOT hidden

Authorization is always the source of truth — the setters cannot *force*
something to appear. Unauthorized actions are never shown.

| Setter | Hides |
|--------|-------|
| `->hideSearch()` | Search input in the panel toolbar |
| `->hideCreateButton()` | Create / Attach button |
| `->hidePerPageSelector()` | "Per page" dropdown |
| `->hideSoftDeleteToggle()` | Active / With trashed / Only trashed dropdown |
| `->hideViewAction()` | Eye icon in the actions column |
| `->hideEditAction()` | Pencil icon in the actions column |
| `->hideDeleteAction()` | Trash icon in the actions column |
| `->hideRestoreAction()` | Restore icon on trashed rows |
| `->hideForceDeleteAction()` | Force-delete icon on trashed rows |

```php
HasMany::make('Comments', 'comments')
    ->hideSoftDeleteToggle()       // never show trashed filter
    ->hideForceDeleteAction()      // permanent deletion is never exposed
```

---

## Relationship panel anatomy

All `-Many` relation panels (`HasMany`, `MorphMany`, `BelongsToMany`, `MorphToMany`
and their Through variants) are rendered by a single shared React component,
`RelationshipTableShell`. Understanding its layout makes the hide flags and
pivot slots predictable across fields.
*resources/js/components/fields/relation/RelationshipTableShell.tsx*

### Panel layout

From top to bottom:

1. **Heading row** — related-resource icon (from `ResourceIcon`), the field
   title, and a count badge with the total number of related rows. When
   `collapsable()` is on, the heading also carries the caret toggle.
2. **Toolbar** — search input, primary *Create* (or *Attach*) button, an
   optional slot for consumer-supplied extras, the per-page selector, and the
   soft-delete dropdown (*Active / With trashed / Only trashed*).
3. **DataTable** — the list itself (see columns below).
4. **Pagination** — rendered only when the server returns a paginator.

### Responsive toolbar (container queries)

The toolbar reflows using **CSS container queries** on the class
`martis-relation-toolbar`, not viewport media queries. This means the layout
adapts to the width of the *panel's container*, so a relation panel inside a
narrow tab wraps even when the window is wide.

| Container width | Layout |
|-----------------|--------|
| `>= 48rem` | Single row: heading on the left, `[search + create + extras + per-page + trashed]` on the right. |
| `< 48rem` | Two rows: row 1 = heading + search + create; row 2 = per-page on the left, soft-delete dropdown on the right. |

### Columns (rendered in order)

1. **Selection checkbox** — only when `selectable` is passed (used by
   `BelongsToMany` / `MorphToMany` for bulk pivot actions).
2. **"Archived" chip** — only rendered when at least one row in the current
   page has a non-null `deleted_at`. Pure visual tag, no action.
3. **`indexFields`** — resolved from the related resource's
   `fieldsForIndex()`. Rendered through `FieldDisplay`.
4. **`pivotFields`** — `BelongsToMany` and `MorphToMany` only. Each pivot
   column reads its value from `row._pivot.{attribute}`.
5. **Actions column** — View / Edit / Delete, plus Restore and Force-delete
   on trashed rows, plus any `rowActionsExtras(row)` the consumer returns.

Trashed rows are rendered with `opacity-60` and swap Edit/Delete for
Restore + Force-delete (icons only; confirmation modals are handled by the
shell itself).

### Slots consumers plug into

| Slot | Type | Purpose |
|------|------|---------|
| `pivotFields` | `FieldDefinition[]` | Extra columns pulled from `row._pivot.*`. BelongsToMany/MorphToMany pipe their pivot fields in here. |
| `selectable` + `selectedRows` + `onSelectionChange` | `boolean` + `ResourceRecord[]` + callback | Controlled multi-select. When `selectable` is on the shell renders a checkbox column and reports the selection upward. |
| `toolbarExtras` | `ReactNode \| (ctx: { selectedRows }) => ReactNode` | Rendered in the primary toolbar after *Create*. The render-prop form receives the current selection — BelongsToMany/MorphToMany use it to show pivot action dropdowns and an *Attach* button with a "N selected" counter. |
| `rowActionsExtras` | `(row) => ReactNode` | Per-row extras, appended to the action icons. |
| `createUrl` / `editUrl` / `viewUrl` / `deleteUrl` | URL builders | Endpoint/route overrides so the same shell powers HasMany (inline CRUD) and BelongsToMany (attach/detach) transparently. |

### Field ↔ slot usage matrix

| Field | Uses | Why |
|-------|------|-----|
| `HasMany` / `HasManyThrough` | (shell defaults only) | Plain inline CRUD; no pivot, no multi-select. |
| `MorphMany` | (shell defaults only) | Same as HasMany but polymorphic. |
| `BelongsToMany` | `pivotFields` + `selectable` + `toolbarExtras(ctx)` + `rowActionsExtras` | Pivot columns + bulk pivot actions + *Attach* button + per-row Detach/edit-pivot. |
| `MorphToMany` | `pivotFields` + `selectable` + `toolbarExtras(ctx)` + `rowActionsExtras` | Same as BelongsToMany, polymorphic pivot. |
| `HasOne` / `MorphOne` / `*OfMany` / `HasOneThrough` | n/a — uses a dedicated single-record panel, not the shell. |

Keep in mind: visible = authorized AND NOT hidden. The shell never
*up-grades* an unauthorized action; the `hideXxx` flags only subtract.

---

## Soft-delete filter

Relationship panels whose related resource uses `SoftDeletes` automatically
render a three-state filter in the toolbar — **Active / With trashed /
Only trashed**. Trashed rows show **Restore** and **Force-delete** actions
instead of Edit/Delete.

The default state comes from `config/martis.php`:

```php
'default_trashed_filter' => 'active', // 'active' | 'with' | 'only'
```

Visibility follows the usual gate — `Resource::canViewTrashed()` must return
`true` (default) AND the programmer must not call `->hideSoftDeleteToggle()`.

---

## BelongsTo

A many-to-one relationship. Stores a foreign key on the parent model and displays the related record's title.

```php
BelongsTo::make('user_id', 'Author')
    ->relatedResource('users')
    ->titleAttribute('name')
    ->displayAsLink()
    ->sortable()
```

See [fields.md — BelongsTo](fields.md#belongsto) for full API reference.

---

## HasMany

A one-to-many relationship. Renders as an inline DataTable on the detail page with full CRUD (create, edit, delete) for child records.

```php
HasMany::make('Comments', 'comments')
    ->relatedResource('comments')
    ->collapsable()
    ->collapsedByDefault()
```

See [fields.md — HasMany](fields.md) for full API reference.

---

## BelongsToMany

A many-to-many pivot relationship field. Renders as a DataTable panel on the detail page with attach/detach, pivot field editing, search, and pagination.

### Basic Usage

```php
BelongsToMany::make('Tags')
    ->relatedResource('tags')
```

### With Pivot Fields

Pivot fields are extra columns stored in the pivot table.

**Migration:**
```php
Schema::create('post_tag', function (Blueprint $table) {
    $table->foreignId('post_id')->constrained()->onDelete('cascade');
    $table->foreignId('tag_id')->constrained()->onDelete('cascade');
    $table->string('notes')->nullable();
    $table->primary(['post_id', 'tag_id']);
});
```

**Model:**
```php
class Post extends Model
{
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->withPivot(['notes'])
            ->withTimestamps();
    }
}
```

**Resource:**
```php
BelongsToMany::make('Tags', 'tags')
    ->relatedResource('tags')
    ->searchable()
    ->fields(fn () => [
        Text::make('notes', 'Notes')->nullable(),
    ])
```

### Full Configuration

```php
BelongsToMany::make('Roles', 'roles', RoleResource::class)
    ->searchable()                      // enable search in attach modal
    ->collapsable()                     // make panel collapsable
    ->collapsedByDefault()              // start collapsed
    ->allowDuplicateRelations()         // allow same record attached twice
    ->showCreateRelationButton()        // inline create button in modal
    ->modalSize('3xl')                  // modal size
    ->withSubtitles()                   // show subtitles in search results
    ->dontReorderAttachables()          // keep DB order in attachable list
    ->relatableQueryUsing(fn ($req, $q) => $q->where('active', true))
    ->fields(fn () => [
        Text::make('notes', 'Notes')->nullable(),
        Date::make('expires_at', 'Expires At')->nullable(),
    ])
    ->perPage(15)
    ->canAttach(fn () => auth()->user()?->isAdmin())
    ->canDetach(fn () => auth()->user()?->isAdmin())
```

### Authorization

```php
// In your Resource class:
public function authorizedToAttach(Request $request, Model $related): bool
{
    return $request->user()?->can('attach', [$this->model(), $related]) ?? false;
}

public function authorizedToDetach(Request $request, Model $related): bool
{
    return $request->user()?->can('detach', [$this->model(), $related]) ?? false;
}
```

If these methods are absent, falls back to `authorizedToUpdate()`.

### API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/resources/{resource}/{id}/belongs-to-many/{relationship}` | List attached records |
| `GET` | `/api/resources/{resource}/{id}/belongs-to-many/{relationship}/attachable` | List attachable records |
| `POST` | `/api/resources/{resource}/{id}/belongs-to-many/{relationship}/attach` | Attach record |
| `DELETE` | `/api/resources/{resource}/{id}/belongs-to-many/{relationship}/{relatedId}/detach` | Detach record |
| `PUT` | `/api/resources/{resource}/{id}/belongs-to-many/{relationship}/{relatedId}/pivot` | Update pivot |

**Attach payload:**
```json
{
    "related_id": 5,
    "notes": "Optional pivot field value"
}
```

**Update pivot payload:**
```json
{
    "notes": "Updated notes"
}
```

---

## Tag (BelongsToMany chip UI)

A many-to-many relationship rendered as editable tag chips with autocomplete. Best for flat, unordered tag-like associations with no pivot data.

```php
Tag::make('tags', 'Tags')
    ->relatedResource('tags')
    ->titleAttribute('name')
    ->withPreview()
    ->preload()
```

Use `BelongsToMany` instead of `Tag` when you need pivot fields, a full DataTable listing, or search/sort/pagination controls.

---

## MorphTo

A polymorphic relationship. The parent model can belong to different model types via a single FK pair (`_type` + `_id`).

```php
MorphTo::make('commentable_id', 'Commentable')
    ->types([PostResource::class, VideoResource::class])
    ->nullable()
```

See [fields.md — MorphTo](fields.md#morphto) for full API reference.

---

---

## HasOne

A one-to-one relationship. Displays and manages a single related record via an Eloquent `hasOne` relationship. The related record is shown as a read-only panel on the detail page, with optional Create / Edit / Delete controls.

**Detail-only by default** — hidden from index and forms.

```php
use Martis\Fields\HasOne;

HasOne::make('Profile')
HasOne::make('Profile', 'profile')
HasOne::make('Profile', 'profile', ProfileResource::class)
    ->canCreate(false)
    ->canUpdate(false)
    ->canDelete(false)
```

See [fields.md § HasOne](fields.md#hasone) for the full API.

Note the static factory `HasOne::ofMany($name, $relationship, $resourceClass)`
promotes a `hasMany()->latestOfMany()` relation into a
[`HasOneOfMany`](#hasoneofmany) field.

---

## HasOneOfMany

Promotes a `hasMany()->latestOfMany()` (or `->ofMany(column, aggregate)`) relationship so the admin shows the **latest / oldest of many** as if it were a plain `HasOne`. Visually identical to `HasOne`.

```php
use Martis\Enums\AggregateFunction;
use Martis\Fields\HasOne;

// Two equivalent ways to declare the field:
HasOne::ofMany('Latest Invoice', 'latestInvoice', InvoiceResource::class)
HasOneOfMany::make('Latest Invoice', 'latestInvoice', InvoiceResource::class)
    ->latestByTimestamp('paid_at')                        // ⭐
    ->aggregateVia(AggregateFunction::Sum, 'amount');    // ⭐
```

**Model side:**

```php
// App\Models\Project
public function latestInvoice(): HasOne
{
    return $this->hasOne(Invoice::class)->latestOfMany();
}
```

See [fields.md § HasOneOfMany](fields.md#hasoneofmany) for the full API.

**⭐ Martis differentials:**

- **"Latest of N" pill** appears automatically on the detail panel next to the section heading (`1 de 12`), surfacing the size of the underlying collection.
- `latestByTimestamp()` / `oldestByTimestamp()` avoid the verbose `->ofMany('created_at', 'max')` boilerplate.
- `aggregateVia()` surfaces a metric tile with the full collection aggregate.

---

## HasOneThrough

Shows a single distant record reached through an intermediate model. Rendered visually like `HasOne`, but **read-only** (Create/Edit/Delete default to `false`; the UI hides those buttons).

```php
use Martis\Fields\HasOneThrough;

HasOneThrough::make('Account Manager', 'accountManager', TeamMemberResource::class)
    ->throughBreadcrumb(); // ⭐
```

**Model side:**

```php
// App\Models\Project
public function accountManager(): HasOneThrough
{
    return $this->hasOneThrough(
        TeamMember::class,       // target
        Client::class,           // intermediate
        // … FK / PK hints if the defaults don't match
    );
}
```

See [fields.md § HasOneThrough](fields.md#hasonethrough) for the full API.
All `HasOne` methods are inherited; `canCreate/canUpdate/canDelete` default to
`false`. `throughBreadcrumb(bool)` ⭐ adds a "through" hint next to the
section heading.

**⭐ Martis differentials:**

- Read-only defaults prevent misleading Create/Edit/Delete UI on traversal relationships.
- `throughBreadcrumb()` hint describes the intermediate hop without a custom tooltip.

---

## MorphOne

A polymorphic one-to-one relationship. Mirrors `HasOne` but for `morphOne` Eloquent relationships. Detail-only by default.

```php
use Martis\Fields\MorphOne;

MorphOne::make('Thumbnail')
MorphOne::make('Thumbnail', 'thumbnail', ThumbnailResource::class)
    ->canCreate(false)
    ->canUpdate(false)
```

See [fields.md § MorphOne](fields.md#morphone) for the full API.

---

## MorphOneOfMany

Polymorphic counterpart of [HasOneOfMany](#hasoneofmany). Promotes `morphMany()->latestOfMany()` (or `->ofMany(...)`).

```php
use Martis\Fields\MorphOne;

MorphOne::ofMany('Latest Note', 'latestNote', NoteResource::class)
    ->latestByTimestamp()                                 // ⭐
    ->aggregateVia(AggregateFunction::Count, '*');       // ⭐
```

**Model side:**

```php
// App\Models\Client
public function latestNote(): MorphOne
{
    return $this->morphOne(Note::class, 'noteable')->latestOfMany();
}
```

Inherits every `MorphOne` method plus the OfMany extras (`latestByTimestamp` / `oldestByTimestamp` / `aggregateVia`). Same "Latest of N" pill + aggregate tile as HasOneOfMany.

---

## HasManyThrough

Inline DataTable of many records reached through an intermediate. Read-only (Create/Edit/Delete default to `false`).

```php
use Martis\Fields\HasManyThrough;

HasManyThrough::make('Managed Projects', 'managedProjects', ProjectResource::class)
    ->throughBreadcrumb()  // ⭐
    ->countBadge();        // ⭐ on by default
```

**Model side:**

```php
// App\Models\TeamMember
public function managedProjects(): HasManyThrough
{
    return $this->hasManyThrough(
        Project::class,
        Client::class,
        'account_manager_id',
        'client_id',
        'id',
        'id',
    );
}
```

See [fields.md § HasManyThrough](fields.md#hasmanythrough) for the full API.
All `HasMany` methods are inherited; `canCreate/canUpdate/canDelete` default
to `false`. Adds `throughBreadcrumb(bool)` ⭐ and `countBadge(bool)` ⭐.

**⭐ Martis differentials:**

- Read-only defaults.
- `countBadge` brings the count affordance to Through fields (in addition to `showRelationCount` on `HasMany`).

---

## MorphMany

A polymorphic one-to-many relationship. Renders as a DataTable panel on the detail page with full inline CRUD, similar to `HasMany` but for `morphMany` Eloquent relationships.

**Detail-only by default.**

```php
use Martis\Fields\MorphMany;

MorphMany::make('Comments', 'comments', CommentResource::class)
    ->collapsable()
    ->collapsedByDefault()
    ->perPage(10)
    ->canCreate(false)
```

See [fields.md § MorphMany](fields.md#morphmany) for the full API.

---

## MorphToMany

A polymorphic many-to-many relationship. Behaves like `BelongsToMany` (DataTable UI, attach/detach, pivot fields, search) but for `morphToMany` Eloquent relationships.

**Detail-only by default.**

```php
use Martis\Fields\MorphToMany;

MorphToMany::make('Tags', 'tags', TagResource::class)
    ->titleAttribute('name')
    ->searchable()
    ->collapsable()
    ->fields(fn () => [
        Text::make('notes', 'Notes')->nullable(),
    ])
```

See [fields.md § MorphToMany](fields.md#morphtomany) for the full API.



## Choosing the Right Field

| Use case | Field |
|----------|-------|
| FK stored on this model | `BelongsTo` |
| FK stored on related model, single record | `HasOne` |
| FK stored on related model, many records | `HasMany` |
| Pivot table, DataTable UI, pivot fields | `BelongsToMany` |
| Pivot table, chip/autocomplete UI, no pivot data | `Tag` |
| Polymorphic parent (belongs to one of many types) | `MorphTo` |
| Polymorphic has-one (single child across types) | `MorphOne` |
| Polymorphic has-many (many children across types) | `MorphMany` |
| Polymorphic many-to-many with pivot | `MorphToMany` |

## Hardening — guaranteed behaviour matrix (Task 10)

The Task 10 hardening pass codified the contract every relationship surface guarantees. Each row below has a feature test in `tests/Feature/`. Use this as your spec when building or migrating a relationship-heavy resource.

### Per-type controller behaviour

| Behaviour | HasMany | HasOne | BelongsToMany | MorphMany | MorphOne | MorphToMany |
|---|---|---|---|---|---|---|
| Listing scoped to parent | ✅ | n/a (single) | ✅ | ✅ | n/a (single) | ✅ |
| **Cross-type isolation** (same ID, different morph type) | n/a | n/a | n/a | ✅ | ✅ | ✅ |
| Pagination + per-page | ✅ | n/a | ✅ | ✅ | n/a | ✅ |
| Search (against searchable fields on the related resource) | ✅ | n/a | ✅ | ✅ | n/a | ✅ |
| Sort (asc / desc on sortable fields) | ✅ | n/a | ✅ | ✅ | n/a | ✅ |
| Inline create / store with FK or morph keys auto-filled | ✅ | ✅ | ✅ (attach) | ✅ | ✅ | ✅ (attach) |
| Update preserves FK / morph keys | ✅ | ✅ | n/a | ✅ | ✅ | n/a |
| Delete / detach scoped — never touches another parent's records | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| 404 on unknown parent / record / relationship | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| 422 on missing required input | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Pivot data round-trip on attach + index + update | n/a | n/a | ✅ | n/a | n/a | ✅ |
| Authorization — `authorizedToCreate` / view / detach respected | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

### Pivot data API (BelongsToMany & MorphToMany)

Pivot fields declared via `->fields(fn () => [Number::make('weight'), ...])` round-trip through three surfaces:

| Surface | Body shape | Notes |
|---|---|---|
| Index response | `data[*]._pivot` | Pivot keys are merged into `_pivot` on each related row. Underscore prefix is intentional — keeps them visually distinct from real columns. |
| Attach | flat keys at top level: `{ related_id: X, weight: 12 }` | Pivot fields read directly from request input via `extractPivotData()`. |
| Update pivot (`PUT .../{relatedId}/pivot`) | flat keys at top level: `{ weight: 99 }` | Same shape as attach — no nested `pivot` key. |

### Multi-relations to the same target model

A resource can declare multiple `BelongsTo` / `HasMany` / `BelongsToMany` fields whose related model is the same class. The hardening pass guarantees they stay isolated:

```php
public function fields(Request $request): array
{
    return [
        BelongsTo::make('manager', 'Manager')->relatedResource('users'),
        BelongsTo::make('lead', 'Lead')->relatedResource('users'),
    ];
}
```

- Each field has its own `attribute()` (the foreign key — `manager_id` and `lead_id`).
- `/relatable/{attribute}` resolves the right field independently.
- The detail payload serializes both relations with their own values.
- `relatable{PluralModelName}(Request, Builder, ?FieldContract)` accepts an optional third parameter — when present, the resolver passes the active field instance so the hook can branch per-field:

```php
public static function relatableUsers(Request $request, Builder $query, ?FieldContract $field = null): Builder
{
    return match ($field?->attribute()) {
        'manager_id' => $query->where('role', 'manager'),
        'lead_id'    => $query->where('role', 'lead'),
        default      => $query,
    };
}
```

### Relatable scoping precedence

When the relatable list is computed, scopes apply in this order:

1. **`relatable{PluralModelName}` on the source resource** (specific override, gets passed the field).
2. **`relatableQuery` on the target resource** (generic fallback, applies whenever the source did not override).
3. **Field-level `relatableQueryUsing(fn ($request, $query) => ...)`** (BelongsTo / BelongsToMany).
4. **Field-level `withoutTrashed()`** (BelongsTo / BelongsToMany when the model uses `SoftDeletes`).

Each layer is composable — declaring a scope at one layer does not disable the others.

### Polymorphic cross-type isolation

For every morph relation (`MorphMany`, `MorphOne`, `MorphToMany`), the controllers scope reads, writes, and deletes by `{morph_type, morph_id}` together — never by `morph_id` alone. Concrete guarantees:

- Listing comments on a `Post` never includes comments belonging to a `Video` with the same numeric id.
- Updating a comment via `/morph-many/comments/{id}` from the wrong parent type returns 404, the comment is not modified.
- Detaching a tag via `/morph-to-many/tags/{id}/detach` only removes attachments where `taggable_type` matches the parent class.
- `MorphOneController::destroy` never deletes a sibling morph type's relation that happens to share the same `imageable_id`.

### Detach idempotency

`DELETE /belongs-to-many/{relatedId}/detach` and `DELETE /morph-to-many/{relatedId}/detach` are safe to retry. Detaching a record that was never attached returns 200, 204, 404, or 422 — **never 500**. Useful in retry-prone surfaces (mass detach, optimistic UI rollback).

### Error matrix

| Status | When |
|---|---|
| `200` / `201` / `204` | Success. |
| `403` | Policy / `authorizedToCreate` / `authorizedToView` denial. |
| `404` | Unknown source resource, unknown parent record, unknown relationship name, OR a related id that exists in the DB but does not belong to this morph parent. |
| `422` | Validation failure (missing required field, missing `related_id`, invalid pivot data). |
| `500` | Bug — please file an issue. |

### Test coverage

Per-type feature tests:

- `tests/Feature/HasManyControllerTest.php` (19)
- `tests/Feature/HasOneControllerTest.php` (10)
- `tests/Feature/BelongsToManyControllerTest.php` (21)
- `tests/Feature/MorphManyControllerTest.php` (16)
- `tests/Feature/MorphOneControllerTest.php` (12)
- `tests/Feature/MorphToManyControllerTest.php` (13)
- `tests/Feature/PivotActionControllerTest.php` (5)
- `tests/Feature/RelationshipsHardeningTest.php` (8) — multi-relation isolation, `relatableQueryUsing`, `relatable{PluralModelName}`, detach idempotency, search.
