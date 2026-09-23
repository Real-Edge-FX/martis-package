# Repeater

Repeatable row widget backed by JSON, a child table (HasMany), or a single
polymorphic child table. Ships five differentials: parent-context injection
(`dependsOn`), collapsible rows with cardinality limits, dynamic row headers,
row templates with duplicate and bulk paste, and a polymorphic storage mode for
page-builder-style layouts.

- [Quick start](#quick-start)
- [Storage modes](#storage-modes)
  - [JSON](#json-mode--asjson)
  - [HasMany](#hasmany-mode--ashasmany)
  - [Polymorphic ⭐](#polymorphic-mode--aspolymorphic-)
- [Writing a Repeatable](#writing-a-repeatable)
- [Core API](#core-api)
- [⭐ Martis differentials](#-martis-differentials)
- [Validation](#validation)
- [Readonly, computed, hidden and immutable row fields](#readonly-computed-hidden-and-immutable-row-fields)
- [Relation pickers and remote selects in rows](#relation-pickers-and-remote-selects-in-rows)
- [Payload format](#payload-format)

## Quick start

```php
use Martis\Fields\Repeater;
use App\Martis\Repeaters\LineItem;

Repeater::make('line_items', 'Line items')
    ->asJson()
    ->uniqueField('id')
    ->repeatables([LineItem::make()]);
```

```php
// app/Martis/Repeaters/LineItem.php
namespace App\Martis\Repeaters;

use Illuminate\Http\Request;
use Martis\Fields\{Currency, Number, Repeatable, Text};

class LineItem extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            Number::make('quantity', 'Qty')->required(),
            Text::make('description', 'Description')->required(),
            Currency::make('price', 'Price')->required(),
        ];
    }
}
```

## Storage modes

### JSON mode (`->asJson()`)

Rows are serialised on a `json`/`array`-cast attribute of the parent
model. Ideal for small, self-contained lists (FAQ items, milestones,
links) that don't need their own table.

Requirements:

- Migration adds `->json('attribute')` on the parent table
- `protected $casts = ['attribute' => 'array']` on the model (an
  `AsCollection::class` or `AsArrayObject::class` cast works too; before
  v1.38.0 a Repeater read no rows from one)
- `->uniqueField('id')` strongly recommended — Martis otherwise generates
  a UUID on create so rows survive reorder

```php
Repeater::make('milestones')
    ->asJson()
    ->uniqueField('id')
    ->repeatables([Milestone::make()]);
```

### HasMany mode (`->asHasMany()`)

Rows live in a dedicated child table. Saving performs a 3-way upsert so FKs
downstream stay stable:

1. Match each row to the child whose id it carries (the `uniqueField`
   column, or the child's primary key when the Repeater has none) → update
   in place
2. Insert rows without a match
3. Delete rows that disappeared on the client

```php
Repeater::make('line_items')
    ->asHasMany()
    ->uniqueField('uuid')              // rows match by this column (by primary key without one)
    ->reorderable(true, 'position')    // auto-managed position column
    ->repeatables([LineItem::make()]);
```

Each `Repeatable` must set `public static ?string $model` to the Eloquent
model for its type.

A row writes the attributes of its Repeatable's fields (computed ones
excepted) to its child model and nothing else: a key the row sends for another
column, such as the foreign key or the primary key, is ignored, so a row
cannot move itself to another parent. A field the row cannot write (readonly,
hidden from the user, immutable on a stored row) keeps its column, see
[Readonly, computed, hidden and immutable row fields](#readonly-computed-hidden-and-immutable-row-fields).
The unique column of a new child is the id the row sends in it, or a new
UUID. Up to v1.37.3 every key of a row's `fields` was written to the child
model, and a row was matched only by a unique field sent at the top of the
row, which the form never sends: every save deleted the children and created
them again.

In this mode and in the polymorphic one, the rows are written right after the
parent is saved, by every form that saves it: the resource's create and update
and, since v1.38.0, the HasMany / HasOne / MorphMany / MorphOne inline forms.
Up to v1.37.3 a record created or updated through one of those inline forms
was saved without its rows.

### Polymorphic mode (`->asPolymorphic()`) ⭐

**Martis-only.** Every row type shares a single child table discriminated
by a `type` column, with field values serialised into a `payload` JSON
column. Ideal for page-builder-style layouts without one table per
Repeatable type.

Requirements:

- One model for the child table (e.g. `ProjectBlock`)
- Columns: `parent_id`, `type`, `payload` (json cast), optional
  `position` and a unique column
- All `Repeatable` subclasses point to the same model

```php
Repeater::make('blocks', 'Page blocks')
    ->asPolymorphic(typeColumn: 'type', payloadColumn: 'payload')
    ->uniqueField('uuid')
    ->reorderable()
    ->repeatables([
        HeroBlock::make()->icon('star')->color('warning')->title('Hero — {headline}'),
        TextBlock::make()->icon('text-align-left')->color('info'),
        GalleryBlock::make()->icon('images-square')->color('accent'),
    ]);
```

## Writing a Repeatable

A Repeatable declares the field set for one row type and, optionally, the
visual header decorations.

```php
use Martis\Fields\{Date, Repeatable, Text, Textarea};

class Milestone extends Repeatable
{
    public static ?string $model = \App\Models\Milestone::class; // HasMany only

    public function fields(\Illuminate\Http\Request $request): array
    {
        return [
            Text::make('name')->required()->rules(['required', 'max:120']),
            Date::make('due_date')->required()->rules(['required', 'date']),
            Textarea::make('description')->nullable(),
        ];
    }
}
```

Every Martis-specific header affordance lives on the Repeatable instance:

```php
Milestone::make()
    ->icon('flag-banner')               // Phosphor icon
    ->color('success')                  // semantic color token
    ->title('{name} — {due_date}')      // template with {attribute} placeholders
    ->badgeCount();                     // "#N" badge per row
```

### Repeatable identity methods

Three methods may be overridden to control how the Repeatable identifies itself
at runtime. The defaults are usually sufficient.

| Method | Default | Effect |
|--------|---------|--------|
| `shortName(): string` | Kebab-case class basename | Type identifier written into the payload `type` column |
| `label(): string` | Title-cased `shortName()` | Human-readable label used in the Add button ("Add Milestone") |
| `uniqueKey(): string` | `'id'` | Per-row key used by the frontend for keyed diffing |

## Core API

### `Repeater` methods

| Method | Signature | Notes |
|--------|-----------|-------|
| `make` | `make(string $attribute, ?string $label = null)` | Factory |
| `repeatables` | `->repeatables(array $repeatables)` | Declare the row types |
| `asJson` | `->asJson()` | JSON storage mode |
| `asHasMany` | `->asHasMany()` | HasMany storage mode |
| `asPolymorphic` | `->asPolymorphic(string $typeColumn, string $payloadColumn)` | Polymorphic storage mode |
| `uniqueField` | `->uniqueField(string $attribute)` | Stable row identifier for upsert and frontend keying |
| `collapsible` | `->collapsible(bool $enabled = true)` | Toggle per-row collapse affordance |
| `collapsedByDefault` | `->collapsedByDefault(bool $enabled = true)` | Start every row collapsed |
| `reorderable` | `->reorderable(bool $enabled = true, ?string $orderColumn = null)` | Drag-and-drop reorder; `$orderColumn` is the position column in HasMany/Polymorphic |
| `minRows` | `->minRows(int $n)` | Minimum row count, shown as a notice in the form; add `rules(['array', 'min:N'])` to enforce it on the server (see [Validation](#validation)) |
| `maxRows` | `->maxRows(int $n)` | Maximum row count, the Add button disables at it; add `rules(['array', 'max:N'])` to enforce it on the server |
| `confirmRemoval` | `->confirmRemoval(bool $confirm = true)` | Show a confirm dialog before removing a row |
| `rowTemplate` | `->rowTemplate(string $label, string $type, array $fields, array $options = [])` | Register one pre-filled template |
| `rowTemplates` | `->rowTemplates(array $templates)` | Register multiple templates in one call |
| `hideDuplicate` | `->hideDuplicate(bool $hidden = true)` | Opt-out of the per-row duplicate affordance |
| `hideBulkPaste` | `->hideBulkPaste(bool $hidden = true)` | Opt-out of the bulk paste affordance |
| `dependsOn` | `->dependsOn(array $attributes, ?\Closure $callback = null)` | Expose parent-record attributes to row field resolvers |

### `Repeatable` methods

| Method | Signature | Notes |
|--------|-----------|-------|
| `make` | `make()` | No arguments — Repeatables have no attribute of their own |
| `icon` | `->icon(string $icon)` | Phosphor icon name |
| `color` | `->color(string $color)` | Semantic color token |
| `title` | `->title(Closure\|string $title)` | Dynamic row header; `{attribute}` placeholders or closure. See [Row header affordances](#row-header-affordances) |
| `hasTitleCallback` | `->hasTitleCallback(): bool` | Whether the title is the Closure form (resolved per row on the server) |
| `resolveTitle` | `->resolveTitle(array $rowValues, int $index): ?string` | Run the Closure title for one row (`$index` is 1-based); `null` for a template or no title |
| `badgeCount` | `->badgeCount(bool $enabled = true)` | "#N" auto-numbered badge on the header |

## ⭐ Martis differentials

### Parent context — `dependsOn()`

Exposes selected parent-record attributes to every field **inside** every
row, so conditional field logic can react to data outside the row itself.

```php
Repeater::make('milestones')
    ->dependsOn(['status', 'deadline']);
```

Inside a row's field resolver, `formValues` now contains the row's own
fields *plus* the parent's `status` and `deadline`.

An optional `$callback` is called when any of the depended-on attributes
change, letting you reconfigure row types or field options dynamically:

```php
Repeater::make('tasks')
    ->dependsOn(['project_id'], function (Repeater $field, array $values) {
        // Reconfigure based on $values['project_id']
    });
```

### Collapse, reorder, cardinality

| Method | Effect |
|---|---|
| `->collapsible()` | Show a chevron on every row header to collapse the body |
| `->collapsedByDefault()` | Start with every row collapsed — ideal for 10+ rows |
| `->reorderable()` | Enables drag-and-drop; persists array position in JSON and a `position` column in HasMany/Polymorphic |
| `->reorderable(true, 'sort_order')` | Custom order column name |
| `->minRows(int)` | Footer shows "Minimum N required" when below threshold |
| `->maxRows(int)` | Disables the Add button when the cap is reached, with a `N / max` counter |

### Row header affordances

Declared on the `Repeatable` itself; surfaces real row context beyond the class basename.

| Method | Effect |
|---|---|
| `->icon('flag-banner')` | Phosphor icon rendered next to the title |
| `->color('success')` | Semantic color token painted as a 3-px left accent and icon tint |
| `->title('{name} — {due_date}')` | Template evaluated live on the client from the row's current field values |
| `->title(fn (array $row, int $i): ?string => "…")` | Closure resolved per row on the server: `$row` is the row's field map (the fields the user can see), `$i` its 1-based position |
| `->badgeCount()` | Show "#N" on the header (auto-numbered); only next to a static label, since both dynamic forms fall back to "Label #N" themselves |

The two `title()` forms differ in *when* they run:

- A **template** is re-evaluated on every keystroke in the form, so the header follows the row as the admin types.
- A **Closure** runs in PHP while the record is read (`Repeater::resolve()`, every storage mode) and its result travels with the row as `title` (see [Payload format](#payload-format)). The header shows the value of the *saved* row: a row added or edited in the form reads "Label #N" (or keeps its last resolved title) until the next save refreshes it. Use the Closure when the header needs data the row does not carry (a label map, a related model, a translation); use a template when the header is a plain projection of the row's own fields.

```php
class HomeSection extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            Select::make('key', 'Section')->options(HomeSections::labels()),
            Boolean::make('enabled', 'Enabled'),
        ];
    }
}

HomeSection::make()->title(
    fn (array $row, int $index): string => HomeSections::labels()[$row['key'] ?? ''] ?? "Section #{$index}"
);
```

Before v1.37.3 the Closure form was serialised as `hasTitleCallback: true` but never invoked, so every row fell back to the repeatable's label.

### Templates, duplicate, bulk paste

Pre-filled templates surface in the Add menu alongside the raw types (a
Repeater with a single row type lists it too, so a blank row can still be
added; before v1.38.0 its menu listed the templates only).

| Method | Effect |
|---|---|
| `->rowTemplate(string $label, string $type, array $fields, array $options = [])` | Register one pre-filled template |
| `->rowTemplates(array $templates)` | Register multiple templates in one call |
| `->hideDuplicate(bool $hidden = true)` | Opt-out of the duplicate-row affordance when row identity must stay unique |
| `->hideBulkPaste(bool $hidden = true)` | Opt-out of bulk paste when imports need a stricter flow |

```php
Repeater::make('delivery_phases')
    ->rowTemplates([
        [
            'label' => 'Kickoff · Design',
            'type' => 'delivery-phase',
            'fields' => ['name' => 'Kickoff', 'owner' => 'design', 'effort_days' => 3],
            'icon' => 'rocket-launch',
            'color' => 'info',
        ],
        // … more templates
    ]);
```

The "Paste rows" footer button opens a modal that parses TSV/CSV/JSON into rows,
detecting a header row automatically when column names match the Repeatable's
field attributes.

A template row, a duplicated row and a pasted row are new rows: each field the
row writes takes the value the template, the source row or the paste gives
it, and a readonly field starts at its `default()`, which is what the save
stores for it (see
[Readonly, computed, hidden and immutable row fields](#readonly-computed-hidden-and-immutable-row-fields)).
To have a template stamp a value the user cannot change, give the readonly
field that `default()`.

### Polymorphic storage

See [Polymorphic mode](#polymorphic-mode--aspolymorphic-). One table holds
every row type — ideal for page-builder-style layouts.

## Validation

The server validates the fields inside every row a save sends. Each row checks
the fields of its Repeatable (the one its `type` names) under the row's path in
the request, so the rules declared on a Repeatable's fields hold for every row:

```php
class Milestone extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            Text::make('name', 'Name')->required()->rules(['max:120']),
            Date::make('due_date', 'Due date')->nullable()->rules(['date']),
        ];
    }
}
```

A save whose second row has no name answers 422 with the Martis error
envelope, the same one every field error uses:

```json
{
  "message": "The given data was invalid.",
  "errors": [
    { "field": "milestones.1.fields.name", "message": "The Name field is required.", "code": "required" }
  ]
}
```

- **Keys.** The error of a row field sits under the path of its value in the
  request, `{attribute}.{row}.fields.{field}`, rows counted from 0. A
  Repeater inside a row nests the same way (`sections.0.fields.links.2.fields.url`).
  A legacy flat row of the JSON mode (no `type` and no `fields` key, read as
  the first Repeatable's fields) is checked under `{attribute}.{row}.{field}`.
- **Messages.** The path is named by the field's label, so the message reads
  "The Name field is required." rather than "The milestones.1.fields.name
  field is required.". A custom message a row field declares
  (`validationMessages()`) applies in every row.
- **Rules.** A row field validates with the rules it has on a form:
  `required()`, `nullable()`, `rules()`, plus `creationRules()` when the
  record is created and `updateRules()` when it is updated. Unlike a field of
  the record, a row field keeps `required` on an update: every save replaces
  the stored rows with the rows it sends, so a row always arrives whole and a
  row without a required value would be stored without it. A row also sends
  every field, an empty one as `null`, so an optional field with a rule that
  rejects `null` (the `email` rule of `Email`, a `min:N`) needs `nullable()`,
  as it does on a record's update form, which sends every field too.
- **Skipped fields.** A row field the save does not take from the request is
  not validated: a readonly or computed one, one the user cannot see
  (`canSee()`), and an immutable one on a stored row (see
  [Readonly, computed, hidden and immutable row fields](#readonly-computed-hidden-and-immutable-row-fields)).
  Neither are file fields (`File`, `Image`,
  `Avatar`, `Audio`): a row does not upload files, so what it sends for one is
  the stored path, which their file rules would reject. The `unique()` helper
  is left out too: it excludes the record an update writes, and a row has no
  record of its own, so it would reject every stored row sent back unchanged.
  A unique rule given to `rules()` (a `Rule::unique(...)` with the `ignore()`
  your storage needs) still applies.
- **Row types.** A row whose `type` names none of the Repeater's
  repeatables fails under `{attribute}.{row}.type` ("The selected Row type is
  invalid.") instead of being stored under a type no form can edit. A row
  with no `type` is checked as the first repeatable, which is how every
  storage mode reads it. When you rename a Repeatable class, pin its previous
  `shortName()` so the rows stored under it keep validating.
- **Where.** The rows are validated wherever a Repeater is written: the
  resource create and update (JSON and multipart requests), the inline
  create, the HasMany / HasOne / MorphMany / MorphOne inline forms, a pivot
  Repeater on attach and on pivot update, and a Repeater among an Action's
  fields. They are not validated when the save stores no rows from the value:
  a readonly Repeater, an immutable one on update, and a computed one without
  a `fillUsing()` callback. A Repeater with a `fillUsing()` callback is
  validated, since the callback receives the rows the form sent.
- **In the form.** Each error shows under the field of the row it belongs to
  and stays with that row when rows are removed, reordered or added before
  the next save; editing the field clears it. A row type error shows at the
  top of its row, a collapsed row with an error opens, and an error of the
  Repeater itself ("The Milestones field must be an array.") shows below the
  rows. Every bundled form does this (the create and update pages and
  drawers, the inline create, the Action modals and the pivot forms), and so
  does a Tool form built on `useMartisForm()`. A custom input receives the
  errors inside its value in its `nestedErrors` prop (see
  [Overrides](overrides.md)).

`minRows()` / `maxRows()` drive the form only: the Add button disables at the
maximum and a notice shows below the minimum. To reject a save with too few or
too many rows on the server, add the count rules to the Repeater itself, with
`array` so that Laravel counts rows ("The Milestones field must have at least 1
items.") rather than characters:

```php
Repeater::make('milestones')
    ->minRows(1)
    ->maxRows(10)
    ->rules(['array', 'min:1', 'max:10'])
    ->repeatables([Milestone::make()]);
```

To validate a Repeater's value in a controller of your own,
`buildRowValidation($data, $context, $model)` returns the `rules`, `messages`
and `attributes` of its rows for the validator (`$context` is `'create'`,
`'update'` or `null`; `$model`, optional, is the record an update writes,
whose stored rows keep an immutable row field out of the rules):

```php
$field = Repeater::make('milestones', 'Milestones')->repeatables([Milestone::make()]);
$rows = $field->buildRowValidation($request->all(), 'create');

Validator::make(
    $request->all(),
    ['milestones' => ['array'], ...$rows['rules']],
    $rows['messages'],
    $rows['attributes'],
)->validate();
```

Before v1.38.0 none of this happened: the rules of the fields inside a
Repeatable never ran on the server, so a row with a missing or invalid value
was stored as sent, and the form could not show a row error either (it handed
the Repeater its own error only, which the Repeater did not show). This
section described per-row validation that did not exist.

## Readonly, computed, hidden and immutable row fields

A field inside a Repeatable follows the write rules of a field of the record,
row by row, on every storage mode (JSON, HasMany, polymorphic) and on every
endpoint that writes a Repeater: the resource create and update, the inline
forms of a relationship panel, and a pivot Repeater on attach and on pivot
update (v1.38.0+).

| Row field | On a stored row | On a new row | Validated | In the schema and the rows a read gives |
|---|---|---|---|---|
| `readonly()` | keeps the stored value | takes its `default()`, or nothing | no | yes, rendered read-only |
| `computed()` | keeps the stored value (a HasMany child has no column for it) | nothing | no | yes (a HasMany child resolves it) |
| hidden by `canSee()` / `canSeeWhen()` | keeps the stored value | takes its `default()`, or nothing | no | no |
| `immutable()` | keeps the stored value | takes the value the row sends | on a new row only | yes, read-only on a stored row of an update form |

The value a request sends for such a field is ignored: it cannot set a
readonly or hidden field, and leaving the field out of a stored row does not
clear it.

**Stored rows and new rows.** A row the form sends back continues the stored
row whose id it carries, the `id` a read gives each row (see
[Payload format](#payload-format)): the `uniqueField()` of a JSON row (`id`
by default), the unique column of a HasMany or polymorphic child, or its
primary key when the Repeater declares no unique field or the column is
empty. A row that carries no stored row's id is new: an added, duplicated,
pasted or template row, and every row of a record being created. A row that
carries the id of a stored row of another row type replaces that row and
continues nothing of it. A JSON row stored without an id (written outside
Martis) gets one from its position on every read, so the form sends it back
as the row it continues, and the next save stores that id.

**Defaults.** A new row stores the `default()` of each readonly or hidden
field that declares one (a Closure default receives the request), and
nothing for a computed field, which stores nothing. A row template, a
duplicated row and pasted rows are new rows: the form starts their readonly
fields at the default, the value the save keeps.

```php
class Milestone extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            Text::make('name', 'Name')->required(),
            Text::make('reference', 'Reference')->immutable(),
            Text::make('status', 'Status')->readonly()->default('planned'),
            Text::make('margin', 'Margin')->canSee(fn (Request $request) => $request->user()?->isAdmin() ?? false),
        ];
    }
}
```

A new milestone stores `status` `planned` whatever the request sends, keeps the
`reference` it was created with, and keeps its `margin` when a user who cannot
see it saves the record.

**Hidden fields.** A field the user cannot see is left out of the row type in
the schema, of the `fields` of every row a read gives (the record's detail,
index and forms, the `_pivot` values of a pivot Repeater and the response of
its pivot update), of the row templates and of the Repeater's `default()`
rows, and of the values a Closure `title()` receives. A key of a JSON or
polymorphic row that names no field of its row type is kept and read as it
is: to hide a value from some users, declare its field with `canSee()` rather
than leaving the field out of `fields()` for them.

**Immutable fields.** `immutable()` on a row field means what it means on a
record, row by row: a new row writes and validates the value it sends, and a
stored row keeps its value without validating the one it sends back. An update
form renders the field read-only on the rows the record stores and editable on
the rows added since; a create form keeps it editable on every row. The
Repeater's input tells the two forms apart by its `context` prop, which every
bundled update form sets to `'update'`, and so do `useMartisForm({ context:
'update' }).fieldProps()` and `FieldsForm` for a form of your own (v1.38.0+). An immutable Repeater as a whole is skipped on every update, as any immutable
field (see [Fields → Immutable fields](fields.md#immutable-fields)).

A Repeater inside a row applies the same rules to its own rows, continued from
the rows the stored row holds. A Repeater with a `fillUsing()` callback hands
the callback the rows with these fields already set (the stored values of a
stored row, the defaults of a new one), read from the rows the Repeater stores
on the record. A Repeater among an Action's fields has no stored rows: every
row is a new one, so `handle()` receives each row with its readonly and hidden
fields at their `default()` (or without them) and without its computed fields,
whatever the request sends (v1.38.0+; before, `handle()` received the rows as
the modal sent them). See
[Actions → Fields the request cannot set](actions.md#fields-the-request-cannot-set).

Before v1.38.0 `readonly()` on a row field only reached the form, and
`computed()`, `canSee()` and `immutable()` had no effect there: every storage
mode wrote each value a row sent, so any request could set a readonly or
computed row field and change an immutable one, and the row type serialised
every field with each row carrying the value of every field, so a field hidden
with `canSee()` showed in every row. A HasMany or polymorphic row was matched
only by a unique field sent at the top of the row, which the form never sends,
so every save deleted the children and created them again, and a JSON Repeater
whose `uniqueField()` is not `id` gave every row a new id on each save.

## Relation pickers and remote selects in rows

A `BelongsTo`, `MorphTo` or `Tag` declared in a row type lists its options from the server like one on the form, and a `Select` with `searchOptionsUsing()` searches its options there (v1.38.0+). Each row field gets the scope of the form the Repeater renders in (its resource and record, its `create` or `update` context, the Action or the pivot fields it belongs to) plus the row, which its request names:

```
GET /api/resources/{resource}/{id}/relatable/{attribute}?repeater={attribute}&repeatable={type}
GET /api/resources/{resource}/fields/{attribute}/options?context={context}&repeater={attribute}&repeatable={type}
```

`repeater` is the Repeater's attribute and `repeatable` the row type (`Repeatable::shortName()`), since two row types may declare the same attribute with their own scope:

```php
Repeater::make('lines', 'Lines')->repeatables([
    ProductLine::make(), // BelongsTo::make('product')->relatedResource('products')
    ServiceLine::make(), // the same attribute, ->relatableQueryUsing(fn ($request, $query) => $query->where('kind', 'service'))
]);
```

The server finds the Repeater where it finds any field of that form (the update form, or the create forms, then `fields()`, with layout containers opened) and reads the field from the row type's `fields()`: the row field's related resource, `relatableQueryUsing()` and `withoutTrashed()` apply, and the resource is the source of the `relatable{PluralModelName}()` hook. The gates are the form's own: `viewAny` on the resource and on the related resource for a picker, the create or update ability for a `Select` search. The two parameters work on every relatable endpoint and on both option searches, so a Repeater among an Action's fields, a pivot action's fields or a relationship's pivot fields reads its rows there, and so does one on a Tool form (`/api/tools/{tool}/fields/{attribute}/options`). An unknown Repeater or row type, a request that sends only one of the two parameters, or an attribute the row type does not declare answers like an undeclared field: 404 for a picker, 422 for a `Select` search. A Repeater nested in a row type is not searched, so the pickers of its rows answer 404.

> Before v1.38.0 the row fields got the form's resource and record with the `update` context forced, and the endpoints only read the form's own fields: a picker declared in a row type answered 404 and opened empty (or listed the options of a form field that reused the attribute), and a remote `Select` in a row answered 422.

## Payload format

All storage modes ship rows to the frontend in the same shape:

```json
[
  {
    "id": "01HXYZ…",
    "type": "milestone",
    "fields": {
      "name": "Wireframes approved",
      "due_date": "2026-05-10",
      "description": "Validation with stakeholders."
    }
  }
]
```

`id` is the id the form sends back to continue the row (see
[Readonly, computed, hidden and immutable row fields](#readonly-computed-hidden-and-immutable-row-fields)):
the `uniqueField()` of a JSON row (`id` by default), or one derived from its
position for a row stored without it; the unique column of a HasMany or
polymorphic child, or its primary key when the Repeater has no unique field or
the column is empty. `fields` holds the values of the fields the user can see.
In polymorphic mode `type` matches `Repeatable::shortName()` and `fields` is the
deserialised `payload` column.

Rows of a Repeatable whose `title()` is a **Closure** carry one more key,
`title`, holding the string the Closure resolved for that row (or `null`
when it returned `null`):

```json
{ "id": "01HXYZ…", "type": "home-section", "fields": { "key": "hero", "enabled": true }, "title": "Hero" }
```

`title` is derived on every read and never stored: the form sends it back
as received and `Repeater::fill()` drops it before writing JSON rows (the
HasMany and polymorphic writers only read `id`, `type` and `fields`). Rows
of a template-titled or untitled Repeatable keep the three-key shape.

`Repeater::resolveRows($value)` gives the rows of a JSON value (a list of rows
or its JSON) in this shape: the pivot endpoints read a pivot Repeater's value
through it.
