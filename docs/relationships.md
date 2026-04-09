# Relationships

This guide covers all relationship field types in Martis and how to use them.

## Overview

Martis provides four relationship field types that map 1:1 to Laravel Eloquent relationships:

| Field | Eloquent Relationship | Nova v5 Equivalent |
|-------|-----------------------|-------------------|
| `BelongsTo` | `belongsTo()` | `BelongsTo` |
| `HasMany` | `hasMany()` | `HasMany` |
| `BelongsToMany` | `belongsToMany()` | `BelongsToMany` |
| `Tag` | `belongsToMany()` | `BelongsToMany` (chip UI) |
| `MorphTo` | `morphTo()` | `MorphTo` |

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

## Choosing the Right Field

| Use case | Field |
|----------|-------|
| FK stored on this model | `BelongsTo` |
| FK stored on related model | `HasMany` |
| Pivot table, DataTable UI, pivot fields | `BelongsToMany` |
| Pivot table, chip/autocomplete UI, no pivot data | `Tag` |
| Polymorphic parent | `MorphTo` |
