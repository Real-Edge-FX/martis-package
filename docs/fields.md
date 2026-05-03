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
  - [Granular Visibility](#granular-visibility)
  - [Convenience Visibility Presets](#convenience-visibility-presets)
  - [Context-Aware Visibility](#context-aware-visibility)
  - [Sortable / Searchable](#sortable--searchable)
  - [Validation](#validation)
  - [Unique Validation](#unique-validation)
  - [Customization Hooks](#customization-hooks)
  - [Component Override](#component-override)
  - [Metadata](#metadata)
  - [Serialization](#serialization)
- [Tooltips (Martis differential)](#tooltips-martis-differential)
- [Field Types](#field-types)
  - [Id](#id)
  - [Text](#text)
  - [Textarea](#textarea)
  - [Number](#number)
  - [Boolean](#boolean)
  - [BooleanGroup](#booleangroup)
  - [Avatar](#avatar)
  - [UiAvatar](#uiavatar)
  - [Audio](#audio)
  - [Select](#select)
  - [GuardSelect](#guardselect)
  - [MultiSelect](#multiselect)
  - [Date](#date)
  - [DateTime](#datetime)
  - [Email](#email)
  - [Password](#password)
  - [PasswordConfirmation](#passwordconfirmation)
  - [Slug](#slug)
  - [Timezone](#timezone)
  - [Icon](#icon)
  - [Stack + Line](#stack--line)
  - [Url](#url)
  - [Color](#color)
  - [Country](#country)
  - [Currency](#currency)
  - [BelongsTo](#belongsto)
  - [Tag](#tag)
  - [BelongsToMany](#belongstomany)
  - [HasOne](#hasone)
  - [HasOneOfMany](#hasoneofmany)
  - [HasOneThrough](#hasonethrough)
  - [HasMany](#hasmany)
  - [HasManyThrough](#hasmanythrough)
  - [MorphOne](#morphone)
  - [MorphOneOfMany](#morphoneofmany)
  - [MorphMany](#morphmany)
  - [MorphTo](#morphto)
  - [MorphToMany](#morphtomany)
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
use Martis\Fields\Audio;
use Martis\Fields\Avatar;
use Martis\Fields\Boolean;
use Martis\Fields\BooleanGroup;
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
use Martis\Fields\Icon;
use Martis\Fields\Id;
use Martis\Fields\Image;
use Martis\Fields\KeyValue;
use Martis\Fields\Line;
use Martis\Fields\Markdown;
use Martis\Fields\MultiSelect;
use Martis\Fields\Number;
use Martis\Fields\Password;
use Martis\Fields\PasswordConfirmation;
use Martis\Fields\Select;
use Martis\Fields\Slug;
use Martis\Fields\Sparkline;
use Martis\Fields\Stack;
use Martis\Fields\Status;
use Martis\Fields\Tag;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Fields\Timezone;
use Martis\Fields\Trix;
use Martis\Fields\UiAvatar;
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
| `nullable` | `nullable(bool\|Closure $value = true): static` | `$this` | Mark as nullable (adds `nullable` validation rule). Accepts a closure for request-time resolution. |
| `readonly` | `readonly(bool\|Closure $value = true): static` | `$this` | Prevent modification through UI. `fill()` becomes a no-op. Accepts a closure for request-time resolution. |
| `required` | `required(bool\|Closure $value = true): static` | `$this` | Require a non-null value (adds `required` validation rule). Accepts a closure for request-time resolution. **v1.8.3**: declaring `'required'` (or any `required_*` variant) inside `->rules([...])` is enough — the visual asterisk now auto-detects it. Calling `->required()` explicitly is still supported and required when you want a Closure-resolved flag. |
| `placeholder` | `placeholder(string\|Closure $text): static` | `$this` | Set placeholder text for the input. Accepts a closure for request-time resolution. |
| `help` | `help(string\|Closure $text): static` | `$this` | Set help text displayed below the field input. Supports inline HTML (Martis extension). Accepts a closure for request-time resolution. |
| `tooltip` | `tooltip(string\|Closure\|null $text): static` | `$this` | ⭐ Martis differential. Attach a hover tooltip to the field label — shown via a `(?)` icon next to the label. Supports raw HTML so authors can use `<br />`, `<strong>`, `<em>`, `<ul>`, etc. for multi-line rich hints. Accepts a closure for request-time resolution. Pass `null` to clear. See [Tooltips](#tooltips-martis-differential). |
| `withLabel` | `withLabel(string\|Closure $value): static` | `$this` | Override the constructor label after construction. Accepts a closure for request-time resolution. |
| `fullWidth` | `fullWidth(bool $fullWidth = true): static` | `$this` | Make the field span the full width of the form. |
| `stacked` | `stacked(bool $stacked = true): static` | `$this` | Control label position: stacked above (true) or inline (false). |
| `default` | `default(mixed $value): static` | `$this` | Set a default value for the field on create forms. Accepts a closure for request-time resolution. |

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

### Granular Visibility

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `hideWhenCreating` | `hideWhenCreating(): static` | `$this` | Hide on create form only. |
| `hideWhenUpdating` | `hideWhenUpdating(): static` | `$this` | Hide on update form only. |
| `showOnCreating` | `showOnCreating(): static` | `$this` | Show on create form. **v1.8.4**: required to opt back in for relationship fields whose persistence requires a saved parent — `BelongsToMany` and `MorphToMany` are hidden on create by default; `HasOne`, `HasMany`, `HasManyThrough`, `HasOneThrough`, `HasOneOfMany`, `MorphOne`, `MorphMany`, `MorphOneOfMany` stay detail-only as before. |
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
| `rules` | `rules(array\|Closure $rules): static` | `$this` | Add validation rules that apply on every context (merged with existing). Accepts a closure for request-time resolution; closure replaces any prior static rule list (and vice versa). See [Closure-aware setters](#closure-aware-setters). |
| `creationRules` | `creationRules(array $rules): static` | `$this` | Rules that apply ONLY on POST `/resources/{r}` (create context). Layered on top of `rules()`. |
| `updateRules` | `updateRules(array $rules): static` | `$this` | Rules that apply ONLY on PUT `/resources/{r}/{id}` (update context). Layered on top of `rules()`. |
| `buildRules` | `buildRules(?string $context = null): array` | `array` | Build the final rule array. Pass `'create'` or `'update'` to layer the matching context rules. |
| `validationMessages` | `validationMessages(): array` | `array` | Custom validation messages (e.g. for unique). |

**Context-aware example** — password is required on create but optional on update (the canonical pattern):

```php
Password::make('password')
    ->rules(['min:8'])              // applies on every context
    ->creationRules(['required'])   // create only
    ->updateRules(['nullable']);    // update only
```

The controller hits `buildRules('create')` for POST and `buildRules('update')` for PUT. The schema endpoint also exposes both rule sets under `creationRules` / `updateRules` keys so the React frontend can pre-validate per context.

When `creationRules` contains `required`, the base `sometimes` rule is automatically stripped — `sometimes` short-circuits validation when a key is missing and would defeat the `required` directive otherwise.

### Immutable fields

`immutable()` flags a field as **writable on create, readonly on update**. The controller silently skips the fill on update (the request is accepted, the column is not mutated). The schema also exposes the flag so the frontend can render the input as disabled on the edit page.

```php
Text::make('slug')->immutable()->required();
```

Common cases: slugs, account numbers, document references.

### Reactive fields — `dependsOn(['field'], Closure)`

⭐ **Martis differential** — declare that a field reacts to one or more sibling fields. The frontend watches the listed attributes and, every time the user edits any of them, posts the live form payload to `POST /api/resources/{r}/sync-field`. The backend re-runs the closure with the fresh data and returns the updated field descriptor (visibility, readonly, required, options, placeholder, help, default, meta, …) — the live form replaces its local descriptor with the response.

The closure receives:
- `array<string, mixed> $formData` — the live form values, keyed by field attribute (the watched siblings are guaranteed; any other touched attribute is forwarded too).
- `Illuminate\Http\Request $request` — the active request (auth user, locale, headers).
- `static $field` — the field instance, mutable. Call any of the regular fluent methods on it.

Examples:

```php
// Make `price` required only when `is_paid` is true:
Number::make('price')
    ->dependsOn(['is_paid'], function (array $form, Request $r, Number $field) {
        $field->required((bool) ($form['is_paid'] ?? false));
    });

// Reload Select options whenever `category_id` changes:
Select::make('subcategory_id')
    ->dependsOn(['category_id'], function (array $form, Request $r, Select $field) {
        $field->options(
            \App\Models\Subcategory::query()
                ->where('category_id', $form['category_id'] ?? null)
                ->pluck('name', 'id')
                ->all(),
        );
    });

// Branch on the current user — works in both create and update contexts:
Text::make('access_code')
    ->dependsOn(['plan'], function (array $form, Request $r, Text $field) {
        if (($form['plan'] ?? null) === 'enterprise' && $r->user()?->isAdmin()) {
            $field->readonly(false);
        } else {
            $field->readonly(true);
        }
    });
```

| Method | Signature | Description |
|---|---|---|
| `dependsOn` | `dependsOn(array $fields, ?Closure $callback = null): static` | Declare reactivity. The closure is optional; passing `null` lets layouts forward the watch list without running a callback (the Repeater uses this form for parent-attribute exposure). |
| `dependentFields` | `dependentFields(): list<string>` | Read the watched attributes. |
| `isDependent` | `isDependent(): bool` | True only when both `$fields` and a `Closure` are configured. The sync endpoint refuses non-reactive fields. |
| `syncDependent` | `syncDependent(array $formData, Request $request): static` | Run the closure against the given payload. Mutates `$this`. Useful in tests. |

**Wire format.** The schema endpoint serializes `dependsOn: { fields: ['attr1', 'attr2'] }` (an object) for reactive fields and `null` for non-reactive ones. The frontend `useDependsOnSync` hook subscribes to the listed attributes, debounces 200ms, and POSTs the payload when any watched value changes — older requests are aborted via `AbortController` so the latest value always wins.

**Endpoint.** `POST /api/resources/{r}/sync-field` body: `{ field: string, formData: object, context?: 'create' | 'update' }`. Response: a single field descriptor in the same shape as `schema.fields[]`. Authorization gates the same as create/update.

### Closure-aware setters

Most field setters accept either a static value or a closure that resolves at request time. Reach for the closure form whenever the result depends on the authenticated user, the locale, the active tenant, or anything else that lives on the `Request`.

The closure receives the active `Request` (or `null` when called outside an HTTP context, e.g. a queue worker) and must return the resolved value. Static values still work — the closure form is purely additive.

| Setter | Signature | Lazy reader |
|--------|-----------|-------------|
| `readonly` | `readonly(bool\|Closure $value = true): static` | `isReadonly(): bool` |
| `required` | `required(bool\|Closure $value = true): static` | `isRequired(): bool` |
| `nullable` | `nullable(bool\|Closure $value = true): static` | `isNullable(): bool` |
| `default` | `default(mixed $value): static` | `getDefaultValue(): mixed` |
| `placeholder` | `placeholder(string\|Closure $text): static` | `getPlaceholder(): ?string` |
| `help` | `help(string\|Closure $text): static` | `getHelp(): ?string` |
| `tooltip` | `tooltip(string\|Closure\|null $text): static` | `getTooltip(): ?string` |
| `withLabel` | `withLabel(string\|Closure $value): static` | `label(): string` |
| `rules` | `rules(array\|Closure $rules): static` | `buildRules(): array` |
| `Select::options` | `options(array\|Closure $options): static` | `getOptions(): list` |
| `MultiSelect::options` | `options(array\|Closure $options): static` | `getOptions(): list` |
| `BooleanGroup::options` | `options(array\|Closure $options): static` | `getOptions(): array` |

Examples:

```php
// Per-user defaults
Text::make('owner_id')
    ->default(fn ($request) => $request?->user()?->id);

// Conditional readonly based on policy
Text::make('email')
    ->readonly(fn ($request) => $request?->user()?->cannot('change-email'));

// Required only for non-admins
Text::make('reason')
    ->required(fn ($request) => $request?->user()?->cannot('skip-reason'));

// Locale-aware label and placeholder
Text::make('greeting')
    ->withLabel(fn () => __('fields.greeting.label'))
    ->placeholder(fn () => __('fields.greeting.placeholder'));

// Help text that reads live state from the user
Text::make('quota')
    ->help(fn ($request) => "Quota left: {$request?->user()?->quota()}");

// Options pulled from the database — Select / MultiSelect / BooleanGroup
Select::make('owner_id')
    ->options(fn () => User::query()->pluck('name', 'id')->all());

MultiSelect::make('skills')
    ->options(fn () => [
        'Backend'  => ['PHP' => 'php', 'Go' => 'go'],
        'Frontend' => ['React' => 'react'],
    ]);

BooleanGroup::make('permissions')
    ->options(fn ($request) => $request?->user()?->availablePermissions()->all() ?? []);

// Validation rules that vary per role
Text::make('cap')
    ->rules(fn ($request) => $request?->user()?->isAdmin()
        ? ['nullable']
        : ['required', 'integer', 'max:100']);
```

#### `withLabel()` vs the constructor label

`Field::make('attribute', 'Label')` accepts the label as a positional argument. `withLabel()` is the post-construction setter that also accepts a closure. The contract method `label()` is the **getter** — `withLabel()` is named explicitly to avoid overloading it.

#### `rules(callable)` semantics

Static and closure rule definitions do **not** compose. The last call wins:

```php
Text::make('field')
    ->rules(['min:1'])      // pinned
    ->rules(fn () => ['email']); // closure replaces ['min:1']

Text::make('field')
    ->rules(fn () => ['email']) // closure
    ->rules(['min:1']);         // static replaces the closure
```

If you need both static and dynamic rules, return them together from a single closure:

```php
->rules(fn ($request) => array_merge(['min:1'], $request?->user()?->isAdmin() ? [] : ['max:100']))
```

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

The three core hooks let you replace the default read / write / format behaviour of a field with arbitrary logic. Unlike the lazy setters in [Closure-aware setters](#closure-aware-setters), these hooks do not have a static counterpart — they ARE the customization.

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `resolveUsing` | `resolveUsing(callable $callback): static` | `$this` | Override value resolution. Callback: `fn(mixed $value, Model $model, string $attribute, ?Request $request): mixed` |
| `fillUsing` | `fillUsing(callable $callback): static` | `$this` | Override model filling. Callback: `fn(Model $model, mixed $value, string $attribute, ?Request $request): void` |
| `displayUsing` | `displayUsing(callable\|list<callable> $callback): static` | `$this` | Override display formatting (applied after resolveUsing, does NOT affect form values). Single callable: `fn(mixed $value, Model $model, string $attribute, ?Request $request): mixed`. Pass an array of callables to compose a pipeline. |

#### ⭐ Martis differential 1 — `?Request` is forwarded to every hook

The active `Request` (or `null` when there is no HTTP context, e.g. a queue worker) is forwarded as the **fourth argument** of every hook. Closures with three parameters keep working unchanged because PHP silently drops trailing arguments that the callback did not declare.

```php
// Per-tenant resolve without calling request() manually:
Text::make('balance')->resolveUsing(
    fn ($value, $model, $attribute, ?Request $request) =>
        $request?->user()?->isAdmin()
            ? number_format((float) $value, 2)
            : '*****',
);

// Per-locale fill that auto-converts decimals:
Number::make('price')->fillUsing(
    fn (Model $model, $value, string $attribute, ?Request $request) =>
        $model->setAttribute(
            $attribute,
            (float) str_replace(
                $request?->getPreferredLanguage(['pt_BR', 'en']) === 'pt_BR' ? ',' : '.',
                '.',
                (string) $value,
            ),
        ),
);
```

#### ⭐ Martis differential 2 — `displayUsing()` accepts a chainable pipeline

`displayUsing()` accepts either a single callable (legacy) or a `list<callable>`. When an array is passed, each callback receives the output of the previous one — equivalent in spirit to `array_reduce`.

```php
// Pipeline: cast → format → prefix
Text::make('amount')->displayUsing([
    fn ($v) => (float) $v,
    fn ($v) => number_format($v, 2),
    fn ($v) => "R$ {$v}",
]);

// Pipeline + per-stage Request access:
Text::make('balance')->displayUsing([
    fn ($v) => (float) $v,
    fn ($v, $m, $attr, ?Request $r) => $r?->user()?->isAdmin()
        ? number_format($v, 2)
        : '*****',
    fn ($v) => "<strong>{$v}</strong>",
]);
```

If any entry in the array is not callable, `displayUsing()` throws `InvalidArgumentException` immediately at definition time — you get the failure when the resource boots, not deep inside a request.

A subsequent single-callable call replaces the entire pipeline. A subsequent array call replaces the prior single callable. The hook is monotonic: only the most recent definition is active.

#### Backward compatibility

| Old code | Still works? |
|---|---|
| `displayUsing(fn ($v) => ...)` | ✅ |
| `displayUsing(fn ($v, $m) => ...)` | ✅ |
| `displayUsing(fn ($v, $m, $attr) => ...)` | ✅ |
| `resolveUsing(fn ($v, $m, $attr) => ...)` | ✅ |
| `fillUsing(fn ($m, $v, $attr) => ...)` | ✅ |

The 4th `?Request $request` argument is purely additive. Existing 3-arg callbacks keep their semantics unchanged.

### Component Override

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `component` | `component(string $key): static` | `$this` | Override the React component used to render this field. |
| `getComponentKey` | `getComponentKey(): ?string` | `?string` | Get custom component key (null = use default). |

### Metadata

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `withMeta` | `withMeta(array $meta): static` | `$this` | Merge arbitrary key-value metadata into the field descriptor. |

### Index Table Column Width

Controls how the field renders as a column in the index table. See [resources.md](resources.md#tablelayout) for the Resource-level `tableLayout()` switch.

| Method | Signature | Description |
|--------|-----------|-------------|
| `width` | `width(string $value): static` | Fix the column width (e.g. `"80px"`, `"10rem"`). |
| `minWidth` | `minWidth(string $value): static` | Minimum width. Useful on title columns that would otherwise collapse. |
| `maxWidth` | `maxWidth(string $value): static` | Maximum width. Pair with `truncate()` on URL / email columns. |
| `truncate` | `truncate(bool $value = true): static` | Clip overflow with an ellipsis. Call with `false` to cancel a type default. |

Type defaults (applied automatically unless overridden):

| Field | Default |
|-------|---------|
| `Id` | `width 80px` |
| `Email`, `Url` | `maxWidth 280px` + `truncate` |
| `Boolean`, `Status`, `Badge` | `width 120px` |
| `Date`, `DateTime` | `width 140px` |
| Column matching `titleAttribute()` | `minWidth 220px` |

All defaults can be disabled globally via `config('martis.index.column_defaults', false)` (env `MARTIS_INDEX_COLUMN_DEFAULTS`). Explicit per-field calls still apply. See [resources.md](resources.md#opting-out-of-the-type-default-heuristics) for details.

### Serialization

| Method | Signature | Description |
|--------|-----------|-------------|
| `toArray` | `toArray(): array` | Serialize field to array for JSON API. Includes: `attribute`, `label`, `type`, `nullable`, `readonly`, `required`, `sortable`, `searchable`, `showOnIndex`, `showOnDetail`, `showOnForms`, `rules`, `component`, plus `extraAttributes()` and `meta`. |

---

## Tooltips (Martis differential)

⭐ **Martis-exclusive.** In addition to `help()` (plain text under the input),
Martis adds **label tooltips** as a separate channel so
authors can surface contextual guidance without committing valuable real estate
to permanent inline text.

### Why it matters

- `help()` is always visible. It costs vertical space on every form render, for
  every user, regardless of how long they've been using the resource.
- A **tooltip** is opt-in: the hint shows only when the user hovers the
  discreet `(?)` icon next to the label. Experienced users never see it;
  new users have it one hover away.
- HTML support means you can pack **rich, multi-line guidance** (lists, bold,
  line breaks) into a single call without bloating the form.

### API

```php
Text::make('name', 'Full name')
    ->tooltip('<strong>Full legal name</strong>.<br />Examples:<br />• John Smith<br />• Ana Pereira<br /><br /><em>Avoid abbreviations.</em>');
```

| Signature | Notes |
|-----------|-------|
| `tooltip(?string $text): static` | Sets the tooltip. Pass `null` to clear. |
| `getTooltip(): ?string` | Returns the current tooltip text (or `null`). |

The tooltip string ships to the frontend in the serialized field under the key
`tooltip` and is rendered by the global `MartisTooltip` component behind the
`(?)` label icon on any form renderer (Panel, Section, TabGroup, ResourceCreate,
ResourceUpdate) **and** on detail labels rendered inside Sections/TabGroups.

### HTML support

The frontend opts in via the `data-pr-tooltip-html="true"` attribute, so only
field tooltips render as HTML — every other `data-pr-tooltip` trigger in the
app keeps the default plain-text escape. Allowed markup: any inline HTML
(`<br />`, `<strong>`, `<em>`, `<ul>`/`<li>`, `<code>`, `<a>`). The author is
responsible for producing safe markup; prefer localised strings from
`__()` / i18n dictionaries to keep content reviewable.

### When to use `tooltip()` vs `help()`

| Situation | Use |
|-----------|-----|
| Short, essential instruction that every user should read (e.g. "Must be unique") | `help()` |
| Long-form, rarely-needed context (examples, edge cases, links to docs) | `tooltip()` |
| Multi-line rich guidance (bullet lists, bold headings) | `tooltip()` |
| Validation hint that depends on input state | `help()` |

Both can coexist on the same field: `->help('Must be unique')->tooltip('<strong>Uniqueness rules</strong><br />...')`.

### Frontend behaviour

- The `(?)` icon uses the muted text colour so it reads as a quiet affordance.
- Hover delay is **500 ms** — long enough that a cursor skimming the form
  doesn't flash tooltips, short enough that intentional hover feels responsive.
- Tooltip content falls back to `white-space: nowrap` for plain text and
  `white-space: normal` for HTML content so `<br />` and wrapping actually work.
- Position respects the trigger's `data-pr-position` (defaults to `top`).

### Why NOT a `Tooltip` field class

We deliberately rejected adding a `Tooltip::make()` field. Reasons:

1. A `Field` represents a **column or value**. A pure tooltip has nothing to
   read, write, validate, or serialise — it breaks the contract.
2. Tooltips are a **presentation concern that applies to fields**, not a field
   type of their own. Inverting that relationship forces authors to position
   an empty "tooltip field" inside the form, competing with real fields for
   `colSpan` and layout placement.
3. The `->tooltip()` modifier is **universal**: it applies to every existing
   and future field without touching the UI layer.

If you need a hint unrelated to any single field (e.g. a section intro), use
`Panel::make('', [])->description(...)` or `Section::make(...)->description(...)`
— not a field.

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

### BooleanGroup

**Type identifier:** `boolean_group`
**Extends:** `Field`
**File:** `src/Fields/BooleanGroup.php`

Map of named boolean flags stored as JSON. Ideal for permission grids, feature gates, notification preferences.

```php
BooleanGroup::make('permissions')
    ->options([
        'clients.view' => 'View clients',
        'clients.edit' => 'Edit clients',
        'billing.view' => 'View billing',
    ])
    ->grouped([
        'Clients' => ['clients.view', 'clients.edit'],
        'Billing' => ['billing.view'],
    ])
    ->requireAny();
```

| Method | Description |
|---|---|
| `options(array\|Closure)` | Flag key → label map. Accepts a closure that resolves at render time. See [Closure-aware setters](#closure-aware-setters). |
| `labels(array)` | Override option labels with translations |
| `grouped(array)` ⭐ | Organise options into collapsible sections (`['Title' => ['key1','key2']]`) |
| `hideFalseValues(bool)` / `hideTrueValues(bool)` | Display filter for the read-only view |
| `noValueText(string)` | Placeholder when every flag is off and `hideFalseValues` is on |
| `minChecked(int)` ⭐ | Minimum number of flags that must be on — shown as a live UI counter |
| `maxChecked(int)` ⭐ | Maximum number of flags that can be on — disables extra checkboxes live |
| `requireAny()` ⭐ | Sugar for `minChecked(1)` |
| `requireAll()` ⭐ | Sugar for `minChecked(count(options))` |

> ⚠️ When `options()` is given a closure, `requireAll()` cannot pre-compute its target at field declaration time — the closure has not run yet. Pair the closure form with `minChecked(int)` directly, or use `requireAny()` (always `1`).

**⭐ Martis differentials:** grouped sections, min/max live counter, `requireAny/All` presets.

---

### Avatar

**Type identifier:** `avatar`
**Extends:** `Image`
**File:** `src/Fields/Avatar.php`

Image upload specialised for profile pictures. Inherits every upload helper from `Image` (`disk`, `storagePath`, `maxSize`, `thumbnail`, …).

**Zero configuration needed for the empty state** — when a record has no upload AND the developer didn't declare an explicit `fallback()`, the field renders coloured initials inline using a deterministic 16-slot palette (same look as the topbar user pill). No external service call, no extra DB column, no boilerplate closure.

```php
// One-liner: uploaded photo when present, coloured initials inline otherwise.
Avatar::make('avatar_path')
    ->disk('public')
    ->storagePath('team-avatars')
    ->maxSize(2048)
    ->circle()
    ->colorFrom('brand_color'); // optional: use a brand attribute instead of the hash palette
```

**Resolution priority (per row):**

| # | Condition | Output |
|---|---|---|
| 1 | Stored file exists | `<img src={uploaded_url}>` |
| 2 | Developer set `fallback($url \| Closure)` | `<img src={fallback}>` |
| 3 | Seed has initials | Inline coloured initials — no external request |
| 4 | Seed exists but produces no initials | Muted user-glyph chip (`.martis-avatar-fallback`) |
| 5 | Nothing at all | em-dash placeholder |

| Method | Description |
|---|---|
| `shape(AvatarShape)` | Typed enum: `Circle` (default), `Rounded`, `Squared` |
| `circle()` / `rounded()` / `squared()` | Convenience shortcuts |
| `fallback($url \| Closure)` ⭐ | Override the default inline initials with a custom URL (static or per-row) — opt-in, usually unnecessary |
| `initialsFrom(string)` ⭐ | Seed attribute for initials + palette (default: `name`) |
| `colorFrom(string)` ⭐ | Pull the initials background from a model attribute (e.g. `brand_color`) |
| `initials(Closure)` ⭐ | Custom initials computation. Closure receives `($seed, $model)` |

**⭐ Martis differentials:**
- **Zero-config inline initials fallback** — no external service, no extra closures, works out of the box.
- **Deterministic 16-hue palette** declared as `--martis-avatar-1..16` tokens, stable across light/dark themes. The `lib/avatarPalette.ts` helper picks one from a hash of the seed (`name`, `email`, `slug`) — two users with the same name always get the same colour.
- **Empty-seed glyph** — when the record has no name/initials, the field renders a muted user icon inside `.martis-avatar-fallback` instead of a bare em-dash.
- Per-row Closure-aware `fallback()` when you *do* want a custom URL.
- Typed `AvatarShape` enum instead of a boolean `rounded()`.
- Deterministic palette shared with `UiAvatar`, login, topbar and profile surfaces via the [`ResolvesInitialsPayload`](../src/Fields/Concerns/ResolvesInitialsPayload.php) trait.

---

### UiAvatar

**Type identifier:** `ui_avatar`
**Extends:** `Field`
**File:** `src/Fields/UiAvatar.php`

Display-only initials pill. Differs from `Avatar` in one key way: **it never takes an upload** — the value is computed entirely from the model.

**When to pick `UiAvatar` over `Avatar`:**

| Your resource… | Use |
|---|---|
| has a photo column and you want users to upload | `Avatar` (initials inline when empty come for free) |
| has no photo column at all and never will | `UiAvatar` |
| is read-only / system-generated and an upload input would be noise | `UiAvatar` |

```php
UiAvatar::make('avatar_initials')
    ->from('name')
    ->colorFrom('brand_color')
    ->circle();
```

| Method | Description |
|---|---|
| `from(string)` | Attribute used as the seed (default: field's own attribute) |
| `shape(AvatarShape)` / `circle()` / `rounded()` / `squared()` | Match the visual style of `Avatar` |
| `colorFrom(string)` ⭐ | Pull background colour from a model attribute (brand colour) |
| `initials(Closure)` ⭐ | Custom initials computation. Closure receives `($seed, $model)` |

**⭐ Martis differentials:** **deterministic 16-slot palette from seed hash** (same name → same colour, zero DB), `colorFrom('attribute')` override, custom-initials closure, decoupled seed via `from()`. Runs entirely client-side with no network call.

---

### Audio

**Type identifier:** `audio`
**Extends:** `File`
**File:** `src/Fields/Audio.php`

File upload specialised for audio clips. Renders a fully custom on-brand player (accent play/pause button, waveform OR progress track, mono current/total timestamps, optional download affordance) and a drag-and-drop dropzone empty state. The native `<audio controls>` element is intentionally bypassed so the look stays consistent under both light and dark themes.

```php
Audio::make('intro_audio_path')
    ->disk('public')
    ->storagePath('team-audio')
    ->maxSize(10240)
    ->downloadable(false);
```

| Method | Description |
|---|---|
| `waveform(bool)` ⭐ | Toggle the canvas waveform (default `true`). When false the player falls back to a thin progress track. |
| `downloadable(bool)` | Toggle the download icon next to the player. |
| `acceptedTypes(array)` | Override the default `mp3/wav/ogg/m4a/flac/aac` allow-list. |

**Empty state.** When no file is attached, the input renders a dashed dropzone with a Phosphor `MusicNote` icon, the `audio_empty_title` and `audio_empty_hint` copy, and a "Choose file" CTA. Drag-and-drop is supported.

**i18n keys** (in `messages.php`, all three locales):

`audio_empty_title`, `audio_empty_hint`, `audio_browse`, `audio_replace`, `audio_remove`, `audio_play`, `audio_pause`, `audio_download`.

**⭐ Martis differentials:** client-side canvas waveform via Web Audio API (no server rendering), custom player chrome that follows the design tokens, drag-and-drop dropzone, `downloadable(bool)` toggle.

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
| `options` | `options(array\|Closure $options): static` | `$this` | Set options. Accepts associative `['Label' => 'value']`, sequential `['value1', 'value2']`, or a closure that resolves at render time (perfect for DB-backed lists). See [Closure-aware setters](#closure-aware-setters). |
| `optionsFromMap` | `optionsFromMap(array $map): static` | `$this` | Set options from a `[value => label]` map. More ergonomic than `options()` when labels come from i18n: the value (what's persisted) stays unchanged while the label can be translated. |
| `displayUsingLabels` | `displayUsingLabels(): static` | `$this` | Render the option label on index and detail (default behaviour). Symmetric with `MultiSelect::displayUsingLabels()` for code that handles both fields generically. |
| `displayUsingValues` | `displayUsingValues(): static` | `$this` | Render the raw stored value on index and detail. Useful when the value is itself meaningful (ISO codes, slugs) and the label is just a humanised alias. |
| `isDisplayingLabels` | `isDisplayingLabels(): bool` | `bool` | Whether the field currently renders labels (true) or raw values (false). |
| `getOptions` | `getOptions(): array` | `array` | Get normalized options `[{label, value}]` (resolves the closure if one was set). |

**Extra attributes:** `options`, `displayLabels`

```php
// Static options
Select::make('status')->options(['Draft' => 'draft', 'Published' => 'published']);

// value => label map — keeps stored value stable when translating
Select::make('plan')->optionsFromMap([
    'free' => __('plans.free'),
    'pro'  => __('plans.pro'),
]);

// Database-backed options resolved at render time
Select::make('owner_id')->options(fn () => User::query()->pluck('name', 'id')->all());

// Show the raw ISO code on the index column instead of the country name
Select::make('country_code')
    ->options(['Portugal' => 'PT', 'United Kingdom' => 'GB'])
    ->displayUsingValues();
```

---

### GuardSelect

**Type identifier:** `select` (inherits)
**Extends:** `Select`
**File:** `src/Fields/GuardSelect.php`

Dropdown of the auth guards configured in the host app's `config/auth.php`. Designed for `guard_name` columns on Spatie Permission / Role tables — most installs have a single guard (`web`), but the field gives the developer a confident UI to pick from instead of typing free text. Shipped in v1.8.0.

```php
use Martis\Fields\GuardSelect;
use Martis\Fields\Slug;

public function fields(Request $request): array
{
    return [
        Id::make()->sortable(),
        Slug::make('name')
            ->separator('.')
            ->reserved(['*'])
            ->help(__('martis::permissions.name_help'))
            ->required(),
        GuardSelect::make('guard_name')
            ->help(__('martis::permissions.guard_help'))
            ->required(),
    ];
}
```

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `make` | `make(string $attribute, ?string $label = null): static` | `static` | Factory. Auto-wires the guard-list resolver, pulls the default value from `config('auth.defaults.guard')`, marks the field `required()`, and validates submitted values against `config('auth.guards')` keys via `Rule::in(...)`. |
| `only` | `only(array $guardNames): static` | `$this` | Restrict the dropdown to a subset of the configured guards. Useful when a Resource only makes sense against one specific guard (`api` permissions vs `web` permissions managed in separate sections of the admin). Empty strings are filtered out. |

The label and value are always identical — guard names are tokens (`web`, `api`, `sanctum`) and translating them would be misleading. Server-side validation rejects any value not declared in `config/auth.guards`, which prevents Spatie Permission from later crashing on `Role::users()` (`morphedByMany` cannot resolve the User model for an unconfigured guard).

The list is fetched lazily at schema-render time so `config()` is read after the host app has finished booting. The catalog comes from `Martis\Auth\GuardCatalog`, which exposes `available(): list<string>` and `default(): string` for advanced uses.

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
| `options` | `options(array\|Closure $options): static` | `$this` | Set options. Supports sequential, associative, and **grouped** formats, or a closure that resolves at render time. See [Closure-aware setters](#closure-aware-setters). |
| `displayUsingLabels` | `displayUsingLabels(): static` | `$this` | Show labels instead of raw values on index/detail. |
| `getOptions` | `getOptions(): array` | `array` | Get normalized options `[{label, value, group?}]` (resolves the closure if one was set). |
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
    ->withStrengthMeter()
```

**Default overrides:**
- `showOnIndex = false`
- `showOnDetail = false`

**Overrides:**
- `resolve()` always returns `null` (never expose password hashes).
- `fill()` hashes with `Hash::make()`. Skips empty/null values (no update if blank).

**Specific methods:**
- `withStrengthMeter(bool $enabled = true): static` — ⭐ **Martis extension.** Shows a 0–4 strength meter below the input (length + character-class heuristic). Pairs naturally with `PasswordConfirmation` to share the same UI cue. No extra dependency — zxcvbn-lite is inlined in the React component.

**Declarative complexity requirements (⭐ Martis extension):**

Each requirement method adds a matching Laravel validation rule AND publishes to the frontend so the `showRequirements()` checklist can tick in real time.

- `minLength(int $length): static` — minimum length. Adds `min:N`.
- `requireUppercase(bool $value = true): static` — adds `regex:/[A-Z]/`.
- `requireLowercase(bool $value = true): static` — adds `regex:/[a-z]/`.
- `requireNumber(bool $value = true): static` — adds `regex:/\d/`.
- `requireSymbol(bool $value = true): static` — adds `regex:/[^A-Za-z0-9]/`.
- `disallowCommonPasswords(bool $value = true): static` — rejects a small inline list (`password`, `qwerty`, `12345…`, `letmein`, `admin`, `welcome`, `abc123`, `iloveyou`) via a closure rule.
- `showRequirements(bool $value = true): static` — opt-in. Renders the ✓/✗ checklist under the strength meter. When every requirement passes, the meter is clamped to at least "Good" (score ≥ 3) so "all checks green" never reads as "Weak".

Example:

```php
Password::make('password')
    ->withStrengthMeter()
    ->minLength(10)
    ->requireUppercase()
    ->requireNumber()
    ->requireSymbol()
    ->disallowCommonPasswords()
    ->showRequirements()
```

---

### PasswordConfirmation

**Type identifier:** `password_confirmation`
**Extends:** `Field`
**File:** `src/Fields/PasswordConfirmation.php`

Companion field for a paired `Password`. Never persists to the model —
relies on Laravel's `confirmed` rule on the password field for validation.

```php
Password::make('password')
    ->creationRules(['required', 'confirmed', 'min:8'])
    ->withStrengthMeter(),

PasswordConfirmation::make('password_confirmation')
    ->confirms('password')
```

**Default overrides:**
- `showOnIndex = false`
- `showOnDetail = false`
- `fill()` is a no-op (companion fields never hydrate the model).
- `resolve()` always returns `null`.

**Specific methods:**
- `confirms(string $attribute): static` — Name the paired password attribute (default: `'password'`). Matches Laravel's `confirmed` rule convention.

**⭐ Martis extensions (UI, automatic):**
- Live match indicator — green tick / red cross the moment the two inputs match or diverge.
- Shared strength meter — if the paired `Password` field has `withStrengthMeter()`, the meter is shared.
- Synchronized visibility toggle — eye icons on both inputs mirror each other.

---

### Slug

**Type identifier:** `slug`
**Extends:** `Field`
**File:** `src/Fields/Slug.php`

URL-safe identifier auto-generated from a source attribute.

```php
Slug::make('slug')
    ->from('title')                                // source attribute
    ->separator('-')                               // default: '-'
    ->reserved(['admin', 'api', 'login'])          // rejected values
    ->lockAfter(fn ($post) => $post->is_published) // freeze after publish
```

**Specific methods:**
- `from(string $attribute): static` — Source attribute to slugify.
- `separator(string $separator): static` — Token separator (default: `'-'`).
- `reserved(array $reserved): static` — ⭐ **Martis extension.** Reject these exact values (system paths). Validation emits `slug_reserved` error; the `/slug-check` endpoint returns `{ reserved: true, suggestion }`.
- `lockAfter(Closure $condition): static` — ⭐ **Martis extension.** Freezes the slug on existing records once the condition holds. `Slug::fill()` becomes a no-op in that case — SEO protection.
- `badgeVariant(string $variant): static` — Read-only display badge variant. Accepts `'default'` (alias `'muted'`), `'accent'`, `'success'`, `'warning'`, `'danger'`, `'custom'`. Unknown values fall back to `'default'`.
- `badgeAccent(): static` — Sugar for `badgeVariant('accent')`. Reads cleanly when the slug IS the row identity (Permission name, Role name).
- `badgeColor(string $color): static` — Custom CSS colour for the badge. Accepts any browser-recognised colour (hex, `rgb()`, `hsl()`, `oklch()`, named). The frontend tints the background as a 14% mix with the surface so it stays subtle in both themes; the foreground uses the colour verbatim. Implies `badgeVariant('custom')`.
- `generate(string $value): string` — Unicode-safe slugify ("São Paulo" → "sao-paulo"). Exposed for tests/controllers.
- `isLockedFor(?Model $model): bool` — Query the lock condition directly.

**⭐ Martis extensions (UI, automatic):**
- **Live preview** — the React input regenerates the slug as the user types in the source field (i18n-aware transliteration).
- **Live collision detection** — debounced probe against
  `GET /martis/api/resources/{resource}/slug-check/{field}?value=…&id=…`.
  Response envelope:
  ```json
  {
    "data": {
      "available": false,
      "reserved": false,
      "suggestion": "existing-post-2"
    }
  }
  ```
  The UI renders a clickable suggestion when `suggestion` is non-null.

**Validation:** a closure rule verifies the submitted value is already in its slugified form (so the server rejects mismatched case / spaces) and that it is not in the `reserved` list.

---

### Timezone

**Type identifier:** `timezone`
**Extends:** `Field`
**File:** `src/Fields/Timezone.php`

Dropdown of every IANA timezone PHP knows about, grouped by continent.

```php
Timezone::make('timezone')->default('Europe/Lisbon')
```

**Specific methods:**
- `Timezone::groupedList(): array` — Static. Returns `['Europe' => ['Europe/Lisbon', …], 'America' => [...], …]`.

**⭐ Martis extensions (UI, automatic):**
- **Live current time** — every option in the dropdown shows the zone's current local time and UTC offset. Ticks once a minute while the dropdown is open.
- **Auto-detect button** — "Use my timezone" reads `Intl.DateTimeFormat().resolvedOptions().timeZone` and fills the field.
- **Grouped + filterable** — `Europe`, `America`, `Asia`, … optgroups. The filter matches label, value, or offset (`+01:00`).

---

### Icon

**Type identifier:** `icon`
**Extends:** `Field`
**File:** `src/Fields/Icon.php`

⭐ **Martis differential** — the Icon field offers three modes, one visual output (Phosphor icon).

```php
// Mode A — display-only (no DB column)
Icon::make('marker', 'rocket')->color('success')

// Mode B — stored in a DB column, with ⭐ visual picker
Icon::make('industry_icon')
    ->stored()
    ->palette(['rocket', 'buildings', 'briefcase', 'globe'])
    ->colorFrom('brand_color')
    ->size(20)

// Mode C — computed from the model
Icon::make('state')->icon(fn ($model) => $model->is_active ? 'check' : 'x')
```

**Factory:**
- `Icon::make(string $attribute, ?string $fixedIcon = null, ?string $label = null)` — when `$fixedIcon` is set, the field is display-only (Mode A) until `->stored()` flips it.

**Specific methods:**
- `stored(bool $value = true): static` — flip to Mode B. The icon name lives in the DB column named `$attribute`. Forms show a ⭐ visual picker.
- `color(string $color): static` — sets the icon tint. Accepts:
  - semantic tokens: `success`, `warning`, `danger`, `info`, `muted`, `accent`
  - CSS variables: `var(--my-color)`
  - arbitrary CSS: hex (`#ec4899`), rgb, named (`red`)
- `colorFrom(string $attribute): static` — read the color per-record from a sibling column on the same model (e.g. `brand_color`). Overrides `->color()` when that column has a value; falls back to `->color()` when empty.
- `map(array $map): static` — declarative value→icon(+color) mapping for stored fields. Accepts shortcut `['value' => 'iconName']` or full `['value' => ['icon' => '…', 'color' => '…']]`.
- `palette(array $palette): static` — whitelist for the picker. `fill()` silently drops values outside the palette — the DB never stores an icon the UI refuses to render.
- `size(int $size): static` — render size in pixels (clamped 8–64; default 16).
- `icon(Closure $resolver): static` — Mode C. Callback receives the model and returns `string` or `['icon' => '…', 'color' => '…']`.

**Behavioural notes:**
- Mode A defaults to `showOnForms = false`. `->stored()` re-enables form exposure.
- `fill()` is a no-op for Mode A / Mode C — only Mode B hydrates the model.
- Index rendering respects `size()` — put a small Icon at the start of `fieldsForIndex()` to get a visual marker on each row.

---

### Stack + Line

**Type identifier:** `stack` / `line`
**Extends:** `Field`
**File:** `src/Fields/Stack.php`, `src/Fields/Line.php`

Composite display field — renders a vertical stack of styled text lines as a single cell/detail slot. Ideal for compressing identity columns (name + email + company) into a single column without writing a custom component.

```php
Stack::make('identity', __('fields.identity'), [
    Line::make('name')->asHeading()->subtitleFrom('email'),
    Line::make('company')->asMuted(),
])->divider();
```

**Calling styles:**

```php
Stack::make('identity', [Line::make('name'), Line::make('email')]);            // attribute + lines
Stack::make('identity', 'Identity', [Line::make('name')]);                    // attribute + label + lines
```

**Line API — style variants:**

| Method | Class | When to use |
|--------|-------|-------------|
| `asHeading()` | `.martis-line-heading` | primary label (bold, slightly larger) |
| `asBase()` | `.martis-line-base` | default body copy |
| `asSmall()` | `.martis-line-small` | compact secondary text |
| `asMuted()` | `.martis-line-muted` | de-emphasised supporting text |
| `asCode()` ⭐ | `.martis-line-code` | monospace pill for slugs, IDs, tokens |
| `subtitleFrom($attrOrClosure)` ⭐ | — | emit a muted second line pulled from another attribute, without declaring a second Line |

**Stack API:**

| Method | Description |
|--------|-------------|
| `Stack::make($attribute, $lines)` | Create a Stack from an array of `Line` instances |
| `Stack::make($attribute, $label, $lines)` | Same, with explicit label |
| `divider(bool $enabled = true)` ⭐ | Insert a thin separator between Lines |
| `getLines(): array<Line>` | Inspect configured lines |
| `hasDivider(): bool` | Introspection helper |

**⭐ Martis differentials:**

1. **Works on the index**. Martis renders `Stack` as an index-table cell, perfect for identity columns.
2. **`->asCode()` variant** — monospace styling for slugs, hashes, tokens.
3. **`Line::subtitleFrom('attribute' | Closure)`** — one-line sugar to emit a muted secondary row without declaring a second `Line`. Accepts a Closure receiving the model for derived subtitles.
4. **`Stack::divider()`** — thin `--martis-border` separator between Lines; great for metadata listings.

**Payload shape (per row)**

The Stack emits `{ __martisStack: true, entries: [{ text, variant, subtitle }], divider }`. The frontend `StackField` component detects the wrapper and renders each entry with the appropriate `.martis-line-*` class. Custom themes restyle every Line in the package by overriding the CSS variables used inside `.martis-line-heading/base/small/muted/code`.

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
use Martis\Enums\CurrencyCode;
use Martis\Enums\CurrencyDisplayMode;

Currency::make('price')
    ->currency(CurrencyCode::EUR)
    ->asMinorUnits()                                  // stored as cents
    ->displayMode(CurrencyDisplayMode::BadgeText)
    ->badgeColor('green')
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `currency` | `currency(CurrencyCode $code): static` | `$this` | Set ISO 4217 currency code (typed enum). Auto-sets step. | `CurrencyCode::USD` |
| `locale` | `locale(string $locale): static` | `$this` | Override locale for formatting. | app locale |
| `asMinorUnits` | `asMinorUnits(): static` | `$this` | Treat stored value as minor units (cents). | `false` |
| `asMajorUnits` | `asMajorUnits(): static` | `$this` | Treat stored value as major units (dollars). | — |
| `displayMode` | `displayMode(CurrencyDisplayMode $mode): static` | `$this` | Set display mode (typed enum: `Text`, `Badge`, `BadgeText`). Martis extension. | `CurrencyDisplayMode::Text` |
| `showBadge` | `showBadge(): static` | `$this` | Shortcut for `displayMode(CurrencyDisplayMode::Badge)`. | — |
| `showText` | `showText(): static` | `$this` | Shortcut for `displayMode(CurrencyDisplayMode::Text)`. | — |
| `showBadgeText` | `showBadgeText(): static` | `$this` | Shortcut for `displayMode(CurrencyDisplayMode::BadgeText)`. | — |
| `badgeColor` | `badgeColor(string $color): static` | `$this` | Set badge color. Martis extension. | `null` |
| `getCurrencyCode` | `getCurrencyCode(): CurrencyCode` | `CurrencyCode` | Get the currency enum case. | — |
| `getLocale` | `getLocale(): string` | `string` | Get effective locale. | — |
| `isMinorUnits` | `isMinorUnits(): bool` | `bool` | Check if using minor units. | — |
| `getDisplayMode` | `getDisplayMode(): CurrencyDisplayMode` | `CurrencyDisplayMode` | Get display mode enum. | — |
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
```

For many-to-many relationships use [`BelongsToMany`](#belongstomany), [`MorphToMany`](#morphtomany), or [`Tag`](#tag) — `BelongsTo` itself is single-cardinality.

```php
// Inline create — show "+" button to create related record in a modal
use Martis\Enums\ModalSize;

BelongsTo::make('category_id', 'Category')
    ->relatedResource('categories')
    ->showCreateRelationButton()
    ->modalSize(ModalSize::Large)
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `titleAttribute` | `titleAttribute(string $attribute): static` | `$this` | Attribute on related model for display label. | `'name'` |
| `displayColumn` | `displayColumn(string $column): static` | `$this` | Alias for `titleAttribute()`. Sets which column appears in index/table cells. | `'name'` |
| `foreignKey` | `foreignKey(string $key): static` | `$this` | Override FK column name. | `{relationship}_id` |
| `relatedResource` | `relatedResource(string $uriKey): static` | `$this` | URI key of related resource for dropdown API. | `null` |
| `placeholder` | `placeholder(string\|\Closure $text): static` | `$this` | Custom placeholder shown when no value is selected. Closure receives `(?Request $r)` for per-request resolution. | translated `'Select {field}...'` |
| `relationSearchable` | `relationSearchable(bool $value = true): static` | `$this` | Enable/disable text search in dropdown. | `true` |
| `relatableQueryUsing` | `relatableQueryUsing(\Closure $closure): static` | `$this` | Per-field constraint on the picker query. Closure receives `(Request $request, Builder $query, BelongsTo $field)` and must return a `Builder`. Runs after the resource's static `relatableQuery()`. | `null` |
| `displayAsLink` | `displayAsLink(bool $value = true): static` | `$this` | Render as clickable link on index/detail. | `true` |
| `showCreateRelationButton` | `showCreateRelationButton(bool\|\Closure $callback = true): static` | `$this` | Show "+" button to create related record inline via modal. | `false` |
| `hideCreateRelationButton` | `hideCreateRelationButton(): static` | `$this` | Explicitly hide the inline create button. | — |
| `modalSize` | `modalSize(ModalSize $size): static` | `$this` | Set the inline create modal size. Pass any `Martis\Enums\ModalSize` case (`Small`, `Medium`, `Large`, `ExtraLarge`, `TwoExtraLarge` through `SevenExtraLarge`). | `ModalSize::TwoExtraLarge` |
| `iconColor` | `iconColor(string $color): static` | `$this` | Color for the resource icon in the inline create modal header. Any CSS color. | accent color |

#### Peek / Preview

The peek card appears when the user hovers the small preview icon next to a related record link.
Content is fetched lazily from the related resource's `fieldsForPreview()`.

The icon is the **only** trigger; hovering the record link itself does **not** open the peek card.

```php
// Peek is enabled by default
BelongsTo::make('author_id', 'Author')
    ->relatedResource('users')

// Disable peek entirely
BelongsTo::make('author_id')->noPeeking()

// Equivalent
BelongsTo::make('author_id')->peekable(false)
```

The peek card content is governed by the **related resource's `fieldsForPreview()` method**,
which defaults to the detail fields (`fieldsForDetail()`). Override it on the resource class
to control exactly which fields appear in the peek card:

```php
// UserResource.php (or any resource)
public function fieldsForPreview(Request $request): array
{
    return [
        Text::make('Name'),
        Text::make('Email'),
        Text::make('Role'),
    ];
}
```

Fields can also be shown/hidden from preview individually. By default a field visible on detail
is also visible in preview (`showOnPreview` falls back to `showOnDetail`).

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `peekable` | `peekable(bool $value = true): static` | `$this` | Enable/disable the peek preview icon. | `true` |
| `noPeeking` | `noPeeking(): static` | `$this` | Disable peek. Shorthand for `peekable(false)`. | — |

**Overrides:**
- `resolve()` returns `{id, title}` for the related record.
- `fill()` sets the foreign-key column on the parent model.

**Extra attributes:** `relationship`, `foreignKey`, `titleAttribute`, `relatedResource`, `relatedLabel`, `relationSearchable`, `displayAsLink`, `showCreateRelationButton`, `modalSize`, `peekable`, `placeholder`, `iconColor`

#### Toolbar controls (inherited)

All nine `hideXxx()` setters from `ControlsRelationshipToolbar` are inherited
— see [relationships.md § Toolbar hide flags](relationships.md#toolbar-hide-flags-cross-cutting).
On `BelongsTo` only the *Create* button (inline create) and the *View*
action surface are affected (per the trait scope matrix). Remember:
visible = authorized AND NOT hidden. Authorization is always the source of
truth; the hide flags can only hide, never force-visible.
*src/Fields/Concerns/ControlsRelationshipToolbar.php*

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

#### Toolbar controls (inherited)

Tag renders a chip/autocomplete UI of its own and **does not** use the shared
`RelationshipTableShell`, so the `ControlsRelationshipToolbar` trait is not
applied here. Use [`BelongsToMany`](#belongstomany) when you need the full
DataTable toolbar with hide flags, search, per-page, and soft-delete
dropdown.

---


### BelongsToMany

**Type identifier:** `belongs_to_many`
**Extends:** `Field`
**File:** `src/Fields/BelongsToMany.php`

Full many-to-many pivot relationship field. Renders as a DataTable panel on the detail page with attach/detach, pivot field editing, search, sort, and pagination. On the index page, shows a count badge.

> **Detail-only by default** — BelongsToMany is hidden from index and forms automatically. Use `->showOnIndex()` to display the count badge.

```php
// Minimal usage
BelongsToMany::make('Tags')
    ->relatedResource('tags')

// Full API
BelongsToMany::make('Tags', 'tags', TagResource::class)
    ->searchable()
    ->collapsable()
    ->collapsedByDefault()
    ->allowDuplicateRelations()
    ->showCreateRelationButton()
    ->modalSize('3xl')
    ->withSubtitles()
    ->dontReorderAttachables()
    ->relatableQueryUsing(fn ($request, $query) => $query->where('active', true))
    ->fields(fn () => [
        Text::make('notes', 'Notes')->nullable(),
        Date::make('expires_at', 'Expires At')->nullable(),
    ])
    ->actions(fn () => [
        // Pivot actions defined here
    ])
    ->perPage(15)
    ->canAttach(true)
    ->canDetach(true)
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `relatedResource` | `relatedResource(string $uriKey): static` | `$this` | URI key of related resource. | inferred from relationship |
| `titleAttribute` | `titleAttribute(string $attribute): static` | `$this` | Attribute on related model for display label. | `'name'` |
| `fields` | `fields(Closure(): list<Field>): static` | `$this` | Define pivot fields (stored on the pivot table). | `null` |
| `actions` | `actions(Closure(): list<mixed>): static` | `$this` | Define pivot actions for attached records. | `null` |
| `searchable` | `searchable(bool $value = true): static` | `$this` | Enable search in the attach modal. | `false` |
| `collapsable` | `collapsable(bool $value = true): static` | `$this` | Make the panel collapsable. | `false` |
| `collapsedByDefault` | `collapsedByDefault(bool $value = true): static` | `$this` | Start the panel collapsed. Implies `collapsable`. | `false` |
| `allowDuplicateRelations` | `allowDuplicateRelations(bool $value = true): static` | `$this` | Allow attaching the same record multiple times. | `false` |
| `showCreateRelationButton` | `showCreateRelationButton(bool\|Closure $callback = true): static` | `$this` | Show inline create button in attach modal. | `false` |
| `hideCreateRelationButton` | `hideCreateRelationButton(): static` | `$this` | Explicitly hide the inline create button. | — |
| `modalSize` | `modalSize(ModalSize\|string $size): static` | `$this` | Attach modal size (`sm`, `md`, `lg`, `xl`, `2xl`–`7xl`). | `'2xl'` |
| `relatableQueryUsing` | `relatableQueryUsing(Closure $fn): static` | `$this` | Filter the query for attachable records. | `null` |
| `dontReorderAttachables` | `dontReorderAttachables(bool $value = true): static` | `$this` | Disable auto-sort of attachables (keep DB order). | `false` |
| `withSubtitles` | `withSubtitles(bool $value = true): static` | `$this` | Show subtitles in the attach modal search results. | `false` |
| `perPage` | `perPage(int $perPage): static` | `$this` | Default per-page for the inline listing. | `10` |
| `canAttach` | `canAttach(bool $value = true): static` | `$this` | Control visibility of the Attach button. | `true` |
| `canDetach` | `canDetach(bool $value = true): static` | `$this` | Control visibility of the Detach button per row. | `true` |

#### API Endpoints

| Method | Path | Action |
|--------|------|--------|
| `GET` | `/api/resources/{resource}/{id}/belongs-to-many/{relationship}` | List attached records (paginated) |
| `GET` | `/api/resources/{resource}/{id}/belongs-to-many/{relationship}/attachable` | List records available to attach |
| `POST` | `/api/resources/{resource}/{id}/belongs-to-many/{relationship}/attach` | Attach a record (with optional pivot data) |
| `DELETE` | `/api/resources/{resource}/{id}/belongs-to-many/{relationship}/{relatedId}/detach` | Detach a record |
| `PUT` | `/api/resources/{resource}/{id}/belongs-to-many/{relationship}/{relatedId}/pivot` | Update pivot fields |

#### Authorization

Override `authorizedToAttach()` or `authorizedToDetach()` on your Resource to restrict operations:

```php
public function authorizedToAttach(Request $request, Model $related): bool
{
    return $request->user()?->isAdmin() ?? false;
}

public function authorizedToDetach(Request $request, Model $related): bool
{
    return $request->user()?->isAdmin() ?? false;
}
```

If these methods are not defined, the field falls back to `authorizedToUpdate()`.

**Overrides:**
- `resolve()` returns `null` on the detail page (data is loaded via API endpoints), or the count (integer) when shown on index.
- `fill()` is a no-op — attach/detach is handled exclusively via the dedicated API endpoints.

**Extra attributes:** `relationship`, `relatedResource`, `titleAttribute`, `searchable`, `collapsable`, `collapsedByDefault`, `allowDuplicateRelations`, `showCreateRelationButton`, `modalSize`, `withSubtitles`, `dontReorderAttachables`, `pivotFields`, `belongsToManyMeta`

#### Toolbar controls (inherited)

All nine `hideXxx()` setters from `ControlsRelationshipToolbar` are inherited
— see [relationships.md § Toolbar hide flags](relationships.md#toolbar-hide-flags-cross-cutting).
On many-to-many fields, `hideCreateButton()` hides the *Attach* button and
`hideDeleteAction()` hides the *Detach* variant. Remember:
visible = authorized AND NOT hidden. Authorization is always the source of
truth; the hide flags can only hide, never force-visible.
*src/Fields/Concerns/ControlsRelationshipToolbar.php*

---

### HasOne

**Type identifier:** `has_one`
**Extends:** `Field`
**File:** `src/Fields/HasOne.php`

One-to-one relationship. Renders a single related record panel on the detail
page with optional Create / Edit / Delete controls. Detail-only by default —
hidden from index and forms.

```php
use Martis\Fields\HasOne;

HasOne::make('Profile', 'profile', ProfileResource::class)
    ->canCreate()
    ->canUpdate()
    ->canDelete(false)
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `relatedResource` | `relatedResource(string $uriKey): static` | `$this` | URI key of the related resource. | inferred from relationship |
| `canCreate` | `canCreate(bool $value = true): static` | `$this` | Show/hide the Create button when no related record exists. | `true` |
| `canUpdate` | `canUpdate(bool $value = true): static` | `$this` | Show/hide the Edit button for the existing related record. | `true` |
| `canDelete` | `canDelete(bool $value = true): static` | `$this` | Show/hide the Delete button for the existing related record. | `true` |

Static factory `HasOne::ofMany($name, $relationship, $resourceClass)`
promotes a `hasMany()->latestOfMany()` relation into a
[`HasOneOfMany`](#hasoneofmany) field.
*src/Fields/HasOne.php*

#### Toolbar controls (inherited)

All nine `hideXxx()` setters from `ControlsRelationshipToolbar` are inherited
— see [relationships.md § Toolbar hide flags](relationships.md#toolbar-hide-flags-cross-cutting).
`HasOne` renders no toolbar, so only the action hide flags
(`hideViewAction` / `hideEditAction` / `hideDeleteAction`) have a visible
effect per the trait scope matrix. Remember:
visible = authorized AND NOT hidden. Authorization is always the source of
truth; the hide flags can only hide, never force-visible.

See [relationships.md — HasOne](relationships.md#hasone) for the full guide.

---

### HasOneOfMany

**Type identifier:** `has_one_of_many`
**Extends:** `HasOne`
**File:** `src/Fields/HasOneOfMany.php`

Promotes a `hasMany()->latestOfMany()` (or `->ofMany(col, aggregate)`)
relationship so the admin displays the latest / oldest record as if it were
a plain `HasOne`. Ships an optional metric tile via `aggregateVia()` and a
"latest of N" pill next to the panel heading.

```php
use Martis\Enums\AggregateFunction;
use Martis\Fields\HasOneOfMany;

HasOneOfMany::make('Latest Invoice', 'latestInvoice', InvoiceResource::class)
    ->latestByTimestamp('paid_at')
    ->aggregateVia(AggregateFunction::Sum, 'amount');
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| *All `HasOne` setters* | — | `$this` | Inherited. | — |
| `latestByTimestamp` | `latestByTimestamp(string $column = 'created_at'): static` | `$this` | Orders the underlying relation by the timestamp column descending before picking the first row. | `'created_at'` |
| `oldestByTimestamp` | `oldestByTimestamp(string $column = 'created_at'): static` | `$this` | Ascending counterpart of `latestByTimestamp()`. | `'created_at'` |
| `aggregateVia` | `aggregateVia(AggregateFunction $function, string $column = '*'): static` | `$this` | Emits a metric tile computed across the full collection (count/sum/min/max/avg). | disabled |

*src/Fields/HasOneOfMany.php*

#### Toolbar controls (inherited)

All nine `hideXxx()` setters from `ControlsRelationshipToolbar` are inherited
(via `HasOne`) — see [relationships.md § Toolbar hide flags](relationships.md#toolbar-hide-flags-cross-cutting).
Only the action hide flags (`hideViewAction` / `hideEditAction` /
`hideDeleteAction`) have a visible effect on this single-record panel.
Remember: visible = authorized AND NOT hidden. Authorization is always the
source of truth; the hide flags can only hide, never force-visible.

See [relationships.md — HasOneOfMany](relationships.md#hasoneofmany) for the
full guide.

---

### HasOneThrough

**Type identifier:** `has_one_through`
**Extends:** `HasOne`
**File:** `src/Fields/HasOneThrough.php`

Shows a single distant record reached through an intermediate model
(`hasOneThrough`). Read-only by default: `canCreate` / `canUpdate` /
`canDelete` start as `false`.

```php
use Martis\Fields\HasOneThrough;

HasOneThrough::make('Account Manager', 'accountManager', TeamMemberResource::class)
    ->throughBreadcrumb();
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| *All `HasOne` setters* | — | `$this` | Inherited. `canCreate` / `canUpdate` / `canDelete` default to `false`. | — |
| `throughBreadcrumb` | `throughBreadcrumb(bool $enabled = true, ?string $text = null): static` | `$this` | Adds a "through" hint next to the section heading. Pass a custom `$text` to override the default label. | `false` |

*src/Fields/HasOneThrough.php*

#### Toolbar controls (inherited)

All nine `hideXxx()` setters from `ControlsRelationshipToolbar` are inherited
— see [relationships.md § Toolbar hide flags](relationships.md#toolbar-hide-flags-cross-cutting).
Because Through fields are read-only by default, the `canCreate` /
`canUpdate` / `canDelete` defaults already hide those buttons; the
`hideXxx()` setters are mostly redundant but available for symmetry.
Remember: visible = authorized AND NOT hidden. Authorization is always the
source of truth; the hide flags can only hide, never force-visible.

See [relationships.md — HasOneThrough](relationships.md#hasonethrough) for
the full guide.

---

### HasMany

**Type identifier:** `has_many`
**Extends:** `Field`
**File:** `src/Fields/HasMany.php`

One-to-many relationship. Renders an inline DataTable panel on the detail
page with full inline CRUD (create, edit, delete), search, sort, per-page,
and pagination via `RelationshipTableShell`. Detail-only by default — use
`->showOnIndex()` to display a count badge on index.

```php
use Martis\Fields\HasMany;
use Martis\Enums\HasManyIndexDisplay;
use Martis\Enums\HasManyRedirectMode;

HasMany::make('Comments', 'comments')
    ->relatedResource('comments')
    ->collapsable()
    ->collapsedByDefault()
    ->perPage(15)
    ->showRelationCount()
    ->redirectAfterSave(HasManyRedirectMode::Parent);
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `relatedResource` | `relatedResource(string $uriKey): static` | `$this` | URI key of the related resource. | inferred from relationship |
| `perPage` | `perPage(int $perPage): static` | `$this` | Default per-page for the inline listing. | `10` |
| `perPageOptions` | `perPageOptions(array $options): static` | `$this` | Custom per-page selector options. | resolved from related resource / `[5,10,25,50]` |
| `canCreate` | `canCreate(bool $value = true): static` | `$this` | Show/hide the Create button. | `true` |
| `canUpdate` | `canUpdate(bool $value = true): static` | `$this` | Show/hide the Edit action per row. | `true` |
| `canDelete` | `canDelete(bool $value = true): static` | `$this` | Show/hide the Delete action per row. | `true` |
| `relationSearchable` | `relationSearchable(bool $value = true): static` | `$this` | Enable the search input in the panel toolbar. | `false` |
| `indexDisplay` | `indexDisplay(HasManyIndexDisplay $mode): static` | `$this` | Configure how the field renders when shown on the index page. | — |
| `showRelationIcon` | `showRelationIcon(bool $value = true): static` | `$this` | Show the related-resource icon in the panel heading. | `true` |
| `showRelationCount` | `showRelationCount(bool $value = true): static` | `$this` | Show the total count badge in the panel heading. | `true` |
| `badgeColor` | `badgeColor(string $color): static` | `$this` | Override the count badge colour for index display. | — |
| `badgeIcon` | `badgeIcon(string $icon): static` | `$this` | Override the panel icon. | — |
| `redirectAfterSave` | `redirectAfterSave(HasManyRedirectMode $mode): static` | `$this` | Where to redirect after saving a related record. | — |
| `collapsable` | `collapsable(bool $value = true): static` | `$this` | Make the panel collapsable. | `false` |
| `collapsedByDefault` | `collapsedByDefault(bool $value = true): static` | `$this` | Start collapsed. Implies `collapsable`. | `false` |

*src/Fields/HasMany.php*

#### Toolbar controls (inherited)

All nine `hideXxx()` setters from `ControlsRelationshipToolbar` are inherited
— see [relationships.md § Toolbar hide flags](relationships.md#toolbar-hide-flags-cross-cutting).
On `HasMany` the full surface is affected (search / create / per-page /
soft-delete dropdown + view / edit / delete / restore / force-delete).
Remember: visible = authorized AND NOT hidden. Authorization is always the
source of truth; the hide flags can only hide, never force-visible.

See [relationships.md — HasMany](relationships.md#hasmany) for the full
guide and [Relationship panel anatomy](relationships.md#relationship-panel-anatomy)
for the shared shell layout.

---

### HasManyThrough

**Type identifier:** `has_many_through`
**Extends:** `HasMany`
**File:** `src/Fields/HasManyThrough.php`

Inline DataTable of many records reached through an intermediate
(`hasManyThrough`). Read-only by default: `canCreate` / `canUpdate` /
`canDelete` start as `false`.

```php
use Martis\Fields\HasManyThrough;

HasManyThrough::make('Managed Projects', 'managedProjects', ProjectResource::class)
    ->throughBreadcrumb()
    ->countBadge();
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| *All `HasMany` setters* | — | `$this` | Inherited. `canCreate` / `canUpdate` / `canDelete` default to `false`. | — |
| `throughBreadcrumb` | `throughBreadcrumb(bool $enabled = true, ?string $text = null): static` | `$this` | Adds a "through" hint next to the section heading. | `false` |
| `countBadge` | `countBadge(bool $enabled = true): static` | `$this` | Renders a count pill on the parent resource's index cell. | `true` |

*src/Fields/HasManyThrough.php*

#### Toolbar controls (inherited)

All nine `hideXxx()` setters from `ControlsRelationshipToolbar` are inherited
(via `HasMany`) — see [relationships.md § Toolbar hide flags](relationships.md#toolbar-hide-flags-cross-cutting).
Since the field is read-only by default, Edit / Delete / Restore /
Force-delete are already hidden via `canUpdate(false)` / `canDelete(false)`.
Remember: visible = authorized AND NOT hidden. Authorization is always the
source of truth; the hide flags can only hide, never force-visible.

See [relationships.md — HasManyThrough](relationships.md#hasmanythrough) for
the full guide.

---

### MorphOne

**Type identifier:** `morph_one`
**Extends:** `Field`
**File:** `src/Fields/MorphOne.php`

Polymorphic one-to-one relationship (`morphOne`). Same UI as `HasOne` but
for polymorphic relations. Detail-only by default.

```php
use Martis\Fields\MorphOne;

MorphOne::make('Thumbnail', 'thumbnail', ThumbnailResource::class)
    ->canCreate()
    ->canUpdate();
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `relatedResource` | `relatedResource(string $uriKey): static` | `$this` | URI key of the related resource. | inferred from relationship |
| `canCreate` | `canCreate(bool $value = true): static` | `$this` | Show/hide the Create button. | `true` |
| `canUpdate` | `canUpdate(bool $value = true): static` | `$this` | Show/hide the Edit button. | `true` |
| `canDelete` | `canDelete(bool $value = true): static` | `$this` | Show/hide the Delete button. | `true` |

*src/Fields/MorphOne.php*

#### Toolbar controls (inherited)

All nine `hideXxx()` setters from `ControlsRelationshipToolbar` are inherited
— see [relationships.md § Toolbar hide flags](relationships.md#toolbar-hide-flags-cross-cutting).
Same scope as `HasOne`: only the action hide flags have a visible effect.
Remember: visible = authorized AND NOT hidden. Authorization is always the
source of truth; the hide flags can only hide, never force-visible.

See [relationships.md — MorphOne](relationships.md#morphone) for the full
guide.

---

### MorphOneOfMany

**Type identifier:** `morph_one_of_many`
**Extends:** `MorphOne`
**File:** `src/Fields/MorphOneOfMany.php`

Polymorphic counterpart of `HasOneOfMany`. Promotes a
`morphMany()->latestOfMany()` (or `->ofMany(col, aggregate)`) relationship.

```php
use Martis\Enums\AggregateFunction;
use Martis\Fields\MorphOneOfMany;

MorphOneOfMany::make('Latest Note', 'latestNote', NoteResource::class)
    ->latestByTimestamp()
    ->aggregateVia(AggregateFunction::Count, '*');
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| *All `MorphOne` setters* | — | `$this` | Inherited. | — |
| `latestByTimestamp` | `latestByTimestamp(string $column = 'created_at'): static` | `$this` | Orders by the timestamp descending before picking the first row. | `'created_at'` |
| `oldestByTimestamp` | `oldestByTimestamp(string $column = 'created_at'): static` | `$this` | Ascending counterpart. | `'created_at'` |
| `aggregateVia` | `aggregateVia(AggregateFunction $function, string $column = '*'): static` | `$this` | Emits a metric tile computed across the full collection. | disabled |

*src/Fields/MorphOneOfMany.php*

#### Toolbar controls (inherited)

All nine `hideXxx()` setters from `ControlsRelationshipToolbar` are inherited
(via `MorphOne`) — see [relationships.md § Toolbar hide flags](relationships.md#toolbar-hide-flags-cross-cutting).
Only the action hide flags have a visible effect on this single-record
panel. Remember: visible = authorized AND NOT hidden. Authorization is
always the source of truth; the hide flags can only hide, never
force-visible.

See [relationships.md — MorphOneOfMany](relationships.md#morphoneofmany) for
the full guide.

---

### MorphMany

**Type identifier:** `morph_many`
**Extends:** `Field`
**File:** `src/Fields/MorphMany.php`

Polymorphic one-to-many relationship (`morphMany`). Same DataTable UI as
`HasMany` — full inline CRUD, search, sort, per-page, pagination — via
`RelationshipTableShell`. Detail-only by default.

```php
use Martis\Fields\MorphMany;

MorphMany::make('Comments', 'comments', CommentResource::class)
    ->collapsable()
    ->collapsedByDefault()
    ->perPage(10)
    ->canCreate(false);
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `relatedResource` | `relatedResource(string $uriKey): static` | `$this` | URI key of the related resource. | inferred from relationship |
| `perPage` | `perPage(int $perPage): static` | `$this` | Default per-page for the inline listing. | `10` |
| `perPageOptions` | `perPageOptions(array $options): static` | `$this` | Custom per-page selector options. | resolved from related resource / `[5,10,25,50]` |
| `canCreate` | `canCreate(bool $value = true): static` | `$this` | Show/hide the Create button. | `true` |
| `canUpdate` | `canUpdate(bool $value = true): static` | `$this` | Show/hide the Edit action per row. | `true` |
| `canDelete` | `canDelete(bool $value = true): static` | `$this` | Show/hide the Delete action per row. | `true` |
| `relationSearchable` | `relationSearchable(bool $value = true): static` | `$this` | Enable the search input in the panel toolbar. | `false` |
| `indexDisplay` | `indexDisplay(HasManyIndexDisplay $mode): static` | `$this` | Configure how the field renders when shown on the index page. | — |
| `showRelationIcon` | `showRelationIcon(bool $value = true): static` | `$this` | Show the related-resource icon in the panel heading. | `true` |
| `showRelationCount` | `showRelationCount(bool $value = true): static` | `$this` | Show the total count badge in the panel heading. | `true` |
| `badgeColor` | `badgeColor(string $color): static` | `$this` | Override the count badge colour. | — |
| `badgeIcon` | `badgeIcon(string $icon): static` | `$this` | Override the panel icon. | — |
| `redirectAfterSave` | `redirectAfterSave(HasManyRedirectMode $mode): static` | `$this` | Where to redirect after saving a related record. | — |

*src/Fields/MorphMany.php*

> `MorphMany` does not expose `collapsable()` / `collapsedByDefault()`
> setters of its own — see src for details.

#### Toolbar controls (inherited)

All nine `hideXxx()` setters from `ControlsRelationshipToolbar` are inherited
— see [relationships.md § Toolbar hide flags](relationships.md#toolbar-hide-flags-cross-cutting).
Full scope applies: search / create / per-page / soft-delete dropdown and
all five row actions. Remember: visible = authorized AND NOT hidden.
Authorization is always the source of truth; the hide flags can only hide,
never force-visible.

See [relationships.md — MorphMany](relationships.md#morphmany) for the full
guide and [Relationship panel anatomy](relationships.md#relationship-panel-anatomy)
for the shared shell layout.

---

### MorphTo

**Type identifier:** `morph_to`
**Extends:** `Field`
**File:** `src/Fields/MorphTo.php`

Polymorphic many-to-one relationship — a record can belong to one of several different model types via a single relationship. The frontend renders a two-step picker: first a type dropdown (the resource families allowed by `types()`), then a record search filtered to the chosen type.

```php
use Martis\Enums\ModalSize;
use Martis\Fields\MorphTo;

MorphTo::make('commentable', 'Commentable')
    ->types([PostResource::class, UserResource::class])
    ->titleAttribute('name')
    ->showCreateRelationButton()
    ->modalSize(ModalSize::Large)
    ->nullable();
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `types` | `types(array $resourceClasses): static` | `$this` | Allowed resource families for this polymorphic relationship. Each entry is a `Resource` class name; the model class is derived via `newModel()`. | — |
| `titleAttribute` | `titleAttribute(string $attr): static` | `$this` | Attribute used for the display label in the picker and on detail rows. | `'name'` |
| `relationSearchable` | `relationSearchable(bool $value = true): static` | `$this` | Enable / disable text search inside the record dropdown. | `true` |
| `showCreateRelationButton` | `showCreateRelationButton(bool\|\Closure $callback = true): static` | `$this` | Show the inline "+" button that creates a related record per type. | `false` |
| `hideCreateRelationButton` | `hideCreateRelationButton(): static` | `$this` | Explicitly hide the inline create button. | — |
| `modalSize` | `modalSize(ModalSize $size): static` | `$this` | Modal size for the inline create flow. Pass any `Martis\Enums\ModalSize` case (`Small` through `SevenExtraLarge`). | `ModalSize::TwoExtraLarge` |
| `nullable` | `nullable(): static` | `$this` | Make the relationship optional on forms. | `false` |

**Resolve format**

```json
{
  "type": "App\\Models\\Post",
  "id": 42,
  "title": "Post Title",
  "resourceType": "posts"
}
```

**Fill format**

The frontend submits:

```json
{ "resourceType": "posts", "id": 42 }
```

The backend resolves the model class from the resource URI key and writes both `commentable_type` and `commentable_id` columns on the parent.

**Inline create**

Inline create is per-type — the create button appears only after the operator picks a type. Nesting is limited to one level (no inline create inside an inline create). The related resource's `fieldsForInlineCreate()` controls which fields show; falls back to `fieldsForCreate()`.

**Toolbar controls (inherited)**

All nine `hideXxx()` setters from `ControlsRelationshipToolbar` are inherited — see [relationships.md § Toolbar hide flags](relationships.md#toolbar-hide-flags-cross-cutting). On `MorphTo` only the *Create* button (inline create) and the *View* action surface are affected (per the trait scope matrix). Remember: visible = authorized AND NOT hidden. Authorization is always the source of truth; the hide flags can only hide, never force-visible.
*src/Fields/Concerns/ControlsRelationshipToolbar.php*

---

### MorphToMany

**Type identifier:** `morph_to_many`
**Extends:** `Field`
**File:** `src/Fields/MorphToMany.php`

Polymorphic many-to-many relationship (`morphToMany`). Same pivot UI as
`BelongsToMany` — DataTable, attach/detach, pivot fields, search, sort,
per-page, pagination — via `RelationshipTableShell`. Detail-only by default.

```php
use Martis\Fields\MorphToMany;

MorphToMany::make('Tags', 'tags', TagResource::class)
    ->titleAttribute('name')
    ->searchable()
    ->collapsable()
    ->fields(fn () => [
        Text::make('notes', 'Notes')->nullable(),
    ]);
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `relatedResource` | `relatedResource(string $uriKey): static` | `$this` | URI key of the related resource. | inferred from relationship |
| `titleAttribute` | `titleAttribute(string $attribute): static` | `$this` | Display attribute on the related model. | `'name'` |
| `fields` | `fields(Closure $closure): static` | `$this` | Define pivot fields (stored on the morph-pivot table). | — |
| `actions` | `actions(Closure $closure): static` | `$this` | Define pivot actions for attached records. | — |
| `searchable` | `searchable(bool $value = true): static` | `$this` | Enable search in the attach modal. | `false` |
| `collapsable` | `collapsable(bool $value = true): static` | `$this` | Make the panel collapsable. | `false` |
| `collapsedByDefault` | `collapsedByDefault(bool $value = true): static` | `$this` | Start collapsed. Implies `collapsable`. | `false` |
| `allowDuplicateRelations` | `allowDuplicateRelations(bool $value = true): static` | `$this` | Allow attaching the same record multiple times. | `false` |
| `showCreateRelationButton` | `showCreateRelationButton(bool\|Closure $callback = true): static` | `$this` | Show the inline create button in the attach modal. | `false` |
| `hideCreateRelationButton` | `hideCreateRelationButton(): static` | `$this` | Explicitly hide the inline create button. | — |
| `modalSize` | `modalSize(ModalSize $size, ?string $height = null): static` | `$this` | Attach modal size / optional height. | `'2xl'` |
| `relatableQueryUsing` | `relatableQueryUsing(Closure $closure): static` | `$this` | Filter the attachable record list. | — |
| `dontReorderAttachables` | `dontReorderAttachables(bool $value = true): static` | `$this` | Keep DB order in the attachable list. | `false` |
| `withSubtitles` | `withSubtitles(bool $value = true): static` | `$this` | Show subtitles in search results. | `false` |
| `subtitleAttribute` | `subtitleAttribute(string $attribute): static` | `$this` | Column used as the subtitle. | — |
| `perPage` | `perPage(int $perPage): static` | `$this` | Default per-page for the inline listing. | `10` |
| `perPageOptions` | `perPageOptions(array $options): static` | `$this` | Custom per-page selector options. | resolved from related resource / `[5,10,25,50]` |
| `canAttach` | `canAttach(bool $value = true): static` | `$this` | Control visibility of the Attach button. | `true` |
| `canDetach` | `canDetach(bool $value = true): static` | `$this` | Control visibility of the Detach button per row. | `true` |

*src/Fields/MorphToMany.php*

#### Toolbar controls (inherited)

All nine `hideXxx()` setters from `ControlsRelationshipToolbar` are inherited
— see [relationships.md § Toolbar hide flags](relationships.md#toolbar-hide-flags-cross-cutting).
Same scope as `BelongsToMany`: `hideCreateButton()` hides the *Attach*
button and `hideDeleteAction()` hides the *Detach* variant. Remember:
visible = authorized AND NOT hidden. Authorization is always the source of
truth; the hide flags can only hide, never force-visible.

See [relationships.md — MorphToMany](relationships.md#morphtomany) for the
full guide and [Relationship panel anatomy](relationships.md#relationship-panel-anatomy)
for the shared shell layout.

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
use Martis\Enums\CodeLanguage;

Code::make('config', 'Configuration')
    ->language(CodeLanguage::Yaml)
    ->json()
    ->rules(['json'])
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `json` | `json(): static` | `$this` | Treat as JSON: pretty-print on resolve, decode on fill. | `false` |
| `language` | `language(CodeLanguage $language): static` | `$this` | Set syntax-highlighting language (typed enum). | `CodeLanguage::Javascript` |
| `isJson` | `isJson(): bool` | `bool` | Check if JSON mode. | — |
| `getLanguage` | `getLanguage(): CodeLanguage` | `CodeLanguage` | Get the configured language enum case. | — |

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
| `map` | `map(array\|Closure $map): static` | `$this` | Value → badge type. Closure resolves once at schema build. | `[]` |
| `labels` | `labels(array\|Closure $labels): static` | `$this` | Value → translated display label. Closure form supported. | `[]` |
| `types` | `types(array\|Closure $types): static` | `$this` | Override badge type definitions (replaces defaults). | `info, success, warning, danger` |
| `addTypes` | `addTypes(array $types): static` | `$this` | Merge extra badge types onto the current set. | — |
| `withIcons` | `withIcons(): static` | `$this` | Enable icon rendering in badges. | `false` |
| `icons` | `icons(array\|Closure $icons): static` | `$this` | Badge type → icon name (also enables icons). | `[]` |
| `resolveBadgeUsing` ⭐ | `resolveBadgeUsing(Closure $fn): static` | `$this` | Per-row override — closure receives `(value, model)` and returns `['type', 'label', 'icon']`. | — |
| `getMap` | `getMap(): array` | `array` | Get resolved value-to-type map. | — |
| `getTypes` | `getTypes(): array` | `array` | Get resolved type definitions. | — |
| `hasIcons` | `hasIcons(): bool` | `bool` | Check if icons enabled. | — |
| `getIcons` | `getIcons(): array` | `array` | Get resolved type-to-icon map. | — |

**Default badge types:** `info` (blue), `success` (green), `warning` (yellow), `danger` (red)
**Extra attributes:** `map`, `labels`, `types`, `withIcons`, `icons`

#### ⭐ Dynamic maps & per-row resolution (Martis differentials)

All static setters (`map`, `labels`, `types`, `icons`) accept a Closure that's resolved **once** when the schema is serialised — ideal for enum-backed maps and config-driven palettes:

```php
Badge::make('status')
    ->map(fn () => StatusEnum::badgeMap())
    ->labels(fn () => StatusEnum::labels());
```

For row-specific decisions, use `resolveBadgeUsing()`. The closure runs during per-row serialisation and its return value (any subset of `type`, `label`, `icon`) is shipped verbatim to the frontend. Missing keys fall back to the static `map`/`labels`/`icons` lookup:

```php
Badge::make('status')
    ->map(['active' => 'success', 'paused' => 'warning'])
    ->labels(['active' => 'Active', 'paused' => 'Paused'])
    ->resolveBadgeUsing(function (?string $value, Model $model) {
        if ($model->is_vip && $value === 'active') {
            return ['type' => 'vip-gold', 'label' => 'VIP ⭐', 'icon' => 'crown'];
        }
        return []; // fall back to the static map/labels for everyone else
    });
```

> [!important] Badge is display-only — use `Select` in forms
> `Badge` is intentionally filtered out of create/update contexts (`showOnCreation = showOnUpdate = false` by default). It is meant for index/detail display only. If you want the user to **pick** a value that renders as a badge afterwards, use `Select` in `fieldsForCreate`/`fieldsForUpdate` and keep `Badge` in `fieldsForIndex`/`fieldsForDetail`.
>
> **Wrong — field disappears from the edit drawer:**
> ```php
> public function fields(Request $request): array
> {
>     return [
>         Badge::make('status')->map([
>             'active' => 'success',
>             'inactive' => 'warning',
>         ]),
>     ];
> }
> ```
>
> **Right — editable input in forms, badge in index/detail:**
> ```php
> public function fieldsForIndex(Request $request): array
> {
>     return [
>         Badge::make('status')->map([
>             'active' => 'success',
>             'inactive' => 'warning',
>         ]),
>     ];
> }
>
> public function fieldsForCreate(Request $request): array
> {
>     return [
>         Select::make('status')->options([
>             'active'   => 'Active',
>             'inactive' => 'Inactive',
>         ])->required(),
>     ];
> }
>
> public function fieldsForUpdate(Request $request): array
> {
>     return $this->fieldsForCreate($request);
> }
> ```
>
> Calling `->showOnForms()` on a Badge forces it into forms as read-only — useful to *show* the current value while another field edits it, but never as an input.

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
    ->chartWidth(120)
    ->color('#22c55e')
```

| Method | Signature | Returns | Description | Default |
|--------|-----------|---------|-------------|---------|
| `data` | `data(array\|callable $data): static` | `$this` | Set chart data (array of numbers or callable receiving model). | `null` |
| `asBarChart` | `asBarChart(): static` | `$this` | Render as bar chart. | — |
| `asLineChart` | `asLineChart(): static` | `$this` | Render as line chart. | `'line'` |
| `height` | `height(int $px): static` | `$this` | Chart height in pixels. | `30` |
| `chartWidth` | `chartWidth(int $px): static` | `$this` | SVG canvas width in pixels. Renamed from `width()` — the base `Field::width(string)` now controls the index column width. | `null` |
| `color` | `color(string $color): static` | `$this` | Chart line/bar color (CSS color). | `'#6366f1'` |
| `getChartType` | `getChartType(): string` | `string` | Get chart type. | — |
| `getChartHeight` | `getChartHeight(): int` | `int` | Get height. | — |
| `getChartWidth` | `getChartWidth(): ?int` | `?int` | Get width. | — |
| `getChartColor` | `getChartColor(): string` | `string` | Get color. | — |

**Overrides:** `resolve()` returns data array (invokes callable if set, falls back to model attribute); `fill()` is a no-op.
**Extra attributes:** `chartType`, `chartHeight`, `chartWidth`, `chartColor`

---

### Repeater

**File:** `src/Fields/Repeater.php` + `src/Fields/Repeatable.php`

Repeatable row widget backed by JSON, HasMany or ⭐ polymorphic (single child table
with a `type` discriminator). See the dedicated [Repeater guide](repeater.md) for the
full API. Highlights:

| Method | Signature | Description |
| --- | --- | --- |
| `repeatables` | `repeatables(array<Repeatable>)` | Register the row types available in the Add menu |
| `asJson` | `asJson(): static` | Persist on a JSON-cast parent attribute |
| `asHasMany` | `asHasMany(): static` | Persist via a child table with 3-way upsert |
| `asPolymorphic` ⭐ | `asPolymorphic(string $type = 'type', string $payload = 'payload')` | One child table for every row type |
| `uniqueField` | `uniqueField(string)` | Column used to identify rows across saves |
| `confirmRemoval` | `confirmRemoval(bool = true)` | Open a confirmation modal on remove |
| `minRows` / `maxRows` ⭐ | `minRows(int)` / `maxRows(int)` | Cardinality limits enforced in the UI |
| `collapsible` ⭐ | `collapsible(bool = true)` | Add collapse chevron to every row |
| `collapsedByDefault` ⭐ | `collapsedByDefault(bool = true)` | Start collapsed |
| `reorderable` ⭐ | `reorderable(bool = true, ?string $column = null)` | Drag-and-drop reorder |
| `dependsOn` ⭐ | `dependsOn(array<string>)` | Expose parent attributes to every inner field |
| `rowTemplates` ⭐ | `rowTemplates(array)` | Pre-filled rows available in the Add menu |

**Repeatable** header decorations (⭐): `icon`, `color`, `title` (template or closure),
`badgeCount`. Row-level UX extras: duplicate button per row, bulk-paste modal that
parses TSV/CSV/JSON.

---

## Utility Classes

### DeferredRelationSync

**File:** `src/Fields/DeferredRelationSync.php`

Static registry for deferred many-to-many relationship syncs. Used by `Tag` and similar fields where the pivot rows can only be written after the parent model has been saved (so the parent's primary key is available).

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

---

## Resource Replication

Martis supports resource replication. When a user clicks "Replicate" on a detail page, they are redirected to the create form with pre-filled field values from the source record. The record is **not** saved until the user submits the form.

### How It Works

1. User clicks "Replicate" on `ResourceDetail`
2. Frontend navigates to `/resources/{resource}/create?fromResourceId={id}`
3. `ResourceCreate` fetches pre-fill data from `GET /api/resources/{resource}/{id}/replicate`
4. Form is pre-filled with source record values (File fields excluded)
5. User can modify values and submit to create the new record

### API Endpoint

```
GET /api/resources/{resource}/{id}/replicate
```

**Response:**
```json
{
  "values": { "name": "Copy of ...", "description": "..." },
  "fromResourceId": 42
}
```

### Customization

Override `fieldsForCreate()` on your resource to control which fields appear in the replicate form. File fields are automatically excluded from replication.

---

## Inline Create

BelongsTo fields can display a "+" button that opens a modal for creating a related record inline, without leaving the current form. This is controlled by `showCreateRelationButton()` on the BelongsTo field.

### How It Works

1. BelongsTo field renders with a "+" button when `showCreateRelationButton()` is enabled
2. Clicking "+" opens a modal with the related resource's inline create fields
3. The related resource defines `fieldsForInlineCreate()` for a reduced field set
4. On submit, the new record is created and automatically selected in the BelongsTo dropdown
5. Nesting is limited to 1 level (no inline create inside an inline create)

### API Endpoints

```
GET  /api/resources/{resource}/inline-create-schema
POST /api/resources/{resource}/inline-create
```

**Schema response:**
```json
{
  "fields": [
    { "attribute": "name", "label": "Name", "type": "text" }
  ]
}
```

**Store request/response:**
```json
// Request
{ "name": "New Category" }

// Response
{ "id": 5, "title": "New Category" }
```

### Resource Configuration

```php
// In your resource class
public function fieldsForInlineCreate(Request $request): array
{
    return [
        Text::make('name', 'Name')->required(),
        Textarea::make('description', 'Description'),
    ];
}
```

Falls back to `fieldsForCreate()` if not overridden.
