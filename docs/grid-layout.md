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

---

## Scope

Grid layout applies **only to `create` and `update` forms**.

| Context | Grid layout? |
|---------|:------------:|
| create  | ✅            |
| update  | ✅            |
| index   | ❌            |
| detail  | ❌            |

If you need to organise fields on the detail page, use `Panel` instead.

---

## API Reference

### Section::columns()

```php
Section::make(string $title, array $fields): static
Section::columns(int $columns): static
```

Creates a form section with a named CSS grid.

| Parameter  | Type     | Default | Description                                    |
|------------|----------|---------|------------------------------------------------|
| `$title`   | `string` | —       | Section heading shown in the header bar        |
| `$fields`  | `array`  | —       | Fields in this section                         |
| `$columns` | `int`    | `12`    | Number of CSS grid columns                     |

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

> **Martis extension:** `->description()` adds a subtitle below the Section title. Nova 5 does not have a Section primitive.

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

| Parameter | Type  | Default         | Description                                  |
|-----------|-------|-----------------|----------------------------------------------|
| `$cols`   | `int` | section columns | Number of grid columns this field occupies   |

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
- On index and detail views — scope is create/update only.

### Keeping spans readable

- Use a consistent grid (12 is standard): `span(6)` = half, `span(4)` = third, `span(3)` = quarter.
- Spans within a section should sum to a multiple of the section columns.
  If spans don't sum evenly (e.g. `span(7)` + `span(7)` in a 12-col grid), the browser will
  wrap the second field to the next row. This is valid but may look surprising.
- If you want a field to always be on its own row: `span(12)` explicitly or omit `span()`.

### Section vs Panel

| Feature       | Panel        | Section              |
|---------------|--------------|----------------------|
| Scope         | all contexts | create/update only   |
| Grid control  | fixed 12-col | configurable         |
| Field span    | `colSpan()`  | `span()` or `colSpan()` |
| Collapsible   | ✅            | ✅                   |
| Tabs inside   | via TabGroup | not supported        |

Use `Panel` when you need consistent layout across detail + forms.
Use `Section` when you want modern multi-column forms with grouping.

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

- **Index and detail views**: Sections are ignored. Use `Panel` for detail-page layouts.
- **Inline create (drawer)**: Sections are currently excluded from the inline drawer create form.
  Fields inside sections will not appear in inline create mode. If you need inline create
  support for these fields, define a separate `fieldsForInlineCreate()` method.
- **Span overflow**: If a field's `span()` exceeds the section's `columns()`, the browser
  will cap it at the grid width. No error is thrown; the field just takes full row width.
- **Nested sections**: Sections cannot be nested. Use a flat structure with multiple
  top-level Sections instead.
- **Mixed scalar + section**: You can mix top-level scalar fields and Sections in the same
  `fieldsForCreate()`. Scalar fields render in the traditional label/input layout below
  all sections, while Sections render above the form as grouped cards.
