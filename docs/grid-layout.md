# Grid Layout — Section & Field Span

Martis supports a flexible, grid-based form layout system that lets you organise fields
into responsive multi-column rows inside `create` and `update` forms.

The API is intentionally minimal: two methods — `Section::columns()` and `Field::span()` —
give you full control over every form layout.

---

## Table of Contents

- [Overview](#overview)
- [Scope](#scope)
- [API Reference](#api-reference)
  - [Section::columns()](#sectioncolumns)
  - [Field::span()](#fieldspan)
  - [Advanced: colSpan / colSpanMd / colSpanLg](#advanced-colspan--colspanmd--colspanlg)
- [Simple Examples](#simple-examples)
  - [Two Fields Side by Side](#two-fields-side-by-side)
  - [Three Fields with Different Spans](#three-fields-with-different-spans)
  - [Full-width Field](#full-width-field)
  - [Section Without columns()](#section-without-columns)
  - [Field Without span()](#field-without-span)
- [Real-world Examples](#real-world-examples)
  - [Posts Resource — Full Layout](#posts-resource--full-layout)
  - [Timeline Pattern](#timeline-pattern)
  - [Operational Grouping Pattern](#operational-grouping-pattern)
- [Rules and Best Practices](#rules-and-best-practices)
- [Responsiveness](#responsiveness)
- [Limitations and Notes](#limitations-and-notes)

---

## Overview

Before grid layout, every Martis form rendered one field per row regardless of field size.
A form with ten fields meant ten rows — even if four of those fields were short selects that
would look much better side by side.

Grid layout solves this by letting you define a CSS grid per form `Section` and assign each
field a column span within that grid.

```php
use Martis\Layout\Section;

Section::make('Timeline', [
    Date::make('start_date')->span(6),
    Date::make('end_date')->span(6),
])->columns(12)
```

Result: `Start Date` and `End Date` render in two equal columns on a 12-column grid.

> **Fully typed.** Returning a layout wrapper (`Section`/`Panel`/`TabGroup`) from `fields()`, any `fieldsFor*()` context method, `detailSidebar()`, or a `Lens`'s `fields()` is first-class: those methods are typed `list<FieldContract|LayoutContract>`, so a consumer running PHPStan (Martis itself runs level 8) stays green — no `return.type` error, no need to loosen a `@return`. The engine flattens the wrappers to their nested fields for validation and model filling. (Top-level wrappers only — a bare `Tab` lives inside a `TabGroup`, not at the top of `fields()`.)

---

## Scope

Grid layout applies to **`create`**, **`update`**, and **`detail`** views.

| Context | Grid layout? |
|---------|:------------:|
| create  | ✅            |
| update  | ✅            |
| detail  | ✅            |
| index   | ❌            |

On index views, fields are flattened into table columns — Section containers have no effect there.

---

## API Reference

### Section::columns()

```php
Section::make(?string $title, array $fields): static
Section::columns(int $columns): static
```

Creates a form section with a named CSS grid.

| Parameter  | Type           | Default | Description                                                              |
|------------|----------------|---------|--------------------------------------------------------------------------|
| `$title`   | `string\|null` | —       | Section heading shown in the header bar. Pass `null` for a header-less grid. |
| `$fields`  | `array`        | —       | Fields in this section                                                   |
| `$columns` | `int`          | `12`    | Number of CSS grid columns                                               |

The `columns()` value defines how many equal-width tracks the grid has.
A `columns(12)` grid with `span(6)` fields gives you two 50%-wide columns.
A `columns(3)` grid with `span(1)` fields gives you three 33%-wide columns.

**Section also supports:**

```php
Section::make('...', [...])->description('Contextual help text')  // Martis extension
Section::make('...', [...])->collapsible()         // user can collapse
Section::make('...', [...])->collapsedByDefault()   // starts collapsed
Section::make('...', [...])->limit(5)               // show 5 fields, Show more for rest
```

> **Martis extension:** `->description()` adds a subtitle below the Section title.

**Help text on fields in Sections** supports inline HTML (Martis extension):

```php
Section::make('Identity', [
    Text::make('name')->span(6)->help('The full name of the user.'),
    Email::make('email')->span(6)->help('See our <a href="/privacy">policy</a>.'),
])->columns(12)
```

---

### Field::span()

```php
Field::span(int $cols): static
```

Assigns a column span to a field within its parent Section grid.

| Parameter | Type  | Default         | Description                                                           |
|-----------|-------|-----------------|-----------------------------------------------------------------------|
| `$cols`   | `int` | section columns | Number of grid columns this field occupies. Clamped server-side to `[1, 12]`. |

`span()` is a clean shorthand for `colSpan()`. Both are equivalent.

If `span()` is not called, the field occupies the full section width (same behaviour as
not using grid layout at all).

**Valid examples:**

```php
// 12-column grid
Text::make('name')->span(12)        // full width
Date::make('start')->span(6)        // half width
Select::make('status')->span(4)     // one third
Currency::make('budget')->span(4)   // one third
Id::make('id')->span(4)             // one third
```

---

### Advanced: colSpan / colSpanMd / colSpanLg

For fine-grained responsive control, use the breakpoint-specific variants:

```php
Text::make('name')
    ->colSpan(12)     // all breakpoints: full width (default)
    ->colSpanMd(6)    // >= 768px: half width
    ->colSpanLg(4)    // >= 1024px: one third
```

| Method        | Breakpoint | Fallback           |
|---------------|------------|--------------------|
| `colSpan()`   | all        | 12                 |
| `colSpanMd()` | >= 768px   | inherits `colSpan` |
| `colSpanLg()` | >= 1024px  | inherits `colSpanMd` |

`span()` is an alias for `colSpan()` with a cleaner API suited for Section usage.
Use `colSpanMd()` / `colSpanLg()` when you need breakpoint-specific control.

---

## Simple Examples

### Two Fields Side by Side

```php
Section::make('Timeline', [
    Date::make('start_date', 'Start Date')->span(6),
    Date::make('end_date', 'End Date')->span(6),
])->columns(12)
```

Visual result:

```
┌─────────────────────────────────────────────────────┐
│ Timeline                                            │
├──────────────────────────┬──────────────────────────┤
│ Start Date               │ End Date                 │
│ [date picker]            │ [date picker]            │
└──────────────────────────┴──────────────────────────┘
```

---

### Three Fields with Different Spans

```php
Section::make('Status', [
    Select::make('status')->span(4),
    Select::make('priority')->span(4),
    Currency::make('budget')->span(4),
])->columns(12)
```

Visual result:

```
┌─────────────────────────────────────────────────────┐
│ Status                                              │
├──────────���─────┬────────────────┬──────���────────────┤
│ Status         │ Priority       │ Budget            │
│ [select]       │ [select]       │ [currency]        │
└────────────────┴────────────────┴───────────────────┘
```

---

### Full-width Field

```php
Section::make('Content', [
    Text::make('title')->span(12),            // full width
    Textarea::make('body')->span(12),          // full width
    Url::make('source_url')->span(6),          // half width
    Select::make('language')->span(6),         // half width
])->columns(12)
```

---

### Section Without columns()

When `columns()` is omitted, the section defaults to `columns(12)`.
Fields without `span()` default to full width.
This means an unstyled section behaves identically to the existing Panel behaviour.

```php
// Equivalent to Panel — backwards-compatible
Section::make('Details', [
    Text::make('title'),
    Textarea::make('body'),
])
```

---

### Field Without span()

A field with no `span()` call occupies the full section width.
This is intentional: the feature is **opt-in** per field.

```php
Section::make('Mixed', [
    Text::make('title'),            // full width (no span)
    Date::make('start')->span(6),   // half width
    Date::make('end')->span(6),     // half width
    Textarea::make('notes'),        // full width (no span)
])->columns(12)
```

---

## Real-world Examples

### Posts Resource — Full Layout

The playground `PostsResource` uses Sections to organise a complex blog post form:

```php
use Martis\Layout\Section;

public function fieldsForCreate(Request $request): array
{
    return [
        Section::make('Core', [
            Image::make('featured_image', 'Cover Image')->nullable()->span(12),
            Text::make('title')->required()->span(12),
        ])->columns(12),

        Section::make('Publication', [
            Select::make('status')
                ->options(['draft', 'published', 'archived'])
                ->required()
                ->span(6),
            DateTime::make('published_at', 'Published At')->nullable()->span(6),
            BelongsTo::make('category')->relatedResource('categories')->nullable()->span(6),
            BelongsTo::make('user', 'Author')->relatedResource('users')->nullable()->span(6),
        ])->columns(12),

        Section::make('Content', [
            Markdown::make('excerpt', 'Excerpt')->nullable()->span(12),
            Textarea::make('body')->nullable()->span(12),
            Url::make('source_url', 'Source URL')->nullable()->span(12),
        ])->columns(12),

        Section::make('Organisation', [
            Tag::make('tags')->relatedResource('tags')->span(8),
            MultiSelect::make('labels')->options([...])->nullable()->span(4),
            File::make('attachment')->nullable()->span(6),
            KeyValue::make('meta')->nullable()->span(6),
        ])->columns(12),
    ];
}
```

---

### Timeline Pattern

Ideal for start/end date pairs, before/after snapshots, or any two-column temporal layout:

```php
Section::make('Timeline', [
    Date::make('starts_at', 'Start Date')->span(6),
    Date::make('ends_at', 'End Date')->span(6),
])->columns(12)
```

---

### Operational Grouping Pattern

Groups related operational fields to reduce visual noise:

```php
Section::make('Project Details', [
    Text::make('name')->span(12),
    Date::make('starts_at', 'Start Date')->span(6),
    Date::make('ends_at', 'End Date')->span(6),
    Select::make('status')->span(4),
    Select::make('priority')->span(4),
    Currency::make('budget')->span(4),
])->columns(12)
```

---

## Rules and Best Practices

### When to use Section

- When a form has many short fields (selects, dates, booleans) that benefit from side-by-side placement.
- When fields have a natural grouping that deserves a named header.
- When you want to reduce vertical scroll without sacrificing clarity.

### When not to use Section

- For forms with just 2–3 long fields — single-column is usually cleaner.
- When fields have no logical grouping — don't create sections just for the grid.
- On index views — Section containers have no effect there; fields are flattened into table columns.

### Keeping spans readable

- Use a consistent grid (12 is standard): `span(6)` = half, `span(4)` = third, `span(3)` = quarter.
- Spans within a section should sum to a multiple of the section columns.
  If spans don't sum evenly (e.g. `span(7)` + `span(7)` in a 12-col grid), the browser will
  wrap the second field to the next row. This is valid but may look surprising.
- If you want a field to always be on its own row: `span(12)` explicitly or omit `span()`.

### Section vs Panel

| Feature              | Panel               | Section             |
|----------------------|---------------------|---------------------|
| Scope                | create/update/detail | create/update/detail |
| Grid control         | fixed 12-col        | configurable        |
| `span()` / `colSpan()` | ✅ (both work)    | ✅ (both work)      |
| Collapsible          | ✅                  | ✅                  |
| Can be placed inside a Tab | ✅           | ❌ (Tab accepts Panel, not Section) |

Use `Panel` when you need tabs (Tab can contain Panel but not Section), or when a fixed single-column group layout is all you need.
Use `Section` when you want configurable multi-column grid layout on forms or detail views.

---

## Responsiveness

Sections are fully responsive out of the box.

- **Desktop / Tablet** (`>= 768px`): fields use their `span()` values within the section grid.
- **Mobile** (`< 768px`): all fields collapse to full width, one per row. No gaps, no overlap.

The mobile collapse is handled by a global CSS rule in `martis.css`:

```css
@media (max-width: 767px) {
  .martis-section-grid > * {
    grid-column: 1 / -1 !important;
  }
}
```

This behaviour is intentional and cannot be overridden per-field on mobile — a consistent
single-column layout is always better than trying to fit a 3-column grid on a 375px screen.

For fine-grained breakpoint control on tablet and desktop, use `colSpanMd()` and `colSpanLg()`:

```php
Text::make('name')
    ->span(12)         // mobile: full (always)
    ->colSpanMd(6)     // tablet: half
    ->colSpanLg(4)     // desktop: one third
```

---

## Limitations and Notes

- **Index views**: Sections are ignored — fields are flattened into table columns. Section containers have no effect on the index.
- **Inline create (drawer)**: Sections are flattened for inline create. Every field inside a Section renders in the inline-create form, but the section header and multi-column grid are dropped — fields appear in the standard single-column label/input layout. To customise inline create fields independently, define `fieldsForInlineCreate()`.
- **Span overflow**: `span()` / `colSpan()` values are clamped to `[1, 12]` server-side, regardless of the section's `columns()` setting. If a value still overflows the CSS grid at render time, the browser places the field on its own row — no error is thrown.
- **Nested sections**: Sections cannot be nested. Use a flat structure with multiple
  top-level Sections instead.
- **Mixed scalar + section**: You can mix top-level scalar fields and Sections in the same
  `fieldsForCreate()`. Scalar fields render in the traditional label/input layout below
  all sections, while Sections render above the form as grouped cards.
