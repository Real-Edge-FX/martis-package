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
something to appear. Unauthorized actions are never shown. An action is
authorized when the field allows it (`canCreate()` / `canUpdate()` /
`canDelete()`) and, for an action on a listed record (View, Edit, Delete,
Restore, Force delete on a row, Edit and Delete on a `HasOne` / `MorphOne`
card), when the related resource's policy allows it for that record: each
record carries those answers under `_authorization`, and a record without them
keeps the action, as on the resource index. The id column links to the record
only when its `authorizedToView` allows it (v2.0).

When the related resource denies `viewAny`, a `HasMany` / `HasOne` /
`MorphMany` / `MorphOne` panel still lists its records but offers no Create,
Edit, Delete, Restore or Force delete (v2.0): every one of those writes needs
the related `viewAny` (see
[Authorization → `viewAny` is the entry gate](authorization.md#viewany-is-the-entry-gate)).
Nova 1 to 3 hid such a panel; Nova 4/5 does not document it, so Martis keeps
the 1.x listing and only drops the actions that would answer 403.

On `BelongsToMany` / `MorphToMany` the row's own View / Edit / Delete are
replaced by Detach and the pivot edit (which `hideDeleteAction()` /
`hideEditAction()` hide), so `hideViewAction()` has nothing to hide;
`hideSoftDeleteToggle()`, `hideRestoreAction()` and `hideForceDeleteAction()`
apply there too since v2.0 (1.x ignored them on these panels).

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
   on trashed rows, plus any `rowActionsExtras(row)` the consumer returns,
   plus (on `HasMany` / `HasManyThrough` / `MorphMany`, v2.0) the related
   resource's inline actions menu, as on its index (see
   [Actions → In relationship panels](actions.md#in-relationship-panels)).
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
That form posts to the relationship's endpoint, as multipart when it carries a
file (v1.38.0+; before, it always posted JSON and a picked file was lost).
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

The filter applies on every panel, the `BelongsToMany` and `MorphToMany`
ones included (v2.0; before, their endpoints ignored `?trashed`, so *Only
trashed* listed the active records). Nova's `BelongsToMany` panel has the
same filter ([nova-dusk-suite: UpdateAttachedSoftDeletingTest](https://github.com/laravel/nova-dusk-suite/blob/10.4/tests/Browser/UpdateAttachedSoftDeletingTest.php)).

Every panel also lists only the rows the related resource's index would:
its `scopes()` and `indexQuery()` apply (v2.0). See
[Resources → indexQuery()](resources.md#indexquery).

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
- a `readonly()` pivot field never takes its value from the request: the attach stores its `default()` when it has one, and the pivot update leaves the column alone;
- a pivot field the user cannot see (`canSee()`) is written like a readonly one and is not validated, and it is left out of the relationship's schema (so the attach and edit forms do not render it) and of the pivot values sent back: each attached record's `_pivot` and the pivot update's response (v1.38.0+);
- a pivot field hidden for the pivot row by `canSeeForModel()` / `canSeeUsingPolicy()` is written and left out the same way, row by row (v1.38.0+). The callback receives the pivot row, an instance of the relationship's pivot class whose `pivotParent` is the parent record: the attached row when the pivot values are read or updated, a new row (before any value of the request is written to it) on attach. The relationship's schema still lists the field, so each attached record's `_pivot` lists it under `_hidden` (v1.38.0+), and the panel leaves it out of that row's cell (left empty) and of its pivot edit form, which neither renders nor sends it. The attach form renders it: the schema cannot decide on the new row, and the attach ignores the field (it stores its `default()`);
- a pivot `Repeater` writes its rows as on a record: a row keeps the stored value of a row field it cannot write and a new row takes the field's `default()`, and its rows are sent back as the Repeater reads them, without the row fields the user cannot see (v1.38.0+, see [Repeater → Readonly, computed, hidden and immutable row fields](repeater.md#readonly-computed-hidden-and-immutable-row-fields)).

The forms match: the attach form keeps an immutable pivot field editable, and the form that edits a pivot row renders it read-only, like a readonly one.

The attach stores the `default()` of every pivot field it does not take from the request (one the request omits, or a readonly one), so a readonly pivot field with a default stamps the row with a value the client cannot change:

```php
->fields(fn () => [
    Text::make('reference')->immutable(),
    Number::make('added_by')->readonly()->default(fn ($request) => $request?->user()?->id),
])
```

A value the request sends for a readonly or immutable field still runs the field's rules (a field the user cannot see is not validated). A pivot update with nothing left to write (an empty body, or only readonly and immutable values) answers 200 and leaves the row as it was. See [Fields → Immutable fields](fields.md#immutable-fields).

Before v1.38.0 `canSee()` and `canSeeForModel()` on a pivot field were ignored: the field was serialised, listed in `_pivot`, validated and written like any other. Up to v1.37.3 the attach and the pivot update wrote every pivot value the request sent, readonly and immutable fields included, and a pivot update with nothing to write answered 500 (an `UPDATE` with an empty `SET`) unless the relation declared `withTimestamps()` or `using()`. The values were copied from the request as they came, so a pivot `fillUsing()` never ran, a computed pivot field was written to a column that does not exist and a `MultiSelect` sent its array to the column (both a 500).

#### Relation pickers among the pivot fields

A `BelongsTo`, `MorphTo` or `Tag` pivot field lists its options from the panel (v1.38.0+). The parent resource's forms do not declare pivot fields, so the attach modal asks `GET /api/resources/{resource}/{id}/belongs-to-many/{relationship}/pivot-fields/relatable/{attribute}`, and the modal that edits the pivot row of an attached record asks `.../pivot-fields/{relatedId}/relatable/{attribute}` (`morph-to-many` for a `MorphToMany`):

```php
BelongsToMany::make('Members', 'members')
    ->relatedResource('users')
    ->fields(fn () => [
        BelongsTo::make('role', 'Role')->relatedResource('roles'),
    ])
```

The field is looked up in the relationship's `fields()` only (a `Repeater` row among them included, see [Repeater → Relation pickers and remote selects in rows](repeater.md#relation-pickers-and-remote-selects-in-rows)), so its related resource, `relatableQueryUsing()` and `withoutTrashed()` apply, and the parent resource is the source of the `relatable{PluralModelName}()` hook, as for the panel's pivot actions. The two routes are gated like the panel: `viewAny` on the resource, the parent record found through its `indexQuery()`, `view` on it, and `{relationship}` resolved only to a relationship field of the route's type the resource declares. Then like the operation the modal performs:

- the attach modal needs `authorizedToAttachAny()` for the related model (the `attachAny{Model}` policy ability), as the list of records to attach and the attach itself do: no record is picked yet, and the attach then checks `attach{Model}` for each one;
- the pivot edit modal needs `authorizedToUpdatePivot()` for that record (`updatePivot{Model}`, falling back to `update`), as the pivot update does.

Then, like every picker, `viewAny` on the related resource. An attribute the relationship does not declare as a `BelongsTo`, `MorphTo` or `Tag` pivot field, an unknown relationship, a missing parent, or a `{relatedId}` the relationship does not attach answer 404.

> Before v1.38.0 the pickers of a pivot field asked the parent resource's relatable endpoint (`/api/resources/{resource}/{id}/relatable/{attribute}`), which reads the parent's forms: 404 and an empty picker.

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

Without an override they ask the parent's policy: `attach{Model}` and `detach{Model}`, permitted when the policy does not define them. Before any record is picked, `authorizedToAttachAny()` (the `attachAny{Model}` ability, permitted when undefined) gates the attach as a whole: when it denies, the list of records to attach (`.../attachable`), the attach itself and the pickers of the attach modal's pivot fields answer 403, while the detach and the pivot update keep their own abilities. The pivot update asks `authorizedToUpdatePivot()` (`updatePivot{Model}`, falling back to `update`).

> Before v1.38.0 the attach and the list of records to attach did not check `attachAny{Model}`: a user it denied could still attach.

### API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/resources/{resource}/{id}/belongs-to-many/{relationship}` | List attached records |
| `GET` | `/api/resources/{resource}/{id}/belongs-to-many/{relationship}/attachable` | List attachable records |
| `POST` | `/api/resources/{resource}/{id}/belongs-to-many/{relationship}/attach` | Attach record |
| `DELETE` | `/api/resources/{resource}/{id}/belongs-to-many/{relationship}/{relatedId}/detach` | Detach record |
| `PUT` | `/api/resources/{resource}/{id}/belongs-to-many/{relationship}/{relatedId}/pivot` | Update pivot |
| `GET` | `/api/resources/{resource}/{id}/belongs-to-many/{relationship}/pivot-fields/relatable/{attribute}` | Options of a `BelongsTo` / `MorphTo` / `Tag` pivot field in the attach modal (v1.38.0, see [Relation pickers among the pivot fields](#relation-pickers-among-the-pivot-fields)) |
| `GET` | `/api/resources/{resource}/{id}/belongs-to-many/{relationship}/pivot-fields/{relatedId}/relatable/{attribute}` | The same, in the pivot edit modal of an attached record (v1.38.0) |

The `MorphToMany` panel serves the same routes under `morph-to-many`.

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

**The card is the related record's detail view (v2.0).** As in Nova, which
hides the panel when the related `view` policy denies the record
([nova-dusk-suite: HasOneAuthorizationTest](https://github.com/laravel/nova-dusk-suite/blob/10.4/tests/Browser/HasOneAuthorizationTest.php);
Nova reads it with a detail query,
[nova-issues#4120](https://github.com/laravel/nova-issues/discussions/4120)),
a `HasOne`, `HasOneOfMany`, `HasOneThrough`, `MorphOne` or `MorphOneOfMany`
card shows its record only when the user may `view` it. When the policy
denies the record the card is not rendered at all, as Nova drops the panel:
no heading, no Create (which would add a second record to a `HasOne`), no
Edit, no count. The card's endpoint answers `data: null` with
`meta.hidden: true` then (a card with no record answers `data: null` alone),
and its Edit and Delete answer 404.

**A `HasOne` or `MorphOne` takes one record.** Creating a second one through
the card's endpoint answers `422` (`The HasOne relationship has already been
filled.`, Nova's wording,
[nova-dusk-suite lang](https://raw.githubusercontent.com/laravel/nova-dusk-suite/10.4/lang/vendor/nova/en.json)),
whether or not the user may view the record already there; Nova hides the
Create button once a record exists
([HasOneRelationTest](https://github.com/laravel/nova-dusk-suite/blob/10.4/tests/Browser/HasOneRelationTest.php)).
Before v2.0 it answered `500`. A one-of-many card sits on a many
relationship and takes more records, as in Nova. Like the resource's own detail page, the card does not apply the related
resource's `indexQuery()`; the one-of-many "1 of N" count and the
`aggregateVia()` tile, which count a list, do (see below).

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

- **"Latest of N" pill** appears automatically on the detail panel next to the section heading (`1 de 12`), surfacing the size of the underlying collection. It and the `aggregateVia()` tile count the records the related resource's index would list (its `scopes()` and `indexQuery()`, v2.0), so a hidden record is not counted.
- `latestByTimestamp()` / `oldestByTimestamp()` avoid the verbose `->ofMany('created_at', 'max')` boilerplate.
- `aggregateVia()` surfaces a metric tile with the full collection aggregate.

---

## HasOneThrough

Shows a single distant record reached through an intermediate model. Rendered visually like `HasOne`, but, as in Nova, **without Create**: a record cannot be created through the relationship. Edit and Delete work as on `HasOne`.

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
All `HasOne` methods are inherited, except that `canCreate()` has no effect
(`canCreate(true)` logs a warning naming the field, once per request).
`throughBreadcrumb(bool)` ⭐ adds a "through" hint next to the section heading.

The relationship has no foreign key of its own to write, so the `has-one`
endpoints refuse a create through it: `POST
/api/resources/{resource}/{id}/has-one/{relationship}` answers 403 (`Records
cannot be created through a hasOneThrough relationship.`), also when the
relationship is declared with a plain `HasOne` (or `HasOneOfMany`) field.
Create the record from its own resource. `PUT` and `DELETE` on the same URL
work as on `HasOne`: they reach the record the relationship holds, under the
related resource's `update` / `delete` policies, and the card leaves out the
Edit or Delete a policy denies for that record. Coming from 1.x, see
[Upgrading the Through fields from 1.x](#upgrading-the-through-fields-from-1x).

**⭐ Martis differentials:**

- No create through the relationship, as in Nova, and enforced: the `has-one` endpoints refuse one (403).
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

Inline DataTable of many records reached through an intermediate. As in Nova, **without Create**: a record cannot be created through the relationship. The rows keep View, Edit, Delete, Restore and Force delete, as on `HasMany`.

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
All `HasMany` methods are inherited, except that `canCreate()` has no effect
(`canCreate(true)` logs a warning naming the field, once per request).
Adds `throughBreadcrumb(bool)` ⭐ and `countBadge(bool)` ⭐.

The relationship has no foreign key of its own to write: a create through it
would put the parent's key in the related record's key to the intermediate
model (`client_id` above), filing the project under whichever client has that
id. The `has-many` endpoints therefore refuse a create through it: `POST
/api/resources/{resource}/{id}/has-many/{relationship}` answers 403 (`Records
cannot be created through a hasManyThrough relationship.`), also when the
relationship is declared with a plain `HasMany` field. Create the projects from
their own resource. `PUT` and `DELETE …/has-many/{relationship}/{relatedId}`
work as on `HasMany`: they reach a project the relationship holds (404 for any
other), under the related resource's `update` / `delete` policies, and a row
leaves out the actions a policy denies for its record.

**⭐ Martis differentials:**

- No create through the relationship, as in Nova, and enforced: the `has-many` endpoints refuse one (403).
- `countBadge` brings the count affordance to Through fields (in addition to `showRelationCount` on `HasMany`).

### Upgrading the Through fields from 1.x

Martis 2.0 aligns `HasOneThrough` and `HasManyThrough` with Nova. In 1.x:

- the `has-many` endpoints took a create through a `hasManyThrough`
  relationship and filed the new record under whichever intermediate had the
  parent's id, and so did the `has-one` endpoints when a plain `HasOne` field
  declared a `hasOneThrough` one;
- `canCreate()` brought the Create button back on a Through panel, and a plain
  `HasMany` / `HasOne` field declaring a Through relationship showed it by
  default;
- the Through panels hid Edit and Delete by default (`canUpdate` /
  `canDelete` started as `false`), and the `has-one` endpoints refused an
  update or a delete through a `HasOneThrough` field (403). The
  `HasManyThrough` panel already showed Restore and Force delete on trashed
  rows, for every record.

In 2.0 a create through a Through relationship answers 403 and the panels never
offer Create, while Edit and Delete show as on `HasOne` / `HasMany`. Restore and
Force delete on `HasManyThrough` do not change. Every row and card action now
also follows the related resource's policy for its record: an action the policy
denies for a record is left out.

What to do:

- The upgrade rebuilds the schema cache, whose keys carry the installed
  Martis version. On a path repository, whose version does not change, clear
  it (`php artisan martis:cache:clear schema`): until it is rebuilt a panel
  keeps the actions it offered in 1.x.
- To keep the 1.x panel, hide Edit and Delete on the field:
  `->canUpdate(false)->canDelete(false)`. The endpoints still follow the
  policies: deny `update` / `delete` there to refuse those writes.
- Create the related records from their own resource (its create page, or
  `POST /api/resources/{related}`). An API client that created them through
  `…/has-many/{relationship}` or `…/has-one/{relationship}` of a Through
  relationship now gets a 403 there.
- Declare a Through relationship with `HasOneThrough` / `HasManyThrough`. A
  plain `HasMany` / `HasOne` field on one still shows Create, and its request
  now answers 403.
- Drop `canCreate()` from Through fields. It no longer does anything; it
  stays callable, so a resource that still calls it keeps loading, and
  `canCreate(true)` logs a warning naming the field, once per request
  (`Log::warning`, on the default log channel).
- If a panel offered Create on a Through relationship in your app, check the
  records created from it: the create wrote the parent's id into the
  relationship's second key (for `TeamMember::managedProjects()` above, the
  project's `client_id`).

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

See [fields.md § MorphToMany](fields.md#morphtomany) for the full API. The relation pickers among its pivot fields ask the panel, under `.../morph-to-many/{relationship}/pivot-fields/...`, like a `BelongsToMany`'s (see [Relation pickers among the pivot fields](#relation-pickers-among-the-pivot-fields)).



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
| `canSee()` pivot fields left out of the schema, `_pivot` and the validation, never written from the request | n/a | n/a | ✅ | n/a | n/a | ✅ |
| `canSeeForModel()` fields left out of the records sent and of the validation, never written from the request (decided on the stored record, the new model on a create, the pivot row for pivot fields) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| 404 on every endpoint of a relationship field `canSeeForModel()` hides for the parent record | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Pivot values written through each field's `fill()` (`fillUsing()`, computed, structured fields, custom pivot casts) | n/a | n/a | ✅ | n/a | n/a | ✅ |
| Pivot data round-trip on attach + index + update | n/a | n/a | ✅ | n/a | n/a | ✅ |
| Pivot actions listed, described and run per panel; `{relationship}` resolves only to a declared field of the route's type | n/a | n/a | ✅ | n/a | n/a | ✅ |
| Authorization — `authorizedToCreate` / view / detach respected | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `attachAny{Model}` gates the list of records to attach, the attach and the attach modal's pivot pickers; `attach{Model}` then decides per record | n/a | n/a | ✅ | n/a | n/a | ✅ |

### Pivot data API (BelongsToMany & MorphToMany)

Pivot fields declared via `->fields(fn () => [Number::make('weight'), ...])` round-trip through three surfaces:

| Surface | Body shape | Notes |
|---|---|---|
| Index response | `data[*]._pivot` | Pivot keys are merged into `_pivot` on each related row. Underscore prefix is intentional: it keeps them visually distinct from real columns. A pivot field the user cannot see is left out, and a pivot `Repeater`'s rows are read like a record's (v1.38.0+). A pivot field hidden for the pivot row (`canSeeForModel()`) is left out and listed under `_pivot._hidden` (v1.38.0+). |
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

1. **`{id}` names a record of the resource the user may update** (the edit form): `fieldsForUpdate()`, on the resource bound to that record, so a `relatableQueryUsing()` closure on the field can read it through `$this->model`.
2. **`{id}` is `_`** (a create form), **names no record, or names a record the user may not update**: `fieldsForCreate()`, then `fieldsForInlineCreate()` (the inline-create modal), on a resource bound to no record.
3. **`fields()`**, so a picker that only `fields()` declares keeps working on every form.

The first declaration found wins: when `fields()` and `fieldsForUpdate()` declare the same attribute differently, the edit form's picker uses the `fieldsForUpdate()` one (its `relatableQueryUsing()`, `withoutTrashed()`, related resource). Section / Panel / TabGroup containers are searched, and only a `BelongsTo`, `MorphTo` or `Tag` under the attribute counts, so a read-only `Text` that reuses the attribute on a form does not hide the picker declared in `fields()`.

Authorisation: `viewAny` on the resource and on the related resource, then the scoping below. The record `{id}` names is only bound when the resource's `authorizedToUpdate()` passes for it (the same check the edit form itself needs); any other record is answered from the create form, exactly like an id that names no record, so nothing a closure derives from that record reaches the answer and the answer does not reveal whether the record exists.

> Before v1.38.0 any record `{id}` named was bound after the `viewAny` check alone: a user who could not view or edit a record read the options its `fieldsForUpdate()` closures derived from it.

> Before v1.38.0 the endpoint searched `fields()` only: a picker declared on a form alone answered `Field 'x' not found.` (404) and opened with no options.

The pickers fill `{id}` from the form they render in: the record under edit on an update form, `_` on a create form. A create form nested in another resource's page sends `_` as well, so the inline-create modal and a create drawer opened from an edit or detail page read the create forms of their own resource. A record id the host passes explicitly (`recordId`, as a Tool form bound to a record does) always wins; otherwise the record id in the page URL is only used outside a create form, and only for the page's own resource.

The pickers of an Action modal ask the Action instead: `GET /api/resources/{resource}/actions/{action}/relatable/{attribute}` looks the field up in the Action's `fields()`, so its related resource, `relatableQueryUsing()` and `withoutTrashed()` apply, and the resource the Action runs on is the source of the `relatable{PluralModelName}()` hook. It answers 403 without `viewAny` on that resource or when the Action's `canSee()` denies, and 404 for an attribute the Action does not declare as a `BelongsTo`, `MorphTo` or `Tag`. The modal of a pivot action asks its panel the same way, under `/{resource}/{id}/{belongs-to-many|morph-to-many}/{relationship}/actions/{action}/relatable/{attribute}`, with the gates of the panel's pivot actions (see [Pivot Actions](#pivot-actions)) and the parent resource as the source. See [Actions → Relation fields](actions.md#relation-fields).

> Before v1.38.0 the pickers of an Action modal asked the page's resource: an attribute only the Action declares answered 404 with an empty picker, and one the resource also declares listed the resource's options (its related resource and scope) instead of the Action's.

The pickers among a `BelongsToMany` / `MorphToMany` panel's pivot fields ask the panel the same way, under `.../{relationship}/pivot-fields/relatable/{attribute}` (the attach modal) and `.../{relationship}/pivot-fields/{relatedId}/relatable/{attribute}` (the pivot edit modal), gated like the attach and the pivot update (see [Relation pickers among the pivot fields](#relation-pickers-among-the-pivot-fields)).

A picker in a `Repeater` row adds the row to any of these requests: `?repeater={attribute}&repeatable={type}` names the Repeater and the row type (`Repeatable::shortName()`), and the field is read from that row type's `fields()` on the form (or Action, or pivot fields) the Repeater belongs to, under the same gates. See [Repeater → Relation pickers and remote selects in rows](repeater.md#relation-pickers-and-remote-selects-in-rows).

The [Slug](fields.md#slug) collision check reads the forms in the same order (the update form when its `id` names a record the user may update, and only that record is left out of the uniqueness probe). The [`dependsOn` sync](fields.md#reactive-fields--dependsonfield-closure) and the server-side [`Select` search](fields.md#select) take the form from their `context` parameter and search only that form (`create` includes `fieldsForInlineCreate()`), never `fields()`, so a field that is not on the form cannot be probed.

### Relatable scoping precedence

When a picker list is computed, scopes apply in this order, on **every** picker that targets the resource: the BelongsTo dropdown (`/relatable/{field}`), the Action modal pickers (`/actions/{action}/relatable/{field}`, with the resource the Action runs on as the source, and `.../{relationship}/actions/{action}/relatable/{field}` for a pivot action, with the parent resource), the pivot field pickers (`.../{relationship}/pivot-fields/relatable/{field}` and `.../pivot-fields/{relatedId}/relatable/{field}`, with the parent resource), the pickers of a Repeater row (any of these with `?repeater=&repeatable=`), the context-free relatable form (`/_/_/relatable/{field}?related_resource=`), and the BelongsToMany / MorphToMany attach picker (`.../attachable`). The layers:

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
- `tests/Feature/FormRecordAuthorizationTest.php` (4) — the relatable options and the Slug check bind the update form of a record only when `authorizedToUpdate()` passes for it; a record the user may not update answers like a missing one (no option derived from it, no reserved value of its update form) and is not left out of the slug uniqueness probe.
- `tests/Feature/FormFieldLookupTest.php` (19) — `BelongsTo`, `MorphTo` and `Tag` pickers declared only in `fieldsForCreate()` / `fieldsForUpdate()` / `fieldsForInlineCreate()`, the form declaration winning over `fields()`, the record bound to the update form, the `fields()` fallback and the `viewAny` gate, plus the Slug check, `dependsOn` sync and `Select` search on a form-only field.
- `tests/Feature/ActionRelatableEndpointTest.php` (13) — `BelongsTo`, `MorphTo` and `Tag` pickers of an Action modal: the Action's declaration (related resource, `relatableQueryUsing()`) over the resource's, the relatable hooks, search, 404 for what the Action does not declare, and the `viewAny` / `canSee()` gates.
- `tests/Feature/PivotActionRelatableEndpointTest.php` (12) — the same pickers in a pivot action modal, on `BelongsToMany` and `MorphToMany` panels: field actions and resource `pivotAction()` ones, the parent resource's relatable hooks, and 404 for an action the panel does not offer, an undeclared attribute or relationship, or a missing parent.
- `tests/Feature/PivotFieldRelatableEndpointTest.php` (40): the same pickers among the pivot fields, in the attach modal and the pivot edit modal of both panels: the pivot field's declaration and `relatableQueryUsing()`, the parent resource's relatable hooks, search, a Repeater row among the pivot fields, 404 for an undeclared attribute or relationship, a missing parent and a related record the relationship does not attach, and the `viewAny` / `view` / `attachAny{Model}` / `updatePivot{Model}` gates.
- `tests/Feature/AttachAnyGateTest.php` (8): `attachAny{Model}` on both panels, refusing the attach (one record or several) and the list of records to attach while leaving the detach and the pivot update alone, and `attach{Model}` still deciding per record when it allows.
- `tests/Feature/RepeaterRowFieldLookupTest.php` (21): pickers and a remote `Select` declared in a Repeater's row types, read from the row the request names (`repeater` + `repeatable`) on the create and update forms, an Action's fields and a Tool's fields: two row types declaring the same attribute, search, the relatable hooks, 404 for an unknown Repeater, row type or attribute, and the `viewAny` gates.
- `tests/Feature/ModelVisibilityWriteTest.php` (14): a field `canSeeForModel()` hides for the record is neither validated nor written by the resource update, create and inline create, nor by the inline create and update of each `HasMany` / `HasOne` / `MorphMany` / `MorphOne` panel (a create decides on the new model), `canSeeUsingPolicy()` included.
- `tests/Feature/ModelVisibilityReadTest.php` (15): the same fields left out of each panel's records and inline update response, of the lens rows and of the peek card, and 404 on every endpoint of a relationship field hidden for the parent record.
- `tests/Feature/PivotFieldModelVisibilityTest.php` (14): `canSeeForModel()` on pivot fields, decided on the pivot row (a new row on attach and in the attachable list's `hiddenPivotFields`, the attached row on pivot update, in `_pivot` and in the pivot edit modal's pickers), and on a `BelongsToMany` / `MorphToMany` field hidden for the parent record.
- `tests/Feature/HiddenFieldEndpointsTest.php` (14): the pickers of a form, an Action, a pivot action and the pivot fields, the remote `Select` search of a resource and a Tool, the Slug check and the `dependsOn` sync answer for a field the user cannot see (a Repeater row field and a hidden Repeater included) exactly as for an undeclared one.
- `tests/Feature/ActionFieldVisibilityTest.php` (9): the fields of a resource action and a pivot action the request cannot set (hidden, readonly, computed, and a Repeater's rows) left out of the modal or the validation, and `handle()` receiving their `default()` (the queued job too).

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
