# Panels and Tabs

Martis supports three layout mechanisms for organising fields on forms and
detail pages: **Panels**, **Tabs**, and **Sections**.

- **Panel** — a visual grouping with a title bar, optional description, collapsible, and a Show more limit.
- **TabGroup / Tab** — navigable sections; each Tab holds fields and/or Panels.
- **Section** — a configurable CSS grid for multi-column form layouts with `Field::span()`. Covered in [Grid Layout](/docs/core/grid-layout).

---

## Panels

A `Panel` groups related fields into a visual section with a title, a differentiated
background and a horizontal separator. It can be collapsed by the user and supports
a limit of visible fields before a "Show more" button.

### API

```php
use Martis\Layout\Panel;

Panel::make('Panel Title', [
    // fields...
])
->description('Descriptive text')  // Martis extension — subtitle below the title
->collapsible()        // optional — allows the user to collapse the panel
->collapsedByDefault() // optional — starts collapsed (implies collapsible)
->limit(int $n)        // optional — only shows the first N fields
```

> **Martis extension:** `->description()` adds a subtitle below the Panel title.

### Help Text on Fields

Fields inside Panels support `->help()` to display contextual help below the input. Supports inline HTML:

```php
Panel::make('Security', [
    Text::make('password')
        ->required()
        ->help('Minimum 8 characters. Use a <strong>strong password</strong>.'),
])
```

### Examples

#### Basic Panel

The simplest case: groups fields in a visual container with a title. The user
cannot collapse it.

```php
Panel::make('Publication', [
    Select::make('status')
        ->options(['draft', 'published', 'archived'])
        ->required(),

    DateTime::make('published_at', 'Published At')
        ->nullable(),
]),
```

#### Collapsible Panel

The user can click the header to collapse or expand the panel.
Starts expanded by default.

```php
Panel::make('Author & Category', [
    BelongsTo::make('category')
        ->relatedResource('categories')
        ->nullable(),

    BelongsTo::make('user', 'Author')
        ->relatedResource('users')
        ->nullable(),
])->collapsible(),
```

#### Panel collapsedByDefault

Starts collapsed. Useful for advanced or rarely edited fields — they stay
out of the way without disappearing entirely.

```php
Panel::make('Advanced Content', [
    Markdown::make('excerpt', 'Excerpt')->nullable(),
    Textarea::make('body')->nullable(),
])->collapsedByDefault(),
```

#### Panel with limit

Shows only the first N fields. The rest are tucked behind a "Show more / Show less"
button. Ideal when a panel has many fields and we want to reduce the initial scroll.

```php
Panel::make('Tags & Labels', [
    Tag::make('tags', 'Tags')
        ->relatedResource('tags'),

    MultiSelect::make('labels', 'Labels')
        ->options(['featured', 'trending', 'exclusive']),

    Text::make('source_url', 'Source URL')
        ->nullable(),
])->limit(1), // only shows the first field initially
```

### Where to use Panels

Panels may appear in:

- `fieldsForCreate()`
- `fieldsForUpdate()`
- `fieldsForDetail()`
- **Inside Tabs** (see the section below)

They do not appear in `fields()` (index) — in that context fields are always flattened.

---

## Tabs

Tabs organise fields and panels into navigable sections. They are useful for
complex resources with many fields, allowing different themes (General, Content,
Organisation, Advanced) to be grouped without overloading the form.

### Structure

```
TabGroup          ← top-level container (implements LayoutContract)
  └── Tab         ← individual tab
        ├── Field ← field directly inside the tab
        └── Panel ← panel nested inside the tab
```

### API

```php
use Martis\Layout\Tab;
use Martis\Layout\TabGroup;

TabGroup::make([
    Tab::make('Tab Name', [
        // fields and/or panels...
    ]),
    Tab::make('Another Tab', [
        // fields and/or panels...
    ]),
])
```

### Examples

#### Simple Tabs (fields only)

```php
TabGroup::make([
    Tab::make('General', [
        Text::make('title')->required(),
        Select::make('status')->options(['draft', 'published']),
        DateTime::make('published_at')->nullable(),
    ]),

    Tab::make('Content', [
        Markdown::make('excerpt')->nullable(),
        Textarea::make('body')->nullable(),
    ]),
]),
```

#### Tabs with Nested Panels

Each `Tab` can contain one or more `Panel`. Panels inside tabs work
independently — a collapsible panel inside one tab can be collapsed without
affecting the other tabs.

```php
TabGroup::make([
    Tab::make('Organisation', [
        Panel::make('Relations', [
            BelongsTo::make('category')->relatedResource('categories'),
            BelongsTo::make('user', 'Author')->relatedResource('users'),
        ])->collapsible(),

        Tag::make('tags', 'Tags')->relatedResource('tags'),
    ]),

    Tab::make('Advanced', [
        Panel::make('SEO & References', [
            Text::make('source_url', 'Source URL')->nullable(),
        ])->collapsedByDefault(),
    ]),
]),
```

#### Full TabGroup (real-world example)

```php
public function fieldsForUpdate(Request $request): array
{
    return [
        TabGroup::make([
            Tab::make('General', [
                Text::make('title')->required(),
                Badge::make('status')->addTypes([
                    'published' => 'success',
                    'draft'     => 'warning',
                    'archived'  => 'danger',
                ]),
                DateTime::make('published_at', 'Published At')->nullable(),
            ]),

            Tab::make('Content', [
                Markdown::make('excerpt', 'Excerpt')->nullable(),
                Textarea::make('body')->nullable(),
            ]),

            Tab::make('Organisation', [
                Panel::make('Relations', [
                    BelongsTo::make('category')->relatedResource('categories')->nullable(),
                    BelongsTo::make('user', 'Author')->relatedResource('users')->nullable(),
                ])->collapsible(),
                Tag::make('tags', 'Tags')->relatedResource('tags')->withPreview(),
                MultiSelect::make('labels', 'Labels')
                    ->options(['featured', 'trending', 'exclusive'])
                    ->nullable(),
            ]),

            Tab::make('Advanced', [
                Panel::make('SEO & References', [
                    Text::make('source_url', 'Source URL')->nullable(),
                ])->collapsedByDefault(),
            ]),
        ]),
    ];
}
```

### Tab configuration

`Tab` is a thin container — it accepts a title and a list of fields/panels. There are no fluent extras (no `collapsible`, no `description`, no `icon`). Use a `Panel` inside the tab if you need those features.

### Where to use TabGroup

TabGroup may appear in:

- `fieldsForCreate()`
- `fieldsForUpdate()`
- `fieldsForDetail()`

It does not appear in `fields()` (index) — fields are always flattened for the listing.

---

## Possible Combinations

| Context            | Panel | TabGroup | Tab inside TabGroup | Panel inside Tab | Section |
|--------------------|-------|----------|---------------------|------------------|---------|
| `fields()` (index) | ✗     | ✗        | ✗                   | ✗                | ✗       |
| `fieldsForCreate`  | ✅    | ✅       | ✅                  | ✅               | ✅      |
| `fieldsForUpdate`  | ✅    | ✅       | ✅                  | ✅               | ✅      |
| `fieldsForDetail`  | ✅    | ✅       | ✅                  | ✅               | ✅      |

Section cannot be nested inside a Tab (Tab accepts `FieldContract|Panel` only). See [Grid Layout](/docs/core/grid-layout) for the Section API.

---

## Playground Showcase

The **Layout Showcase** resource at `playground/app/Martis/Resources/LayoutShowcaseResource.php`
demonstrates every combination described in this document:

- **Create** — demonstrates the 5 Panel variants (basic, collapsible, collapsedByDefault, limit, fields outside panels)
- **Edit** — demonstrates a TabGroup with 4 tabs (General, Content, Organisation with a nested Panel, Advanced)
- **Detail** — uses the same structure as Edit

To reach the showcase, navigate to `/showcase/layout-showcase/create` and `/showcase/layout-showcase/{id}/edit` on the playground.

---

## JSON Serialization

The backend serialises Panels and TabGroups as part of the resource's field schema.
The format is stable and can be inspected via `GET /api/{resource}/schema`.

### Panel

```json
{
  "type": "panel",
  "title": "Publication",
  "description": null,
  "collapsible": false,
  "collapsedByDefault": false,
  "limit": null,
  "fields": [
    { "type": "select", "attribute": "status", ... },
    { "type": "datetime", "attribute": "published_at", ... }
  ]
}
```

### TabGroup

The `fields` key inside each tab holds a heterogeneous array — it may contain field objects **and** panel objects mixed together (panels are serialised inline, not promoted to a separate key).

```json
{
  "type": "tab_group",
  "tabs": [
    {
      "type": "tab",
      "title": "General",
      "fields": [
        { "type": "text", "attribute": "title", ... }
      ]
    },
    {
      "type": "tab",
      "title": "Organisation",
      "fields": [
        { "type": "text", "attribute": "note", ... },
        {
          "type": "panel",
          "title": "Relations",
          "description": null,
          "collapsible": true,
          "collapsedByDefault": false,
          "limit": null,
          "fields": [ ... ]
        }
      ]
    }
  ]
}
```

### Section

```json
{
  "type": "section",
  "title": "Timeline",
  "description": null,
  "columns": 12,
  "collapsible": false,
  "collapsedByDefault": false,
  "limit": null,
  "fields": [
    { "type": "date", "attribute": "starts_at", "colSpan": 6, ... },
    { "type": "date", "attribute": "ends_at", "colSpan": 6, ... }
  ]
}
```
