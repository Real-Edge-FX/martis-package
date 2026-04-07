# Fields — Complete Reference

> Auto-generated from source code. **Every agent MUST update this file when adding or modifying fields or base field methods.**

All available field types in Martis, their methods, and configuration options.

---

## Table of Contents

- [Import](#import)
- [Base Field (Common Methods)](#base-field-common-methods)
  - [Factory](#factory)
  - [Identity](#identity)
  - [Value Resolution & Filling](#value-resolution--filling)
  - [Fluent Configuration](#fluent-configuration)
  - [Visibility](#visibility)
  - [Granular Visibility (Nova v5 Parity)](#granular-visibility-nova-v5-parity)
  - [Convenience Visibility Presets](#convenience-visibility-presets)
  - [Context-Aware Visibility](#context-aware-visibility)
  - [Sortable / Searchable](#sortable--searchable)
  - [Validation](#validation)
  - [Unique Validation](#unique-validation)
  - [Customization Hooks](#customization-hooks)
  - [Component Override](#component-override)
  - [Metadata](#metadata)
  - [Serialization](#serialization)
- [Field Types](#field-types)
  - [Id](#id)
  - [Text](#text)
  - [Textarea](#textarea)
  - [Number](#number)
  - [Boolean](#boolean)
  - [Select](#select)
  - [MultiSelect](#multiselect)
  - [Date](#date)
  - [DateTime](#datetime)
  - [Email](#email)
  - [Password](#password)
  - [Url](#url)
  - [Color](#color)
  - [Country](#country)
  - [Currency](#currency)
  - [BelongsTo](#belongsto)
  - [Tag](#tag)
  - [File](#file)
  - [Image](#image)
  - [Code](#code)
  - [Markdown](#markdown)
  - [Trix](#trix)
  - [KeyValue](#keyvalue)
  - [Heading](#heading)
  - [Hidden](#hidden)
  - [Badge](#badge)
  - [Status](#status)
  - [Gravatar](#gravatar)
  - [Sparkline](#sparkline)
- [Utility Classes](#utility-classes)
  - [DeferredRelationSync](#deferredrelationsync)
  - [FieldContext (Enum)](#fieldcontext-enum)

---

## Import

```php
use Martis\Fields\Badge;
use Martis\Fields\BelongsTo;
use Martis\Fields\Boolean;
use Martis\Fields\Code;
use Martis\Fields\Color;
use Martis\Fields\Country;
use Martis\Fields\Currency;
use Martis\Fields\Date;
use Martis\Fields\DateTime;
use Martis\Fields\Email;
use Martis\Fields\File;
use Martis\Fields\Gravatar;
use Martis\Fields\Heading;
use Martis\Fields\Hidden;
use Martis\Fields\Id;
use Martis\Fields\Image;
use Martis\Fields\KeyValue;
use Martis\Fields\Markdown;
use Martis\Fields\MultiSelect;
use Martis\Fields\Number;
use Martis\Fields\Password;
use Martis\Fields\Select;
use Martis\Fields\Sparkline;
use Martis\Fields\Status;
use Martis\Fields\Tag;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Fields\Trix;
use Martis\Fields\Url;
```

---

## Base Field (Common Methods)

All fields extend `Martis\Fields\Field` (abstract) which implements `Martis\Contracts\FieldContract`. These methods are available on **every** field type.

### Factory

| Method | Signature | Description |
|--------|-----------|-------------|
| `make` | `static make(string $attribute, ?string $label = null): static` | Create a new field instance. Label defaults to title-cased attribute name. |

```php
Text::make('title')                    // label = "Title"
Text::make('first_name', 'First Name') // explicit label
```

### Identity

| Method | Signature | Description |
|--------|-----------|-------------|
| `attribute` | `attribute(): string` | Return the model attribute name (e.g. `"title"`). |
| `label` | `label(): string` | Return the human-readable label (e.g. `"Title"`). |
| `type` | `type(): string` | Return the field type identifier (e.g. `"text"`, `"boolean"`). Abstract — each subclass implements. |

### Value Resolution & Filling

| Method | Signature | Description |
|--------|-----------|-------------|
| `resolve` | `resolve(Model $model, ?string $attribute = null): mixed` | Read the field value from a model. Respects `resolveUsing()` callback if set. |
| `resolveForDisplay` | `resolveForDisplay(Model $model, ?string $attribute = null): mixed` | Resolve then apply `displayUsing()` callback. Use for index/detail serialization. |
| `fill` | `fill(Model $model, mixed $value): void` | Write a value to the model. Respects `fillUsing()` callback and `readonly` flag. |

### Fluent Configuration

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `nullable` | `nullable(): static` | `$this` | Mark as nullable (adds `nullable` validation rule). |
| `readonly` | `readonly(): static` | `$this` | Prevent modification through UI. `fill()` becomes a no-op. |
| `required` | `required(): static` | `$this` | Require a non-null value (adds `required` validation rule). |
| `placeholder` | `placeholder(string $text): static` | `$this` | Set placeholder text for the input. |

### Visibility

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `showOnIndex` | `showOnIndex(): static` | `$this` | Show on the index (list) view. |
| `hideFromIndex` | `hideFromIndex(): static` | `$this` | Hide from the index view. |
| `showOnDetail` | `showOnDetail(): static` | `$this` | Show on the detail (show) view. |
| `hideFromDetail` | `hideFromDetail(): static` | `$this` | Hide from the detail view. |
| `showOnForms` | `showOnForms(): static` | `$this` | Show on create and edit forms. |
| `hideFromForms` | `hideFromForms(): static` | `$this` | Hide from create and edit forms. |
| `isShownOnIndex` | `isShownOnIndex(): bool` | `bool` | Check if visible on index. |
| `isShownOnDetail` | `isShownOnDetail(): bool` | `bool` | Check if visible on detail. |
| `isShownOnForms` | `isShownOnForms(): bool` | `bool` | Check if visible on forms. |

### Granular Visibility (Nova v5 Parity)

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `hideWhenCreating` | `hideWhenCreating(): static` | `$this` | Hide on create form only. |
| `hideWhenUpdating` | `hideWhenUpdating(): static` | `$this` | Hide on update form only. |
| `showOnCreating` | `showOnCreating(): static` | `$this` | Show on create form. |
| `showOnUpdating` | `showOnUpdating(): static` | `$this` | Show on update form. |

### Convenience Visibility Presets

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `onlyOnIndex` | `onlyOnIndex(): static` | `$this` | Show only on index; hide everywhere else. |
| `onlyOnDetail` | `onlyOnDetail(): static` | `$this` | Show only on detail; hide everywhere else. |
| `onlyOnForms` | `onlyOnForms(): static` | `$this` | Show only on create/update forms; hide everywhere else. |
| `exceptOnForms` | `exceptOnForms(): static` | `$this` | Show everywhere except forms. |

### Context-Aware Visibility

| Method | Signature | Description |
|--------|-----------|-------------|
| `isVisibleForContext` | `isVisibleForContext(string $context): bool` | Check visibility for a given context. Contexts: `index`, `detail`, `create`, `update`, `inline-create`, `preview`. |
| `filterForContext` | `static filterForContext(array $fields, string $context): array` | Filter an array of fields to only those visible in the given context. |

**Resolution rules per context:**

| Context | Resolves from |
|---------|---------------|
| `index` | `showOnIndex` |
| `detail` | `showOnDetail` |
| `create` / `inline-create` | `showOnCreate` ?? `showOnForms` |
| `update` | `showOnUpdate` ?? `showOnForms` |
| `preview` | `showOnPreview` ?? `showOnDetail` |

### Sortable / Searchable

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `sortable` | `sortable(bool $value = true): static` | `$this` | Allow sorting the index table by this field. |
| `searchable` | `searchable(bool $value = true): static` | `$this` | Include in global search queries. |
| `isSortable` | `isSortable(): bool` | `bool` | Check if sortable. |
| `isSearchable` | `isSearchable(): bool` | `bool` | Check if searchable. |

### Validation

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `rules` | `rules(array $rules): static` | `$this` | Add validation rules (merged with existing). |
| `buildRules` | `buildRules(): array` | `array` | Build the final validation rule array (merges required/nullable/unique + extra rules). |
| `validationMessages` | `validationMessages(): array` | `array` | Custom validation messages (e.g. for unique). |

### Unique Validation

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `unique` | `unique(array $config, ?string $message = null): static` | `$this` | Mark as unique. Config: `[table]` or `[table, column]`. |
| `setUniqueIgnoreId` | `setUniqueIgnoreId(int\|string\|null $id): void` | `void` | Set ID to ignore for unique validation (used on updates). |

```php
Email::make('email')
    ->unique(['users', 'email'], 'This email is already in use.')
```

### Customization Hooks

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `resolveUsing` | `resolveUsing(callable $callback): static` | `$this` | Override value resolution. Callback: `fn(mixed $value, Model $model, string $attribute): mixed` |
| `fillUsing` | `fillUsing(callable $callback): static` | `$this` | Override model filling. Callback: `fn(Model $model, mixed $value, string $attribute): void` |
| `displayUsing` | `displayUsing(callable $callback): static` | `$this` | Override display formatting (applied after resolveUsing, does NOT affect form values). Callback: `fn(mixed $value, Model $model, string $attribute): mixed` |

### Component Override

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `component` | `component(string $key): static` | `$this` | Override the React component used to render this field. |
| `getComponentKey` | `getComponentKey(): ?string` | `?string` | Get custom component key (null = use default). |

### Metadata

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `withMeta` | `withMeta(array $meta): static` | `$this` | Merge arbitrary key-value metadata into the field descriptor. |

### Serialization

| Method | Signature | Description |
|--------|-----------|-------------|
| `toArray` | `toArray(): array` | Serialize field to array for JSON API. Includes: `attribute`, `label`, `type`, `nullable`, `readonly`, `required`, `sortable`, `searchable`, `showOnIndex`, `showOnDetail`, `showOnForms`, `rules`, `component`, plus `extraAttributes()` and `meta`. |

---

## Field Types

### Id

**Type identifier:** `id`
**Extends:** `Field`
**File:** `src/Fields/Id.php`

Auto-incrementing primary key. Read-only, hidden from forms, sortable by default.

```php
Id::make()           // defaults: attribute='id', label='ID'
Id::make('uuid')     // custom attribute
```

**Default overrides:**
- `readonly = true`
- `showOnForms = false`
- `sortable = true`

**Specific methods:** None (all from base).

---

### Text

**Type identifier:** `text`
**Extends:** `Field`
**File:** `src/Fields/Text.php`

Single-line text input. Renders as `<input type="text">`.

```php
Text::make('title')
    ->sortable()
    ->searchable()
    ->required()
    ->placeholder('Enter title...')
```

**Specific methods:** None (all from base).

---

### Textarea

**Type identifier:** `textarea`
**Extends:** `Field`
**File:** `src/Fields/Textarea.php`

Multi-line text area. Renders as `<textarea>`.

```php
Textarea::make('body', 'Content')
    ->rows(10)
    ->nullable()
    ->hideFromIndex()
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `rows` | `rows(int $rows): static` | `$this` | Set visible row count. | `5` |
| `getRows` | `getRows(): int` | `int` | Get row count. | — |

**Extra attributes:** `rows`

---

### Number

**Type identifier:** `number`
**Extends:** `Field`
**File:** `src/Fields/Number.php`

Numeric input. Renders as `<input type="number">`.

```php
Number::make('price', 'Price')
    ->min(0)
    ->max(9999)
    ->step(0.01)
    ->integer()
    ->nullable()
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `min` | `min(int\|float $min): static` | `$this` | Set minimum value (adds `min:N` validation rule). | `null` |
| `max` | `max(int\|float $max): static` | `$this` | Set maximum value (adds `max:N` validation rule). | `null` |
| `step` | `step(int\|float $step): static` | `$this` | Set stepping interval. | `null` |
| `integer` | `integer(): static` | `$this` | Enforce integer validation. | — |

**Extra attributes:** `min`, `max`, `step` (only non-null values)

---

### Boolean

**Type identifier:** `boolean`
**Extends:** `Field`
**File:** `src/Fields/Boolean.php`

Toggle/checkbox field. Casts values to strict boolean.

```php
Boolean::make('is_active', 'Active')
    ->trueLabel('Enabled')
    ->falseLabel('Disabled')
    ->sortable()
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `trueLabel` | `trueLabel(string $label): static` | `$this` | Label for true value on index/detail. | `__('martis::messages.yes')` |
| `falseLabel` | `falseLabel(string $label): static` | `$this` | Label for false value on index/detail. | `__('martis::messages.no')` |

**Overrides:** `resolve()` casts to `(bool)`, `fill()` casts incoming to `(bool)`.
**Extra attributes:** `trueLabel`, `falseLabel`

---

### Select

**Type identifier:** `select`
**Extends:** `Field`
**File:** `src/Fields/Select.php`

Dropdown select with predefined options.

```php
Select::make('status')
    ->options([
        'Draft'     => 'draft',
        'Published' => 'published',
        'Archived'  => 'archived',
    ])
    ->nullable()
```

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `options` | `options(array $options): static` | `$this` | Set options. Accepts associative `['Label' => 'value']` or sequential `['value1', 'value2']`. |
| `getOptions` | `getOptions(): array` | `array` | Get normalized options `[{label, value}]`. |

**Extra attributes:** `options`

---

### MultiSelect

**Type identifier:** `multi_select`
**Extends:** `Field`
**File:** `src/Fields/MultiSelect.php`

Multi-value select with chips/tags UI. Stores as JSON array.

```php
MultiSelect::make('technologies')
    ->options([
        'Backend' => ['PHP' => 'php', 'Python' => 'python'],
        'Frontend' => ['React' => 'react', 'Vue' => 'vue'],
    ])
    ->displayUsingLabels()
    ->required()
```

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `options` | `options(array $options): static` | `$this` | Set options. Supports sequential, associative, and **grouped** formats. |
| `displayUsingLabels` | `displayUsingLabels(): static` | `$this` | Show labels instead of raw values on index/detail. |
| `getOptions` | `getOptions(): array` | `array` | Get normalized options `[{label, value, group?}]`. |
| `isDisplayingLabels` | `isDisplayingLabels(): bool` | `bool` | Check if displaying labels. |

**Storage format:** JSON array, e.g. `["php","react"]`
**Overrides:** `resolve()` decodes JSON/array to list; `fill()` encodes back to JSON.
**Extra attributes:** `options`, `displayLabels`

---

### Date

**Type identifier:** `date`
**Extends:** `Field`
**File:** `src/Fields/Date.php`

Date picker (date-only by default).

```php
Date::make('published_at', 'Published')
    ->withTime()          // enable date+time mode
    ->format('d/m/Y')     // custom display format
    ->nullable()
    ->sortable()
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `withTime` | `withTime(bool $value = true): static` | `$this` | Enable date+time mode (changes format to `Y-m-d H:i:s`). | `false` |
| `format` | `format(string $format): static` | `$this` | Customize display format. | `Y-m-d` |

**Overrides:** `resolve()` normalizes `Carbon`/`DateTime` to formatted string.
**Extra attributes:** `withTime`, `displayFormat`

---

### DateTime

**Type identifier:** `datetime`
**Extends:** `Date`
**File:** `src/Fields/DateTime.php`

Date + time picker. Extends Date; inherits all Date methods.

```php
DateTime::make('created_at', 'Created')
    ->hideFromForms()
    ->sortable()
```

**Specific methods:** None (inherits `withTime()`, `format()` from Date).

---

### Email

**Type identifier:** `email`
**Extends:** `Text`
**File:** `src/Fields/Email.php`

Email input with automatic `email` validation rule.

```php
Email::make('email')
    ->sortable()
    ->searchable()
    ->required()
    ->unique(['users', 'email'], 'This email is already in use.')
```

**Overrides:** `buildRules()` appends `'email'` to validation rules.
**Specific methods:** None (inherits from Text).

---

### Password

**Type identifier:** `password`
**Extends:** `Field`
**File:** `src/Fields/Password.php`

Password field. Hashes value with bcrypt, never exposes hashes.

```php
Password::make('password')
    ->nullable()
```

**Default overrides:**
- `showOnIndex = false`
- `showOnDetail = false`

**Overrides:**
- `resolve()` always returns `null` (never expose password hashes).
- `fill()` hashes with `Hash::make()`. Skips empty/null values (no update if blank).

**Specific methods:** None.

---

### Url

**Type identifier:** `url`
**Extends:** `Field`
**File:** `src/Fields/Url.php`

URL field with clickable link on index/detail and `url` validation.

```php
Url::make('website')
    ->displayText('Visit Website')
    ->nullable()
```

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `displayText` | `displayText(string $text): static` | `$this` | Static display text for the link. For dynamic text, use `displayUsing()`. |
| `getDisplayText` | `getDisplayText(): ?string` | `?string` | Get display text. |

**Overrides:** `buildRules()` appends `'url'` to validation rules.
**Extra attributes:** `displayText`

---

### Color

**Type identifier:** `color`
**Extends:** `Field`
**File:** `src/Fields/Color.php`

HTML5 color picker. Stores hex string (e.g. `#ff5733`).

```php
Color::make('brand_color', 'Brand Color')
```

**Specific methods:** None (all from base).
**Extra attributes:** None.

---

### Country

**Type identifier:** `country`
**Extends:** `Field`
**File:** `src/Fields/Country.php`

ISO 3166-1 alpha-2 country picker. Stores 2-letter code (e.g. `US`, `BR`).

```php
Country::make('country')
    ->withFlags()     // show emoji flags
    ->searchable()
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `withFlags` | `withFlags(): static` | `$this` | Enable flag emoji display. Martis extension. | `false` |
| `withoutFlags` | `withoutFlags(): static` | `$this` | Disable flag emoji display. | — |
| `hasFlags` | `hasFlags(): bool` | `bool` | Check if flags enabled. | — |
| `countryList` | `static countryList(): array` | `array` | Full ISO 3166-1 country list `[{label, value, flag}]`. | — |
| `resolveCountryName` | `static resolveCountryName(string $code): ?string` | `?string` | Get country name from 2-letter code. | — |
| `resolveCountryFlag` | `static resolveCountryFlag(string $code): ?string` | `?string` | Get flag emoji from 2-letter code. | — |

**Extra attributes:** `countries`, `showFlags`

---

### Currency

**Type identifier:** `currency`
**Extends:** `Number`
**File:** `src/Fields/Currency.php`

Monetary value input with currency formatting. Inherits `min()`, `max()`, `step()`, `integer()` from Number.

```php
Currency::make('price')
    ->currency('EUR')
    ->asMinorUnits()     // stored as cents
    ->displayMode('badge_text')
    ->badgeColor('green')
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `currency` | `currency(string $code): static` | `$this` | Set ISO 4217 currency code. Auto-sets step. | `'USD'` |
| `locale` | `locale(string $locale): static` | `$this` | Override locale for formatting. | app locale |
| `asMinorUnits` | `asMinorUnits(): static` | `$this` | Treat stored value as minor units (cents). | `false` |
| `asMajorUnits` | `asMajorUnits(): static` | `$this` | Treat stored value as major units (dollars). | — |
| `displayMode` | `displayMode(string $mode): static` | `$this` | Set display mode: `'text'`, `'badge'`, or `'badge_text'`. Martis extension. | `'text'` |
| `showBadge` | `showBadge(): static` | `$this` | Shortcut for `displayMode('badge')`. | — |
| `showText` | `showText(): static` | `$this` | Shortcut for `displayMode('text')`. | — |
| `showBadgeText` | `showBadgeText(): static` | `$this` | Shortcut for `displayMode('badge_text')`. | — |
| `badgeColor` | `badgeColor(string $color): static` | `$this` | Set badge color. Martis extension. | `null` |
| `getCurrencyCode` | `getCurrencyCode(): string` | `string` | Get currency code. | — |
| `getLocale` | `getLocale(): string` | `string` | Get effective locale. | — |
| `isMinorUnits` | `isMinorUnits(): bool` | `bool` | Check if using minor units. | — |
| `getDisplayMode` | `getDisplayMode(): string` | `string` | Get display mode. | — |
| `getBadgeColor` | `getBadgeColor(): ?string` | `?string` | Get badge color. | — |
| `getCurrencyInfo` | `getCurrencyInfo(): array` | `array` | Get `{symbol, name, decimals}` for current currency. | — |

**Supported currencies:** USD, EUR, GBP, BRL, JPY, CNY, CAD, AUD, CHF, INR, MXN, KRW, SEK, NOK, DKK, PLN, THB, ZAR, TRY, RUB, NZD, SGD, HKD, CLP, ARS, COP, PEN
**Extra attributes:** `currencyCode`, `currencySymbol`, `currencyName`, `currencyDecimals`, `locale`, `minorUnits`, `displayMode`, `badgeColor` + Number extras (`min`, `max`, `step`)

---

### BelongsTo

**Type identifier:** `belongs_to`
**Extends:** `Field`
**File:** `src/Fields/BelongsTo.php`

Many-to-one relationship field. Stores foreign key, displays related model title.

```php
// Recommended: pass FK column (auto-detects relationship via _id suffix)
BelongsTo::make('user_id', 'Author')
    ->relatedResource('users')
    ->titleAttribute('name')
    ->sortable()

// Alternative: pass relationship name
BelongsTo::make('user', 'Author')
    ->relatedResource('users')

// Many-to-many mode
BelongsTo::make('categories', 'Categories')
    ->relatedResource('categories')
    ->multiple()
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `titleAttribute` | `titleAttribute(string $attribute): static` | `$this` | Attribute on related model for display label. | `'name'` |
| `foreignKey` | `foreignKey(string $key): static` | `$this` | Override FK column name. | `{relationship}_id` |
| `relatedResource` | `relatedResource(string $uriKey): static` | `$this` | URI key of related resource for dropdown API. | `null` |
| `relationSearchable` | `relationSearchable(bool $value = true): static` | `$this` | Enable/disable text search in dropdown. | `true` |
| `multiple` | `multiple(bool $value = true): static` | `$this` | Enable many-to-many mode (pivot sync). | `false` |
| `displayAsLink` | `displayAsLink(bool $value = true): static` | `$this` | Render as clickable link on index/detail. | `true` |

**Overrides:**
- `resolve()` returns `{id, title}` (single) or `[{id, title}, ...]` (multiple).
- `fill()` sets FK (single) or registers deferred pivot sync (multiple) via `DeferredRelationSync`.

**Extra attributes:** `relationship`, `foreignKey`, `titleAttribute`, `relatedResource`, `relatedLabel`, `relationSearchable`, `multiple`, `displayAsLink`

---

### Tag

**Type identifier:** `tag`
**Extends:** `Field`
**File:** `src/Fields/Tag.php`

Relational tagging via BelongsToMany. Renders as tag chips with autocomplete.

```php
Tag::make('tags', 'Tags')
    ->relatedResource('tags')
    ->titleAttribute('name')
    ->withPreview()
    ->displayAsList()
    ->showCreateRelationButton()
    ->modalSize('3xl')
    ->preload()
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `relatedResource` | `relatedResource(string $uriKey): static` | `$this` | URI key of related resource for autocomplete API. | `null` |
| `titleAttribute` | `titleAttribute(string $attribute): static` | `$this` | Display attribute on related model. | `'name'` |
| `withPreview` | `withPreview(): static` | `$this` | Enable preview popover on hover. | `false` |
| `displayAsList` | `displayAsList(): static` | `$this` | Render as vertical list instead of chips. | `false` |
| `showCreateRelationButton` | `showCreateRelationButton(): static` | `$this` | Show inline create button. | `false` |
| `modalSize` | `modalSize(string $size): static` | `$this` | Inline creation modal size (`sm` to `7xl`). | `'2xl'` |
| `preload` | `preload(): static` | `$this` | Preload all tags on init (for small sets). | `false` |
| `relationSearchable` | `relationSearchable(bool $value = true): static` | `$this` | Enable/disable text search. | `true` |
| `getRelationship` | `getRelationship(): string` | `string` | Get relationship method name. | — |
| `getTitleAttribute` | `getTitleAttribute(): string` | `string` | Get title attribute. | — |
| `getRelatedResource` | `getRelatedResource(): ?string` | `?string` | Get related resource URI key. | — |
| `hasPreview` | `hasPreview(): bool` | `bool` | Check if preview enabled. | — |
| `isDisplayAsList` | `isDisplayAsList(): bool` | `bool` | Check if list mode. | — |
| `isShowCreateRelationButton` | `isShowCreateRelationButton(): bool` | `bool` | Check if create button shown. | — |
| `getModalSize` | `getModalSize(): string` | `string` | Get modal size. | — |
| `isPreload` | `isPreload(): bool` | `bool` | Check if preloading. | — |

**Overrides:**
- `resolve()` loads related models, returns `[{id, title}]`.
- `fill()` registers deferred pivot sync via `DeferredRelationSync`.

**Extra attributes:** `relationship`, `titleAttribute`, `relatedResource`, `withPreview`, `displayAsList`, `showCreateRelationButton`, `modalSize`, `preload`, `relationSearchable`

---

### File

**Type identifier:** `file`
**Extends:** `Field`
**File:** `src/Fields/File.php`

File upload with configurable disk and storage path.

```php
File::make('attachment', 'Attachment')
    ->disk('public')
    ->storagePath('uploads/docs')
    ->maxSize(10240)                  // 10MB in KB
    ->acceptedTypes(['pdf', 'doc'])
    ->multiple()
    ->preserveOriginalName()
    ->sanitizeFileName()
    ->nullable()
    ->hideFileInfo()
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `disk` | `disk(string $disk): static` | `$this` | Set Laravel filesystem disk. | `'public'` |
| `getDisk` | `getDisk(): string` | `string` | Get disk name. | — |
| `storagePath` | `storagePath(string $path): static` | `$this` | Set subdirectory within disk. | `'uploads'` |
| `maxSize` | `maxSize(int $kb): static` | `$this` | Set max file size in KB. | `null` |
| `acceptedTypes` | `acceptedTypes(array $mimes): static` | `$this` | Restrict accepted file extensions. | `[]` |
| `multiple` | `multiple(bool $value = true): static` | `$this` | Enable multiple file uploads (stores JSON array). | `false` |
| `isMultiple` | `isMultiple(): bool` | `bool` | Check if multiple mode. | — |
| `preserveOriginalName` | `preserveOriginalName(bool $value = true): static` | `$this` | Keep original filename (with unique suffix). | `false` |
| `sanitizeFileName` | `sanitizeFileName(bool\|callable $sanitizer = true): static` | `$this` | Sanitize filenames. Accepts `true` for default or a custom callable. | `false` |
| `showFileInfo` | `showFileInfo(bool $value = true): static` | `$this` | Show/hide file info (max size, types). | `true` |
| `hideFileInfo` | `hideFileInfo(): static` | `$this` | Hide file info display. | — |
| `deleteStoredFile` | `deleteStoredFile(Model $model): void` | `void` | Delete stored file(s) from disk. | — |
| `buildItemRules` | `buildItemRules(): array` | `array` | Validation rules for each item in multiple mode. | — |

**Overrides:**
- `resolve()` returns `{path, url, name}` (single) or `[{path, url, name}]` (multiple).
- `fill()` stores uploaded file, deletes old, supports multiple mode.
- `buildRules()` adds `file`, `mimes:...`, `max:...` rules.

**Extra attributes:** `disk`, `storagePath`, `maxSize`, `acceptedTypes`, `multiple`, `showFileInfo`

---

### Image

**Type identifier:** `image`
**Extends:** `File`
**File:** `src/Fields/Image.php`

Image upload with thumbnail generation. Inherits all File methods.

```php
Image::make('featured_image', 'Featured Image')
    ->disk('public')
    ->storagePath('posts/images')
    ->thumbnail(400, 300)
    ->maxSize(5120)
    ->acceptedTypes(['jpg', 'jpeg', 'png', 'webp'])
    ->multiple()
    ->preserveOriginalName()
    ->sanitizeFileName()
    ->nullable()
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `thumbnail` | `thumbnail(int $width = 300, int $height = 300): static` | `$this` | Enable thumbnail generation (GD or Intervention Image). | disabled |

**Default accepted types:** jpg, jpeg, png, gif, webp, bmp (SVG excluded: XSS risk).
**Overrides:**
- `resolve()` returns `{path, url, name, thumbnailUrl}`.
- `fill()` generates thumbnail after storing image.
- `buildRules()` uses `image` instead of `file`.
- `deleteStoredFile()` also deletes thumbnail.

**Extra attributes:** File extras + `thumbnailWidth`, `thumbnailHeight`

---

### Code

**Type identifier:** `code`
**Extends:** `Field`
**File:** `src/Fields/Code.php`

Syntax-highlighted code editor (CodeMirror 6). Hidden from index by default.

```php
Code::make('config', 'Configuration')
    ->language('json')
    ->json()
    ->rules(['json'])
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `json` | `json(): static` | `$this` | Treat as JSON: pretty-print on resolve, decode on fill. | `false` |
| `language` | `language(string $language): static` | `$this` | Set syntax highlighting language. | `'javascript'` |
| `isJson` | `isJson(): bool` | `bool` | Check if JSON mode. | — |
| `getLanguage` | `getLanguage(): string` | `string` | Get language. | — |

**Supported languages:** dockerfile, htmlmixed, javascript, markdown, nginx, php, ruby, sass, shell, sql, twig, vim, vue, xml, yaml-frontmatter, yaml
**Overrides:** `resolve()` pretty-prints JSON; `fill()` decodes JSON before storing.
**Extra attributes:** `json`, `language`

---

### Markdown

**Type identifier:** `markdown`
**Extends:** `Field`
**File:** `src/Fields/Markdown.php`

Markdown editor with preview. Stores raw Markdown. Hidden from index by default.

```php
Markdown::make('content')
    ->alwaysShow()
    ->preset('default')
    ->withFiles('public')
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `alwaysShow` | `alwaysShow(): static` | `$this` | Always expand content on detail (skip "Show Content" toggle). | `false` |
| `preset` | `preset(string $preset): static` | `$this` | Markdown rendering preset: `'default'` (GFM), `'commonmark'`, `'zero'`. | `'default'` |
| `withFiles` | `withFiles(string $disk = 'public'): static` | `$this` | Enable file uploads in editor. | `null` |
| `isAlwaysShow` | `isAlwaysShow(): bool` | `bool` | Check if always showing. | — |
| `getPreset` | `getPreset(): string` | `string` | Get preset. | — |
| `getWithFilesDisk` | `getWithFilesDisk(): ?string` | `?string` | Get uploads disk. | — |

**Extra attributes:** `alwaysShow`, `preset`, `withFiles`

---

### Trix

**Type identifier:** `trix`
**Extends:** `Field`
**File:** `src/Fields/Trix.php`

Rich text HTML editor (Trix). Stores raw HTML. Hidden from index by default.

```php
Trix::make('body', 'Body')
    ->alwaysShow()
    ->withFiles('public')
    ->toolbarSize('sm')
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `alwaysShow` | `alwaysShow(): static` | `$this` | Always expand content on detail. | `false` |
| `withFiles` | `withFiles(string $disk = 'public'): static` | `$this` | Enable file uploads in editor. | `null` |
| `toolbarSize` | `toolbarSize(string $size): static` | `$this` | Toolbar button size: `'sm'`, `'md'`, `'lg'`. | `null` |
| `isAlwaysShow` | `isAlwaysShow(): bool` | `bool` | Check if always showing. | — |
| `getWithFilesDisk` | `getWithFilesDisk(): ?string` | `?string` | Get uploads disk. | — |

**Extra attributes:** `alwaysShow`, `withFiles`, `toolbarSize`

---

### KeyValue

**Type identifier:** `key_value`
**Extends:** `Field`
**File:** `src/Fields/KeyValue.php`

Dynamic key-value pair editor. Stores as JSON. Hidden from index by default.

```php
KeyValue::make('metadata', 'Metadata')
    ->keyLabel('Property')
    ->valueLabel('Setting')
    ->actionText('Add Property')
    ->disableEditingKeys()
    ->disableAddingRows()
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `keyLabel` | `keyLabel(string $label): static` | `$this` | Label for key column header. | `'Key'` |
| `valueLabel` | `valueLabel(string $label): static` | `$this` | Label for value column header. | `'Value'` |
| `actionText` | `actionText(string $text): static` | `$this` | Label for "add row" button. | `'Add Row'` |
| `disableEditingKeys` | `disableEditingKeys(): static` | `$this` | Prevent editing existing keys. | `false` |
| `disableAddingRows` | `disableAddingRows(): static` | `$this` | Prevent adding new rows. | `false` |
| `getKeyLabel` | `getKeyLabel(): string` | `string` | Get key label. | — |
| `getValueLabel` | `getValueLabel(): string` | `string` | Get value label. | — |
| `getActionText` | `getActionText(): string` | `string` | Get action text. | — |
| `isEditingKeysDisabled` | `isEditingKeysDisabled(): bool` | `bool` | Check if key editing disabled. | — |
| `isAddingRowsDisabled` | `isAddingRowsDisabled(): bool` | `bool` | Check if adding disabled. | — |

**Storage format:** `{"key1":"value1","key2":"value2"}`
**Overrides:** `resolve()` decodes to `[{key, value}]` rows; `fill()` normalizes and stores as JSON.
**Extra attributes:** `keyLabel`, `valueLabel`, `actionText`, `editingKeysDisabled`, `addingRowsDisabled`

---

### Heading

**Type identifier:** `heading`
**Extends:** `Field`
**File:** `src/Fields/Heading.php`

Visual section divider. Not a data field — does not read/write model attributes. Hidden from index by default.

```php
Heading::make('media_section', 'Media')
    ->content('Featured image and attachments')
```

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `content` | `content(string $text): static` | `$this` | Descriptive text below the heading. |

**Overrides:** `resolve()` returns `null`; `fill()` is a no-op.
**Extra attributes:** `content`

---

### Hidden

**Type identifier:** `hidden`
**Extends:** `Field`
**File:** `src/Fields/Hidden.php`

Hidden form input. Invisible in UI — never shown on index or detail.

```php
Hidden::make('user_id')
```

**Default overrides:**
- `showOnIndex = false`
- `showOnDetail = false`

**Specific methods:** None.

---

### Badge

**Type identifier:** `badge`
**Extends:** `Field`
**File:** `src/Fields/Badge.php`

Visual read-only indicator. Maps model values to colored badges. Display-only (hidden from forms by default).

```php
Badge::make('status')
    ->map([
        'draft'     => 'warning',
        'published' => 'success',
        'archived'  => 'danger',
    ])
    ->withIcons()
    ->icons([
        'warning' => 'pencil',
        'success' => 'check-circle',
        'danger'  => 'archive',
    ])
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `map` | `map(array $map): static` | `$this` | Map model values to badge types (`['value' => 'type']`). | `[]` |
| `types` | `types(array $types): static` | `$this` | Override badge type definitions (replaces defaults). | `info, success, warning, danger` |
| `addTypes` | `addTypes(array $types): static` | `$this` | Add extra badge types to defaults. | — |
| `withIcons` | `withIcons(): static` | `$this` | Enable icon rendering in badges. | `false` |
| `icons` | `icons(array $icons): static` | `$this` | Map badge types to icon names (also enables icons). | `[]` |
| `getMap` | `getMap(): array` | `array` | Get value-to-type map. | — |
| `getTypes` | `getTypes(): array` | `array` | Get type definitions. | — |
| `hasIcons` | `hasIcons(): bool` | `bool` | Check if icons enabled. | — |
| `getIcons` | `getIcons(): array` | `array` | Get type-to-icon map. | — |

**Default badge types:** `info` (blue), `success` (green), `warning` (yellow), `danger` (red)
**Extra attributes:** `map`, `types`, `withIcons`, `icons`

---

### Status

**Type identifier:** `status`
**Extends:** `Field`
**File:** `src/Fields/Status.php`

Visual state/progress indicator with loading and failed states. Display-only (hidden from forms by default).

```php
Status::make('job_status', 'Job Status')
    ->loadingWhen(['waiting', 'running', 'queued'])
    ->failedWhen(['failed', 'errored', 'cancelled'])
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `loadingWhen` | `loadingWhen(array $values): static` | `$this` | Values that trigger spinner. | `[]` |
| `failedWhen` | `failedWhen(array $values): static` | `$this` | Values that trigger error indicator. | `[]` |
| `getLoadingWhen` | `getLoadingWhen(): array` | `array` | Get loading values. | — |
| `getFailedWhen` | `getFailedWhen(): array` | `array` | Get failed values. | — |

**Behavior:** Values not in either list render as "success" (completed).
**Extra attributes:** `loadingWhen`, `failedWhen`

---

### Gravatar

**Type identifier:** `gravatar`
**Extends:** `Field`
**File:** `src/Fields/Gravatar.php`

Display-only avatar from Gravatar. Generates URL from email hash. Hidden from forms by default.

```php
Gravatar::make()               // default: attribute='email', label='Avatar'
Gravatar::make('user_email')   // custom attribute
    ->squared()
    ->size(80)
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `squared` | `squared(): static` | `$this` | Display with square edges. | — |
| `rounded` | `rounded(): static` | `$this` | Display with rounded (circle) edges. | `'rounded'` |
| `size` | `size(int $size): static` | `$this` | Avatar size in pixels. | `40` |
| `getShape` | `getShape(): string` | `string` | Get shape (`'rounded'` or `'squared'`). | — |
| `getSize` | `getSize(): int` | `int` | Get size in pixels. | — |
| `gravatarUrl` | `static gravatarUrl(string $email, int $size = 40): string` | `string` | Generate Gravatar URL from email. | — |

**Overrides:** `resolve()` returns Gravatar URL (not raw email); `fill()` is a no-op.
**Extra attributes:** `shape`, `avatarSize`

---

### Sparkline

**Type identifier:** `sparkline`
**Extends:** `Field`
**File:** `src/Fields/Sparkline.php`

Inline mini chart for trend visualization. Display-only (hidden from forms by default).

```php
Sparkline::make('trend', 'Revenue Trend')
    ->data([10, 20, 15, 40, 35, 50])
    ->asBarChart()
    ->height(40)
    ->width(120)
    ->color('#22c55e')
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `data` | `data(array\|callable $data): static` | `$this` | Set chart data (array of numbers or callable receiving model). | `null` |
| `asBarChart` | `asBarChart(): static` | `$this` | Render as bar chart. | — |
| `asLineChart` | `asLineChart(): static` | `$this` | Render as line chart. | `'line'` |
| `height` | `height(int $px): static` | `$this` | Chart height in pixels. | `30` |
| `width` | `width(int $px): static` | `$this` | Chart width in pixels. | `null` |
| `color` | `color(string $color): static` | `$this` | Chart line/bar color (CSS color). | `'#6366f1'` |
| `getChartType` | `getChartType(): string` | `string` | Get chart type. | — |
| `getChartHeight` | `getChartHeight(): int` | `int` | Get height. | — |
| `getChartWidth` | `getChartWidth(): ?int` | `?int` | Get width. | — |
| `getChartColor` | `getChartColor(): string` | `string` | Get color. | — |

**Overrides:** `resolve()` returns data array (invokes callable if set, falls back to model attribute); `fill()` is a no-op.
**Extra attributes:** `chartType`, `chartHeight`, `chartWidth`, `chartColor`

---

## Utility Classes

### DeferredRelationSync

**File:** `src/Fields/DeferredRelationSync.php`

Static registry for deferred many-to-many relationship syncs. Used by `BelongsTo::multiple()` and `Tag` fields.

| Method | Signature | Description |
|--------|-----------|-------------|
| `register` | `static register(Model $model, string $relationship, array $ids): void` | Register a relationship sync to be executed after save. |
| `sync` | `static sync(Model $model): void` | Execute all pending syncs for a model, then clear them. |

Uses `WeakMap` keyed by model instances for automatic garbage collection. The `ResourceController` calls `sync()` after the model is saved.

### FieldContext (Enum)

**File:** `src/FieldContext.php`

| Case | Value | Description |
|------|-------|-------------|
| `INDEX` | `'index'` | List/table view |
| `DETAIL` | `'detail'` | Show/detail view |
| `CREATE` | `'create'` | Create form |
| `UPDATE` | `'update'` | Edit form |
| `INLINE_CREATE` | `'inline-create'` | Inline creation modal |
| `PREVIEW` | `'preview'` | Preview mode |

---

## Component Resolution Order

When rendering a field, the React frontend resolves the component in this order:

1. **Explicit key** — `->component('custom-key')` set in PHP
2. **Override by resource** — `componentRegistry.registerResourceFieldDisplay(resource, field, comp)`
3. **Override global by type** — `componentRegistry.registerFieldDisplay(type, comp)`
4. **Default component** — built-in Martis component for the type

See [Override System](overrides.md) for details.
