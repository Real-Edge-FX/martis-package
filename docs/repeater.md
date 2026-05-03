# Repeater

Repeatable row widget backed by JSON, a child table (HasMany), or a single
polymorphic child table. Ships five differentials: parent-context injection
(`dependsOn`), collapsible rows with cardinality limits, dynamic row headers,
row templates with duplicate and bulk paste, and a polymorphic storage mode for
page-builder-style layouts.

- [Quick start](#quick-start)
- [Storage modes](#storage-modes)
  - [JSON](#json-mode-asjson)
  - [HasMany](#hasmany-mode-ashasmany)
  - [Polymorphic ⭐](#polymorphic-mode-aspolymorphic-)
- [Writing a Repeatable](#writing-a-repeatable)
- [Core API](#core-api)
- [⭐ Martis differentials](#-martis-differentials)
- [Validation](#validation)
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
- `protected $casts = ['attribute' => 'array']` on the model
- `->uniqueField('id')` strongly recommended — Martis otherwise generates
  a UUID on create so rows survive reorder

```php
Repeater::make('milestones')
    ->asJson()
    ->uniqueField('id')
    ->repeatables([Milestone::make()]);
```

### HasMany mode (`->asHasMany()`)

Rows live in a dedicated child table. Saving performs a 3-way upsert
against `uniqueField` so FKs downstream stay stable:

1. Match existing rows by `uniqueField` → update in place
2. Insert rows without a match
3. Delete rows that disappeared on the client

```php
Repeater::make('line_items')
    ->asHasMany()
    ->uniqueField('uuid')              // required for upsert
    ->reorderable(true, 'position')    // auto-managed position column
    ->repeatables([LineItem::make()]);
```

Each `Repeatable` must set `public static ?string $model` to the Eloquent
model for its type.

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
| `minRows` | `->minRows(int $n)` | Minimum row count enforced client-side |
| `maxRows` | `->maxRows(int $n)` | Maximum row count enforced client-side |
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
| `title` | `->title(Closure\|string $title)` | Dynamic row header; `{attribute}` placeholders or closure |
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
| `->title('{name} — {due_date}')` | Template resolved per-row from the field values |
| `->title(fn($row, $i) => "…")` | Closure variant resolved on the server |
| `->badgeCount()` | Show "#N" on the header (auto-numbered) |

### Templates, duplicate, bulk paste

Pre-filled templates surface in the Add menu alongside the raw types.

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

### Polymorphic storage

See [Polymorphic mode](#polymorphic-mode-aspolymorphic-). One table holds
every row type — ideal for page-builder-style layouts.

## Validation

`rules()` declared on each field inside a `Repeatable` is executed for
every row. Errors are formatted as `attribute.index.field`:

```json
{
  "errors": {
    "milestones.0.due_date": ["The due_date field must be a valid date."]
  }
}
```

`minRows()` / `maxRows()` cardinality is enforced on the client
immediately (Add button disables, footer banner shows the minimum). A
server-side cardinality validator can be added via the Resource's
`validationMessage()` hook if stricter guarantees are required.

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

In polymorphic mode the `id` comes from `uniqueField` (typically a UUID
column), `type` matches `Repeatable::shortName()`, and `fields` is the
deserialised `payload` column.
