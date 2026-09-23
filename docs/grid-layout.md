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

Spans place a field inside a grid: the body of a `Section`, a `Panel` or a `Tab`, on the create, update and detail pages and in the create, update and detail drawers. A field declared outside any layout container is a full-width row of the form, whatever its span.

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

For fine-grained responsive control, use the breakpoint-specific variants (v1.38.0+):

```php
Text::make('name')
    ->colSpan(12)     // base span: full width (default)
    ->colSpanMd(6)    // >= 768px: half width
    ->colSpanLg(4)    // >= 1024px: one third
```

The three methods form a mobile-first cascade, like Tailwind's `col-span-* md:col-span-* lg:col-span-*`:

| Method        | Applies from    | Fallback                                 |
|---------------|-----------------|------------------------------------------|
| `colSpan()`   | `md` (768px)    | 12 (full row)                            |
| `colSpanMd()` | `md` (768px)    | inherits `colSpan()`                     |
| `colSpanLg()` | `lg` (1024px)   | inherits `colSpanMd()`, then `colSpan()` |

Below `md` every field takes the full row, whatever its spans (see [Responsiveness](#responsiveness)).
Every tier is clamped to the grid it sits in: in a `columns(3)` section a span above 3 takes the full row.

`span()` is an alias for `colSpan()` with a cleaner API suited for Section usage.
Use `colSpanMd()` / `colSpanLg()` when you need breakpoint-specific control.

> Before v1.38.0 `colSpanMd()` and `colSpanLg()` were serialised but never applied: every breakpoint used `colSpan()`.

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
| `colSpanMd()` / `colSpanLg()` | ✅          | ✅                  |
| Collapsible          | ✅                  | ✅                  |
| Can be placed inside a Tab | ✅           | ❌ (Tab accepts Panel, not Section) |

Use `Panel` when you need tabs (Tab can contain Panel but not Section), or when a fixed single-column group layout is all you need.
Use `Section` when you want configurable multi-column grid layout on forms or detail views.

---

## Responsiveness

Every field grid is responsive out of the box: the body of a Section, a Panel or a Tab, on the
create, update and detail pages and in the drawers. The breakpoints are the ones the dashboard
grid uses, `md` = 768px and `lg` = 1024px:

- **Mobile** (`< 768px`): every field takes the full row, one per row, whatever its spans. No gaps, no overlap.
- **Tablet** (`>= 768px`): each field spans `colSpanMd()`, falling back to `span()` / `colSpan()`.
- **Desktop** (`>= 1024px`): each field spans `colSpanLg()`, falling back to the tablet span.

A field that only calls `span()` keeps that span on tablets and desktops. The mobile collapse is
intentional and not configurable per field: a consistent single-column layout is always better
than trying to fit a 3-column grid on a 375px screen.

For fine-grained breakpoint control on tablet and desktop, use `colSpanMd()` and `colSpanLg()`:

```php
Section::make('Contact', [
    Text::make('name')->colSpanLg(4),                  // tablet: full row, desktop: one third
    Email::make('email')->colSpanMd(6)->colSpanLg(4),  // tablet: half, desktop: one third
    Text::make('phone')->colSpanMd(6)->colSpanLg(4),   // tablet: half, desktop: one third
])->columns(12)
```

**How it is applied (v1.38.0+).** The SPA never writes `grid-column` inline. Each field grid
carries the `.martis-field-grid` class and its track count as `--martis-field-columns`
(`Section::columns()`, 12 for a Panel or a Tab); each field carries its resolved tiers as custom
properties on the grid item (`--martis-field-span`, `--martis-field-span-md`,
`--martis-field-span-lg`, each clamped to the grid), and `.martis-field-grid` in `martis.css`
owns the placement per media query: `grid-column: 1 / -1` below `md`, then
`span var(--martis-field-span-md)` and `span var(--martis-field-span-lg)`. A Section grid also
carries `.martis-section-grid`; `.martis-form-grid` sets the gap (16px, 10px in the dense density).

A theme overrides any tier with an ordinary rule, no `!important` needed. For example, to honour
`colSpan()` on phones too:

```css
@media (max-width: 767px) {
  .martis-field-grid > * {
    grid-column: span var(--martis-field-span);
  }
}
```

Before v1.38.0 every field wrote `grid-column: span {colSpan}` inline: `colSpanMd()` /
`colSpanLg()` were ignored, only Section grids collapsed on phones (through an `!important` rule
on `.martis-section-grid`; Panel and Tab grids kept their spans at every width), and a span wider
than `Section::columns()` added implicit columns to the section grid, squeezing the real ones,
instead of taking the full row.

---

## Limitations and Notes

- **Index views**: Sections are ignored — fields are flattened into table columns. Section containers have no effect on the index.
- **Inline create (drawer)**: Sections are flattened for inline create. Every field inside a Section renders in the inline-create form, but the section header and multi-column grid are dropped — fields appear in the standard single-column label/input layout. To customise inline create fields independently, define `fieldsForInlineCreate()`.
- **Span overflow**: `span()` / `colSpan()` / `colSpanMd()` / `colSpanLg()` values are clamped to `[1, 12]` server-side, and to the grid's columns at render time: a field whose span is wider than its section's `columns()` takes the full row (v1.38.0+).
- **Nested sections**: Sections cannot be nested. Use a flat structure with multiple
  top-level Sections instead.
- **Mixed scalar + section**: You can mix top-level scalar fields and Sections in the same
  `fieldsForCreate()`. On create and update forms they render in declaration order; on the
  detail page the loose scalar fields are grouped in the Details panel below the layout
  containers. A loose scalar field is always a full-width row: spans only apply inside a
  Section, Panel or Tab grid.
