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
   The column **auto-collapses** when a row would render **zero** actions: a
   fully read-only panel (all row actions hidden/unauthorized, and for pivot
   relations `canAttach(false)` + `canDetach(false)` with no pivot editing)
   renders no trailing "Actions" header or empty cells. Since **v1.31.1**.

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

### Which record a panel belongs to

Every relationship panel (the `-Many` panels above and the single-record
`HasOne` / `MorphOne` cards, `*OfMany` and Through variants included) lists
the related records of the record it belongs to, and builds every URL from
it: the list, the pivot actions, the attach picker, Create / Edit links
(`viaResource` / `viaResourceId`) and Delete / Detach.

| Where the panel renders | The record it belongs to |
|-------------------------|--------------------------|
| Top level of a detail page, or an edit form (`BelongsToMany` / `MorphToMany`) | The record in the URL (`/resources/{resource}/{id}`). |
| Among the fields of a `HasOne` / `MorphOne` card (`*OfMany`, `HasOneThrough`) | The card's related record. |
| Inside a bundled record drawer (`DrawerDetail`, `DrawerUpdate`, `DrawerQuick`) | The record the drawer shows, which the page URL may not name (a lens row, an index row or an action response opens it). |
| On a create surface (the create page, `DrawerCreate`, the inline-create modal) | None: the record does not exist yet, whatever page or drawer the surface opens over. The schema keeps `BelongsToMany` / `MorphToMany` off every create form, like Nova, and a pivot panel with no record renders nothing and asks nothing. |
| Inside a custom override, Tool or card | The record it names with `NestedParentProvider` from `@martis/runtime` (`id: null` on a create form), else the record in the URL. See [overrides.md § Naming the record of the relationship panels](overrides.md#naming-the-record-of-the-relationship-panels-v1380). |

On `team-members/2`, a `HasOneThrough` card showing project 3 renders the
project's `HasMany` tasks from `/api/resources/projects/3/has-many/tasks`, and
its Create button opens `/resources/tasks/create?viaResource=projects&viaResourceId=3&…`.
Since **v1.38.0**: before it, only the `HasOne` / `MorphOne` cards honoured the
enclosing card, so a `HasMany`, `MorphMany`, `BelongsToMany` or `MorphToMany`
nested in a card or rendered in a drawer asked the page's record (404, or the
page record's rows when it declares the same relationship). A `BelongsToMany` /
`MorphToMany` declared with `showOnCreating()` also rendered on the create
forms and read the page: `/api/resources/{resource}//belongs-to-many/...` (404)
on the create page, and the page's record in a create drawer or modal opened
over another record, so a Replicate drawer listed, and attached to, the record
it copies.

---

## Soft-delete filter

Relationship panels whose related resource uses `SoftDeletes` automatically
render a three-state filter in the toolbar — **Active / With trashed /
Only trashed**. Trashed rows show **Restore** and **Force-delete** actions
instead of Edit/Delete.

The default state comes from `config/martis.php` under the `index` block:

```php
// config/martis.php
'index' => [
    'default_trashed_filter' => env('MARTIS_DEFAULT_TRASHED_FILTER', 'active'),
    // 'active' | 'with' | 'only'
],
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

A many-to-many pivot relationship field. Renders as a DataTable panel on the detail page and the update form with attach/detach, pivot field editing, search, and pagination. It never renders on a create form (the create page, the create drawer, the inline-create modal), like Nova: a pivot row needs the record's key, so no visibility call brings the field there (since v1.38.0). Attach once the record exists, or use [`Tag`](#tag-belongstomany-chip-ui) to pick related records while creating.

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

Pivot fields validate with their own rules, like any field: the attach runs `rules()` plus `creationRules()`, and the pivot update runs `rules()` plus `updateRules()` with the literal `required` dropped and `sometimes` first, so a pivot field the update does not send is left alone. Rule objects (`Rule::in()`, `Rule::unique()`), `ValidationRule` instances and closures run on both. See [Fields → What an update validates](fields.md#what-an-update-validates).

Each pivot value is written through the pivot field's own `fill()`, run on a pivot model of the relationship's class (the stock `Pivot`, or the class passed to `->using()`), the way a record field is filled and the way Nova fills the pivot. So a pivot field behaves as it does on a record (v1.38.0+):

- a `fillUsing()` callback receives the pivot model and decides what to write (several columns included);
- a `computed()` field writes nothing;
- a structured field (`MultiSelect`, `KeyValue`, `BooleanGroup`) is stored as JSON, or handed to the cast of a custom pivot class and encoded once;
- a `Boolean` stores a boolean, a `BelongsTo` its foreign key column, a `Password` its hash.

On top of `fill()`, the pivot endpoints apply the write rules of the resource endpoints:

- an `immutable()` pivot field is written on attach and skipped on the pivot update;
- a `readonly()` pivot field never takes its value from the request: the attach stores its `default()` when it has one, and the pivot update leaves the column alone.

The forms match: the attach form keeps an immutable pivot field editable, and the form that edits a pivot row renders it read-only, like a readonly one.

The attach stores the `default()` of every pivot field it does not take from the request (one the request omits, or a readonly one), so a readonly pivot field with a default stamps the row with a value the client cannot change:

```php
->fields(fn () => [
    Text::make('reference')->immutable(),
    Number::make('added_by')->readonly()->default(fn ($request) => $request?->user()?->id),
])
```

A value the request sends for a skipped field still runs the field's rules. A pivot update with nothing left to write (an empty body, or only readonly and immutable values) answers 200 and leaves the row as it was. See [Fields → Immutable fields](fields.md#immutable-fields).

Up to v1.37.3 the attach and the pivot update wrote every pivot value the request sent, readonly and immutable fields included, and a pivot update with nothing to write answered 500 (an `UPDATE` with an empty `SET`) unless the relation declared `withTimestamps()` or `using()`. The values were copied from the request as they came, so a pivot `fillUsing()` never ran, a computed pivot field was written to a column that does not exist and a `MultiSelect` sent its array to the column (both a 500).

### Pivot Actions

Actions declared on the field with `->actions()` run on the rows selected in this relationship's panel, and `handle()` receives each related model with its `pivot` row (the pivot fields above included). A resource action flagged `->pivotAction()` shows on every `BelongsToMany` and `MorphToMany` panel of the resource. See [Actions → Pivot Actions](actions.md#pivot-actions).

```php
BelongsToMany::make('Tags', 'tags')
    ->relatedResource('tags')
    ->fields(fn () => [
        Text::make('notes', 'Notes')->nullable(),
    ])
    ->actions(fn (Request $request) => [
        ClearTagNotes::make(),
    ])
```

### Full Configuration

```php
use Martis\Enums\ModalSize;

BelongsToMany::make('Roles', 'roles', RoleResource::class)
    ->searchable()                      // enable search in attach modal
    ->collapsable()                     // make panel collapsable
    ->collapsedByDefault()              // start collapsed
    ->allowDuplicateRelations()         // allow same record attached twice
    ->showCreateRelationButton()        // inline create button in modal
    ->modalSize(ModalSize::ThreeExtraLarge, '70vh') // size + optional fixed height
    ->withSubtitles()                   // show subtitles in search results
    ->dontReorderAttachables()          // keep DB order in attachable list
    ->relatableQueryUsing(fn ($req, $q) => $q->where('active', true))
    ->fields(fn () => [
        Text::make('notes', 'Notes')->nullable(),
        Date::make('expires_at', 'Expires At')->nullable(),
    ])
    ->perPage(15)
    ->canAttach()                       // bool toggle — defaults to true
    ->canDetach()                       // bool toggle — defaults to true
```

`modalSize()` accepts a `Martis\Enums\ModalSize` case (`Small` through `SevenExtraLarge`) and an optional second parameter that pins the modal's height to a fixed CSS value (`'70vh'`, `'600px'`). Default height tracks the size; pass the second arg only when the size's intrinsic height is wrong for your form.

### Authorization

`canAttach()` / `canDetach()` are **static toggles** (defaults to `true` — pass `false` to hide the affordance for everyone). They do not accept closures. For dynamic, request-aware authorization, override the matching method on the parent Resource:

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

If these methods are absent, the framework falls back to `authorizedToUpdate()`.

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
MorphTo::make('commentable', 'Commentable')
    ->types([PostResource::class, VideoResource::class])
    ->nullable()
```

Pass the **relationship method name** (e.g. `commentable`), not the FK column. MorphTo writes both `commentable_type` and `commentable_id` based on the resolved related record.

See [fields.md — MorphTo](fields.md#morphto) for full API reference.

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

A polymorphic many-to-many relationship. Behaves like `BelongsToMany` (DataTable UI, attach/detach, pivot fields, pivot actions, search) but for `morphToMany` Eloquent relationships. Pivot actions come from the field's `->actions()` and from the resource actions flagged `->pivotAction()`, as on `BelongsToMany` (see [Actions → Pivot Actions](actions.md#pivot-actions)); up to v1.37.3 the panel asked for pivot action endpoints that did not exist, so none showed.

**On the detail page and the update form; never on a create form**, like `BelongsToMany` above.

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

## Hardening — guaranteed behaviour matrix

The hardening pass codified the contract every relationship surface guarantees. Each row below has a feature test in `tests/Feature/`. Use this as your spec when building or migrating a relationship-heavy resource.

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
| Field rules run as on the resource endpoint (rule objects, `ValidationRule`s, closures, `creationRules()` / `updateRules()`) | ✅ | ✅ | ✅ (pivot fields) | ✅ | ✅ | ✅ (pivot fields) |
| `immutable()` fields written on create, skipped on update, as on the resource endpoint | ✅ | ✅ | ✅ (pivot fields) | ✅ | ✅ | ✅ (pivot fields) |
| `readonly()` pivot fields never written from the request (the attach stores their `default()`) | n/a | n/a | ✅ | n/a | n/a | ✅ |
| Pivot values written through each field's `fill()` (`fillUsing()`, computed, structured fields, custom pivot casts) | n/a | n/a | ✅ | n/a | n/a | ✅ |
| Pivot data round-trip on attach + index + update | n/a | n/a | ✅ | n/a | n/a | ✅ |
| Pivot actions listed, described and run per panel; `{relationship}` resolves only to a declared field of the route's type | n/a | n/a | ✅ | n/a | n/a | ✅ |
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

### Relation fields declared on one form only

The picker endpoint of `BelongsTo`, `MorphTo` and `Tag` (`GET /api/resources/{resource}/{id}/relatable/{attribute}`) looks the field up on the form the picker renders in, so a relation field that a resource declares only in `fieldsForCreate()` or `fieldsForUpdate()` lists its options like one declared in `fields()`:

```php
public function fields(Request $request): array
{
    return [Text::make('name')];
}

public function fieldsForCreate(Request $request): array
{
    return [
        Text::make('name'),
        Tag::make('tags', 'Tags')->relatedResource('tags')->titleAttribute('name'),
    ];
}
```

The `{id}` segment of the URL decides which form is read, and `fields()` comes last:

1. **`{id}` names a record of the resource** (the edit form): `fieldsForUpdate()`, on the resource bound to that record, so a `relatableQueryUsing()` closure on the field can read it through `$this->model`.
2. **`{id}` is `_`** (a create form) **or names no record**: `fieldsForCreate()`, then `fieldsForInlineCreate()` (the inline-create modal).
3. **`fields()`**, so a picker that only `fields()` declares keeps working on every form.

The first declaration found wins: when `fields()` and `fieldsForUpdate()` declare the same attribute differently, the edit form's picker uses the `fieldsForUpdate()` one (its `relatableQueryUsing()`, `withoutTrashed()`, related resource). Section / Panel / TabGroup containers are searched, and only a `BelongsTo`, `MorphTo` or `Tag` under the attribute counts, so a read-only `Text` that reuses the attribute on a form does not hide the picker declared in `fields()`. Authorisation does not depend on which declaration is used: `viewAny` on the resource and on the related resource, then the scoping below.

> Before v1.38.0 the endpoint searched `fields()` only: a picker declared on a form alone answered `Field 'x' not found.` (404) and opened with no options.

The pickers fill `{id}` from the form they render in: the record under edit on an update form, `_` on a create form. A create form nested in another resource's page sends `_` as well, so the inline-create modal and a create drawer opened from an edit or detail page read the create forms of their own resource. A record id the host passes explicitly (`recordId`, as a Tool form bound to a record does) always wins; otherwise the record id in the page URL is only used outside a create form, and only for the page's own resource.

The pickers of an Action modal ask the Action instead: `GET /api/resources/{resource}/actions/{action}/relatable/{attribute}` looks the field up in the Action's `fields()`, so its related resource, `relatableQueryUsing()` and `withoutTrashed()` apply, and the resource the Action runs on is the source of the `relatable{PluralModelName}()` hook. It answers 403 without `viewAny` on that resource or when the Action's `canSee()` denies, and 404 for an attribute the Action does not declare as a `BelongsTo`, `MorphTo` or `Tag`. The modal of a pivot action asks its panel the same way, under `/{resource}/{id}/{belongs-to-many|morph-to-many}/{relationship}/actions/{action}/relatable/{attribute}`, with the gates of the panel's pivot actions (see [Pivot Actions](#pivot-actions)) and the parent resource as the source. See [Actions → Relation fields](actions.md#relation-fields).

> Before v1.38.0 the pickers of an Action modal asked the page's resource: an attribute only the Action declares answered 404 with an empty picker, and one the resource also declares listed the resource's options (its related resource and scope) instead of the Action's.

The [Slug](fields.md#slug) collision check reads the forms in the same order (the update form when its `id` names a record). The [`dependsOn` sync](fields.md#reactive-fields--dependsonfield-closure) and the server-side [`Select` search](fields.md#select) take the form from their `context` parameter and search only that form (`create` includes `fieldsForInlineCreate()`), never `fields()`, so a field that is not on the form cannot be probed.

### Relatable scoping precedence

When a picker list is computed, scopes apply in this order, on **every** picker that targets the resource — the BelongsTo dropdown (`/relatable/{field}`), the Action modal pickers (`/actions/{action}/relatable/{field}`, with the resource the Action runs on as the source, and `.../{relationship}/actions/{action}/relatable/{field}` for a pivot action, with the parent resource), the context-free relatable form (`/_/_/relatable/{field}?related_resource=`), and the BelongsToMany / MorphToMany attach picker (`.../attachable`):

1. **`relatableQuery` on the target resource** — the generic fence the target declares for itself. It always runs.
2. **`relatable{PluralModelName}` on the source resource** (specific override, gets passed the field) — narrows the already-fenced query for that source's relationships.
3. **Field-level `relatableQueryUsing(fn ($request, $query) => ...)`** (BelongsTo / BelongsToMany / MorphToMany).
4. **Field-level `withoutTrashed()`** (BelongsTo / BelongsToMany when the model uses `SoftDeletes`).

Each layer is composable — declaring a scope at one layer does not disable the others, and a lower layer can only ever narrow the result of the layers above it. In particular a source-side `relatable{PluralModelName}()` never replaces the target's `relatableQuery()`: a resource that fences itself (a tenant or ownership predicate on a model that cannot carry a global scope, such as the `User` model tenancy is resolved *from*) stays fenced no matter which resource offers the picker or which override that resource declares. This is a deliberate divergence from Nova, where the source override is an either/or replacement.

> Before v1.34.0 the attach picker never called `relatableQuery()` (only the field closure) and `relatable{PluralModelName}()` replaced `relatableQuery()`. A consumer that only needs the index fence on its pickers declares it once in `relatableQuery()`; it no longer has to repeat it on every `BelongsToMany` / `MorphToMany` field targeting the resource.

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

- `tests/Feature/HasManyControllerTest.php` (22)
- `tests/Feature/HasOneControllerTest.php` (11)
- `tests/Feature/BelongsToManyControllerTest.php` (24)
- `tests/Feature/MorphManyControllerTest.php` (17)
- `tests/Feature/MorphOneControllerTest.php` (13)
- `tests/Feature/MorphToManyControllerTest.php` (16)
- `tests/Feature/PivotActionControllerTest.php` (27) — pivot actions on `BelongsToMany` and `MorphToMany` panels: listing, fields and run, the field's `actions()` against the resource's `pivotAction()`, `{relationship}` resolved only to a declared field of the route's type, and the view / `canSee()` / `canRun()` gates.
- `tests/Feature/RelationshipsHardeningTest.php` (8) — multi-relation isolation, `relatableQueryUsing`, `relatable{PluralModelName}`, detach idempotency, search.
- `tests/Feature/RelationshipFieldRulesTest.php` (61) — every kind of field rule and the context rules on each write endpoint, next to the resource endpoint they match.
- `tests/Feature/RelationshipImmutableFieldsTest.php` (10) — `immutable()` on each inline create and update, next to the resource endpoint they match.
- `tests/Feature/PivotReadonlyImmutableFieldsTest.php` (24) — `readonly()` and `immutable()` pivot fields on the attach (single and batch) and the pivot update, next to the resource endpoint they match, plus a pivot update with nothing to write.
- `tests/Feature/PivotFieldFillTest.php` (7) — pivot values written through each field's `fill()` on the attach (single and batch) and the pivot update of both panels: a `fillUsing()` callback, a `MultiSelect`, a computed field and a `Boolean`, plus a custom pivot class that casts a structured field once.
- `tests/Feature/FormFieldLookupTest.php` (19) — `BelongsTo`, `MorphTo` and `Tag` pickers declared only in `fieldsForCreate()` / `fieldsForUpdate()` / `fieldsForInlineCreate()`, the form declaration winning over `fields()`, the record bound to the update form, the `fields()` fallback and the `viewAny` gate, plus the Slug check, `dependsOn` sync and `Select` search on a form-only field.
- `tests/Feature/ActionRelatableEndpointTest.php` (13) — `BelongsTo`, `MorphTo` and `Tag` pickers of an Action modal: the Action's declaration (related resource, `relatableQueryUsing()`) over the resource's, the relatable hooks, search, 404 for what the Action does not declare, and the `viewAny` / `canSee()` gates.
- `tests/Feature/PivotActionRelatableEndpointTest.php` (12) — the same pickers in a pivot action modal, on `BelongsToMany` and `MorphToMany` panels: field actions and resource `pivotAction()` ones, the parent resource's relatable hooks, and 404 for an action the panel does not offer, an undeclared attribute or relationship, or a missing parent.

---

## RelationshipQueryResolver

Internal resolver that powers the per-relationship `relatable*` overrides. Most consumers never touch it directly; it's documented here because it shows up in stack traces and is part of the public contract for advanced overrides.

### Resolution chain

When a `BelongsTo` dropdown, a context-free relatable form or a `BelongsToMany` / `MorphToMany` attach picker needs its candidates, the resolver applies the resource-level query hooks, composed in this order:

1. `relatableQuery(Request $request, Builder $query): Builder` — the generic fence on the **target** resource (the related resource itself). Always applied.
2. `relatable{PluralModelName}(Request $request, Builder $query [, FieldContract $field]): Builder` — pluralized model basename of the **related** resource, looked up on the **source** resource. Example: a `BelongsTo::make('Author', 'author', UserResource::class)` looks for `relatableUsers()` on the resource calling `BelongsTo::make`. It narrows the result of (1); it does not replace it.

The dynamic method name uses `Str::plural(class_basename($model))`. The third parameter is optional — declare it if you need to know which field is asking (useful when one resource exposes multiple `BelongsTo` to the same target).

### Override-side hook

For overrides on a `BelongsTo` field, prefer:

```php
BelongsTo::make('Author', 'author', UserResource::class)
    ->relatableQueryUsing(fn (Request $request, Builder $query) =>
        $query->where('is_active', true)
    );
```

`relatableQueryUsing()` runs alongside the resolver and stacks on top of both resource-level methods. See `tests/Feature/RelationshipsHardeningTest.php` and `tests/Feature/PickersHonourTargetRelatableQueryTest.php` for the full priority matrix.
