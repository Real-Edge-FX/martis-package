# Relationships

This guide covers all relationship field types in Martis and how to use them.

## Overview

Martis provides nine relationship field types that map 1:1 to Laravel Eloquent relationships:

| Field | Eloquent Relationship | Nova v5 Equivalent |
|-------|-----------------------|-------------------|
| `BelongsTo` | `belongsTo()` | `BelongsTo` |
| `HasOne` | `hasOne()` | `HasOne` |
| `HasMany` | `hasMany()` | `HasMany` |
| `BelongsToMany` | `belongsToMany()` | `BelongsToMany` |
| `Tag` | `belongsToMany()` | `BelongsToMany` (chip UI) |
| `MorphTo` | `morphTo()` | `MorphTo` |
| `MorphOne` | `morphOne()` | `MorphOne` |
| `MorphMany` | `morphMany()` | `MorphMany` |
| `MorphToMany` | `morphToMany()` | `MorphToMany` |

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

A many-to-many pivot relationship with full Nova v5 parity. Renders as a DataTable panel on the detail page with attach/detach, pivot field editing, search, and pagination.

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

| Method | Description |
|--------|-------------|
| `relatedResource(string $uriKey)` | Override the inferred related resource URI key |
| `canCreate(bool $value = true)` | Show/hide the Create button when no related record exists |
| `canUpdate(bool $value = true)` | Show/hide the Edit button for the existing related record |
| `canDelete(bool $value = true)` | Show/hide the Delete button for the existing related record |
| `withSubtitles()` / `subtitleAttribute('attr')` | Show a muted secondary line under the related-record options |
| `peekable(bool)` / `noPeeking()` | Toggle the hover-preview affordance (default: on) |
| `relatableQueryUsing(fn ($req, $q) => …)` | Closure that scopes the relatable-records query |
| `HasOne::ofMany($name, $relationship, $resourceClass)` | Promotes a `hasMany()->latestOfMany()` into a `HasOneOfMany` — see [HasOneOfMany](#hasoneofmany) |

---

## HasOneOfMany

⭐ **Nova parity** — promotes a `hasMany()->latestOfMany()` (or `->ofMany(column, aggregate)`) relationship so the admin shows the **latest / oldest of many** as if it were a plain `HasOne`. Visually identical to `HasOne`.

```php
use Martis\Enums\AggregateFunction;
use Martis\Fields\HasOne;

// Two equivalent ways to declare the field (Nova parity):
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

| Method | Description |
|--------|-------------|
| `latestByTimestamp(string $column = 'created_at')` ⭐ | One-liner sugar — orders the relation by the timestamp descending before picking the first row |
| `oldestByTimestamp(string $column = 'created_at')` ⭐ | Same in ascending order |
| `aggregateVia(AggregateFunction $fn, string $column = '*')` ⭐ | Ships a metric tile alongside the promoted record (count/sum/min/max/avg). Rendered on the detail panel next to the "1 of N" pill |

**⭐ Martis differentials vs Nova:**

- **"Latest of N" pill** appears automatically on the detail panel next to the section heading (`1 de 12`). Nova doesn't surface the size of the underlying collection.
- `latestByTimestamp()` / `oldestByTimestamp()` avoid the verbose `->ofMany('created_at', 'max')` boilerplate.
- `aggregateVia()` surfaces a metric tile with the full collection aggregate — Nova has no equivalent affordance on this field.

---

## HasOneThrough

⭐ **Nova parity** — shows a single distant record reached through an intermediate model. Rendered visually like `HasOne`, but **read-only** (Create/Edit/Delete default to `false`; the UI hides those buttons).

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

| Method | Description |
|--------|-------------|
| All `HasOne` methods | Inherited; `canCreate/canUpdate/canDelete` default to `false` |
| `throughBreadcrumb(bool $enabled = true)` ⭐ | Shows a "through" hint next to the section heading |

**⭐ Martis differentials:**

- Read-only defaults prevent misleading Create/Edit/Delete UI (Nova relies on the developer to remember).
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

| Method | Description |
|--------|-------------|
| `relatedResource(string $uriKey)` | Override the inferred related resource URI key |
| `canCreate(bool $value = true)` | Show/hide the Create button |
| `canUpdate(bool $value = true)` | Show/hide the Edit button |
| `canDelete(bool $value = true)` | Show/hide the Delete button |

---

## MorphOneOfMany

⭐ **Nova parity** — polymorphic counterpart of [HasOneOfMany](#hasoneofmany). Promotes `morphMany()->latestOfMany()` (or `->ofMany(...)`).

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

⭐ **Nova parity** — inline DataTable of many records reached through an intermediate. Read-only (Create/Edit/Delete default to `false`).

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

| Method | Description |
|--------|-------------|
| All `HasMany` methods | Inherited; `canCreate/canUpdate/canDelete` default to `false`, everything else (pagination, `collapsable`, `searchable`, `indexDisplay`, `showRelationIcon`, `showRelationCount`, `badgeColor`, etc.) carries over |
| `throughBreadcrumb(bool $enabled = true)` ⭐ | Adds a "through" hint to the section heading |
| `countBadge(bool $enabled = true)` ⭐ | Count pill on the parent's index cell (default: on) |

**⭐ Martis differentials:**

- Read-only defaults.
- `countBadge` brings the count affordance to Through fields (Nova exposes `showRelationCount` only on `HasMany`).

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

| Method | Description |
|--------|-------------|
| `relatedResource(string $uriKey)` | Override the inferred related resource URI key |
| `perPage(int $perPage)` | Records per page in the inline table |
| `perPageOptions(array $options)` | Custom per-page selector options |
| `canCreate(bool $value = true)` | Show/hide the Create button |
| `canUpdate(bool $value = true)` | Show/hide the Edit button |
| `canDelete(bool $value = true)` | Show/hide the Delete button |
| `relationSearchable(bool $value = true)` | Enable search within the inline table |
| `collapsable(bool $value = true)` | Make the panel collapsable |
| `collapsedByDefault(bool $value = true)` | Start collapsed |
| `showRelationIcon(bool $value = true)` | Show icon in the panel header |
| `showRelationCount(bool $value = true)` | Show record count badge |
| `badgeColor(string $color)` | Override the count badge colour |
| `badgeIcon(string $icon)` | Override the panel icon |

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

| Method | Description |
|--------|-------------|
| `relatedResource(string $uriKey)` | Override the inferred related resource URI key |
| `titleAttribute(string $attribute)` | Column used as the display label in attach modal |
| `fields(\Closure $closure)` | Pivot fields added to the attach/edit-pivot form |
| `actions(\Closure $closure)` | Actions available in the pivot table |
| `searchable(bool $value = true)` | Enable search in the attach modal |
| `collapsable(bool $value = true)` | Make the panel collapsable |
| `collapsedByDefault(bool $value = true)` | Start collapsed |
| `allowDuplicateRelations(bool $value = true)` | Allow attaching the same record twice |
| `showCreateRelationButton(bool\|\Closure $callback = true)` | Show inline create button in attach modal |
| `modalSize(ModalSize $size, ?string $height = null)` | Control modal dimensions |
| `relatableQueryUsing(\Closure $closure)` | Filter the attachable record list |
| `dontReorderAttachables(bool $value = true)` | Keep DB order in attachable list |
| `withSubtitles(bool $value = true)` | Show subtitles in search results |
| `subtitleAttribute(string $attribute)` | Column used as the subtitle |
| `perPage(int $perPage)` | Records per page in the inline table |
| `canAttach(bool $value = true)` | Control attach permission |
| `canDetach(bool $value = true)` | Control detach permission |



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
