# Fields Reference

Fields map model attributes to UI components. All fields extend the base `Field` class and implement `FieldContract`.

---

## Base Field — Common API

Every field type inherits these methods from `Martis\Fields\Field`.

### Factory

| Method | Signature | Description |
|--------|-----------|-------------|
| `make` | `static make(string $attribute, ?string $label = null): static` | Create a field instance. Label is auto-generated from the attribute name if omitted (e.g. `user_name` → `User Name`). |

### Identity

| Method | Signature | Description |
|--------|-----------|-------------|
| `attribute` | `attribute(): string` | Get the model attribute name. |
| `label` | `label(): string` | Get the human-readable label. |

### Input Configuration

| Method | Signature | Description |
|--------|-----------|-------------|
| `placeholder` | `placeholder(string $text): static` | Set input placeholder text. |
| `nullable` | `nullable(): static` | Allow null values (adds `nullable` validation rule). |
| `readonly` | `readonly(): static` | Prevent modification through the UI. Fill is skipped when readonly. |
| `required` | `required(): static` | Mark as required (adds `required` validation rule). |

### Visibility

| Method | Signature | Description |
|--------|-----------|-------------|
| `showOnIndex` | `showOnIndex(): static` | Show on the index (list) view. |
| `hideFromIndex` | `hideFromIndex(): static` | Hide from the index view. |
| `showOnDetail` | `showOnDetail(): static` | Show on the detail (show) view. |
| `hideFromDetail` | `hideFromDetail(): static` | Hide from the detail view. |
| `showOnForms` | `showOnForms(): static` | Show on create and update forms. |
| `hideFromForms` | `hideFromForms(): static` | Hide from all forms. |
| `showOnCreating` | `showOnCreating(): static` | Show on the create form (granular override). |
| `hideWhenCreating` | `hideWhenCreating(): static` | Hide from the create form. |
| `showOnUpdating` | `showOnUpdating(): static` | Show on the update form (granular override). |
| `hideWhenUpdating` | `hideWhenUpdating(): static` | Hide from the update form. |
| `onlyOnIndex` | `onlyOnIndex(): static` | Visible only on index; hidden everywhere else. |
| `onlyOnDetail` | `onlyOnDetail(): static` | Visible only on detail; hidden everywhere else. |
| `onlyOnForms` | `onlyOnForms(): static` | Visible only on create/update forms. |
| `exceptOnForms` | `exceptOnForms(): static` | Visible everywhere except forms. |

**Granular visibility resolution:** `showOnCreate` / `showOnUpdate` override `showOnForms` when set. The `isVisibleForContext($context)` method resolves the final visibility per context (`index`, `detail`, `create`, `update`, `inline-create`, `preview`).

### Sortable / Searchable

| Method | Signature | Description |
|--------|-----------|-------------|
| `sortable` | `sortable(bool $value = true): static` | Enable/disable column sorting on index. |
| `searchable` | `searchable(bool $value = true): static` | Include/exclude field from search queries. |

### Validation

| Method | Signature | Description |
|--------|-----------|-------------|
| `rules` | `rules(array $rules): static` | Append Laravel validation rules (e.g. `['max:255', 'alpha']`). |
| `unique` | `unique(array $config, ?string $message = null): static` | Mark as unique. Config: `[table]` or `[table, column]`. Optional custom message. |

### Hooks (Customization Callbacks)

| Method | Signature | Description |
|--------|-----------|-------------|
| `resolveUsing` | `resolveUsing(callable $callback): static` | Custom value resolution. Signature: `fn(mixed $value, Model $model, string $attribute): mixed` |
| `fillUsing` | `fillUsing(callable $callback): static` | Custom model filling. Signature: `fn(Model $model, mixed $value, string $attribute): void` |
| `displayUsing` | `displayUsing(callable $callback): static` | Custom display transformation (index/detail only, after resolve). Signature: `fn(mixed $value, Model $model, string $attribute): mixed` |

### Component Override

| Method | Signature | Description |
|--------|-----------|-------------|
| `component` | `component(string $key): static` | Override the React component key used for rendering. |

### Metadata

| Method | Signature | Description |
|--------|-----------|-------------|
| `withMeta` | `withMeta(array $meta): static` | Merge arbitrary key-value metadata into the field's serialized output. |

---

## Field Types

### Id

Auto-incrementing primary key. Read-only, hidden from forms, sortable by default.

```php
Id::make('id');
Id::make('uuid', 'UUID');
```

**Inherits all base Field methods.** No additional specific methods.

**Defaults:** `readonly = true`, `showOnForms = false`, `sortable = true`.

---

### Text

Single-line text input. Renders as `<input type="text">`.

```php
Text::make('title')
    ->sortable()
    ->searchable()
    ->required()
    ->placeholder('Enter title');
```

**Inherits all base Field methods.** No additional specific methods.

---

### Textarea

Multi-line text area. Renders as `<textarea>`.

```php
Textarea::make('body')
    ->rows(6)
    ->hideFromIndex();
```

| Method | Signature | Description | Default |
|--------|-----------|-------------|---------|
| `rows` | `rows(int $rows): static` | Set the number of visible rows. | `5` |

---

### Number

Numeric input. Renders as `<input type="number">`.

```php
Number::make('price')
    ->min(0)
    ->max(10000)
    ->step(0.01)
    ->integer();
```

| Method | Signature | Description | Default |
|--------|-----------|-------------|---------|
| `min` | `min(int\|float $min): static` | Set minimum allowed value. Also adds `min:N` validation rule. | `null` |
| `max` | `max(int\|float $max): static` | Set maximum allowed value. Also adds `max:N` validation rule. | `null` |
| `step` | `step(int\|float $step): static` | Set the stepping interval. | `null` |
| `integer` | `integer(): static` | Enforce integer value (adds `integer` validation rule). | — |

---

### Email

Email input. Extends `Text`. Renders as `<input type="email">`.

```php
Email::make('email')->required();
```

**Inherits all Text methods.** Automatically adds `email` validation rule.

---

### Password

Password input. Hidden from index and detail by default. Auto-hashes values with `Hash::make()`.

```php
Password::make('password')
    ->required()
    ->hideWhenUpdating();
```

**Inherits all base Field methods.** No additional specific methods.

**Behavior:** `resolve()` always returns `null` (never exposes hashes). `fill()` only updates when a non-empty value is provided.

**Defaults:** `showOnIndex = false`, `showOnDetail = false`.

---

### Boolean

Toggle/checkbox for boolean values. Casts values to strict `bool` on resolve and fill.

```php
Boolean::make('active')
    ->trueLabel('Enabled')
    ->falseLabel('Disabled');
```

| Method | Signature | Description | Default |
|--------|-----------|-------------|---------|
| `trueLabel` | `trueLabel(string $label): static` | Label shown for `true` on index/detail. | `__('martis::messages.yes')` |
| `falseLabel` | `falseLabel(string $label): static` | Label shown for `false` on index/detail. | `__('martis::messages.no')` |

---

### Select

Single-select dropdown. Renders as `<select>`.

```php
Select::make('status')
    ->options([
        'Draft' => 'draft',
        'Published' => 'published',
        'Archived' => 'archived',
    ]);

// Sequential format (value used as label)
Select::make('role')->options(['admin', 'editor', 'viewer']);
```

| Method | Signature | Description |
|--------|-----------|-------------|
| `options` | `options(array $options): static` | Set available options. Accepts associative `['Label' => 'value']` or sequential `['value1', 'value2']`. |

---

### MultiSelect

Multi-select with chips display. Persists values as JSON array.

```php
MultiSelect::make('tags')
    ->options([
        'PHP' => 'php',
        'React' => 'react',
        'Laravel' => 'laravel',
    ])
    ->displayUsingLabels();

// Grouped options
MultiSelect::make('skills')->options([
    'Backend' => ['PHP' => 'php', 'Go' => 'go'],
    'Frontend' => ['React' => 'react', 'Vue' => 'vue'],
]);
```

| Method | Signature | Description |
|--------|-----------|-------------|
| `options` | `options(array $options): static` | Set options. Accepts sequential, associative, or grouped `['Group' => ['Label' => 'value']]`. |
| `displayUsingLabels` | `displayUsingLabels(): static` | Display labels instead of raw values on index/detail. |

**Storage format:** JSON array, e.g. `["php","laravel","react"]`.

---

### Date

Date picker. Normalizes Carbon/DateTime instances to ISO string.

```php
Date::make('birth_date')
    ->nullable()
    ->withTime()
    ->format('d/m/Y');
```

| Method | Signature | Description | Default |
|--------|-----------|-------------|---------|
| `withTime` | `withTime(bool $value = true): static` | Enable date+time mode (datetime-local input). | `false` |
| `format` | `format(string $format): static` | Customize the display/serialization format. | `'Y-m-d'` (date), `'Y-m-d H:i:s'` (datetime) |

---

### DateTime

Date and time picker. Extends `Date` with `withTime` pre-enabled.

```php
DateTime::make('published_at')
    ->sortable()
    ->nullable();
```

**Inherits all Date methods.** Renders as datetime-local input.

---

### File

File upload with drag-and-drop, download, single and multiple modes.

```php
File::make('document')
    ->disk('s3')
    ->storagePath('uploads/docs')
    ->maxSize(10240)
    ->acceptedTypes(['pdf', 'doc', 'docx'])
    ->preserveOriginalName()
    ->sanitizeFileName()
    ->nullable();

// Multiple files
File::make('documents')
    ->multiple()
    ->disk('public')
    ->storagePath('uploads/docs')
    ->maxSize(5120);
```

| Method | Signature | Description | Default |
|--------|-----------|-------------|---------|
| `disk` | `disk(string $disk): static` | Set the storage disk. | `'public'` |
| `storagePath` | `storagePath(string $path): static` | Set the upload directory within the disk. | `'uploads'` |
| `maxSize` | `maxSize(int $kb): static` | Maximum file size in kilobytes. | `null` (no limit) |
| `acceptedTypes` | `acceptedTypes(array $mimes): static` | Restrict file extensions, e.g. `['pdf', 'png']`. | `[]` (any) |
| `multiple` | `multiple(bool $value = true): static` | Enable multi-file upload. Stores paths as JSON array. | `false` |
| `preserveOriginalName` | `preserveOriginalName(bool $value = true): static` | Keep original filename (with unique suffix) instead of hash. | `false` |
| `sanitizeFileName` | `sanitizeFileName(bool\|callable $sanitizer = true): static` | Sanitize filenames. Pass a callable for custom logic: `fn(string $name): string`. | `false` |
| `showFileInfo` | `showFileInfo(bool $value = true): static` | Show/hide file info (max size, accepted types) below the field. | `true` |
| `hideFileInfo` | `hideFileInfo(): static` | Hide file info display. | — |

**Resolve output:** `{path, url, name}` (single) or `[{path, url, name}, ...]` (multiple).

---

### Image

Image upload with preview, thumbnail generation, and multiple mode. Extends `File`.

```php
Image::make('avatar')
    ->disk('public')
    ->storagePath('avatars')
    ->thumbnail(300, 300)
    ->maxSize(5120)
    ->preserveOriginalName()
    ->sanitizeFileName();

// Multiple images
Image::make('gallery')
    ->multiple()
    ->thumbnail(200, 200)
    ->acceptedTypes(['jpg', 'jpeg', 'png', 'webp']);
```

**Inherits all File methods**, plus:

| Method | Signature | Description | Default |
|--------|-----------|-------------|---------|
| `thumbnail` | `thumbnail(int $width = 300, int $height = 300): static` | Enable thumbnail generation. Aspect ratio preserved. Uses Intervention Image v3 or GD fallback. | disabled |

**Default accepted types:** `['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']` (SVG excluded for security).

**Resolve output:** `{path, url, name, thumbnailUrl}` (single) or `[{path, url, name, thumbnailUrl}, ...]` (multiple).

---

### BelongsTo

Relationship dropdown with async search. Supports single and multiple (many-to-many) modes.

```php
// Standard BelongsTo (foreign key)
BelongsTo::make('category')
    ->titleAttribute('name')
    ->relatedResource('categories')
    ->searchable()
    ->displayAsLink();

// Can also pass the FK column directly
BelongsTo::make('author_id', 'Author')
    ->titleAttribute('full_name')
    ->relatedResource('users');

// Many-to-many mode
BelongsTo::make('authors', 'Authors')
    ->relatedResource('users')
    ->multiple();
```

| Method | Signature | Description | Default |
|--------|-----------|-------------|---------|
| `titleAttribute` | `titleAttribute(string $attribute): static` | Attribute on the related model used as display label. | `'name'` |
| `foreignKey` | `foreignKey(string $key): static` | Override the foreign key column name. | `'{relationship}_id'` |
| `relatedResource` | `relatedResource(string $uriKey): static` | URI key of the related resource for fetching options. | `null` |
| `relationSearchable` | `relationSearchable(bool $value = true): static` | Enable/disable text search in the dropdown. | `true` |
| `multiple` | `multiple(bool $value = true): static` | Enable many-to-many mode with multi-select checkboxes. Uses `DeferredRelationSync` for pivot sync. | `false` |
| `displayAsLink` | `displayAsLink(bool $value = true): static` | Display as clickable link on index/detail. | `true` |

**Resolve output (single):** `{id, title}` or `null`.
**Resolve output (multiple):** `[{id, title}, ...]`.

---

### Hidden

Hidden form input. Not visible to users on index or detail.

```php
Hidden::make('type')->withMeta(['value' => 'page']);
```

**Inherits all base Field methods.** No additional specific methods.

**Defaults:** `showOnIndex = false`, `showOnDetail = false`.

---

### Heading

Section heading / visual divider. Not a data field — does not read or write model attributes.

```php
Heading::make('Personal Information')
    ->content('Fill in the personal details below.');
```

| Method | Signature | Description |
|--------|-----------|-------------|
| `content` | `content(string $text): static` | Set descriptive text displayed below the heading. |

**Defaults:** `showOnIndex = false`. `resolve()` returns `null`. `fill()` is a no-op.

---

### Badge

Visual read-only indicator with colored badges. Hidden from forms by default.

```php
Badge::make('status')
    ->map([
        'draft' => 'warning',
        'published' => 'success',
        'archived' => 'danger',
    ])
    ->withIcons()
    ->icons([
        'warning' => 'pencil',
        'success' => 'check',
        'danger' => 'archive',
    ]);
```

| Method | Signature | Description | Default |
|--------|-----------|-------------|---------|
| `map` | `map(array $map): static` | Map model values to badge types: `['value' => 'type']`. | `[]` |
| `types` | `types(array $types): static` | Override the full badge type → color map (replaces defaults). | `info/success/warning/danger` |
| `addTypes` | `addTypes(array $types): static` | Add extra badge types without replacing defaults. | — |
| `withIcons` | `withIcons(): static` | Enable icon rendering in badges. | `false` |
| `icons` | `icons(array $icons): static` | Map badge types to icon names: `['type' => 'icon']`. Enables icons automatically. | `[]` |

**Default types:** `info` (blue), `success` (green), `warning` (yellow), `danger` (red).

**Defaults:** Hidden from forms. Display-only.

---

### Status

Status indicator with semantic loading/failed states. Hidden from forms by default.

```php
Status::make('sync_status')
    ->loadingWhen(['syncing', 'queued', 'processing'])
    ->failedWhen(['failed', 'errored', 'cancelled']);
```

| Method | Signature | Description |
|--------|-----------|-------------|
| `loadingWhen` | `loadingWhen(array $values): static` | Values that trigger loading state (spinner). |
| `failedWhen` | `failedWhen(array $values): static` | Values that trigger failed state (error indicator). |

Values not listed in either array render as "success" (completed) state.

**Defaults:** Hidden from forms. Display-only.

---

### Tag

Relational tagging via BelongsToMany. Renders as multi-select with autocomplete.

```php
Tag::make('tags', 'Tags')
    ->relatedResource('tags')
    ->titleAttribute('name')
    ->withPreview()
    ->displayAsList()
    ->showCreateRelationButton()
    ->modalSize('3xl')
    ->preload();
```

| Method | Signature | Description | Default |
|--------|-----------|-------------|---------|
| `relatedResource` | `relatedResource(string $uriKey): static` | URI key of the related resource for autocomplete API. | `null` |
| `titleAttribute` | `titleAttribute(string $attribute): static` | Display attribute on the related model. | `'name'` |
| `withPreview` | `withPreview(): static` | Enable preview popover on hover. | `false` |
| `displayAsList` | `displayAsList(): static` | Display as vertical list instead of horizontal chips. | `false` |
| `showCreateRelationButton` | `showCreateRelationButton(): static` | Show inline creation button. | `false` |
| `modalSize` | `modalSize(string $size): static` | Inline creation modal size: `'sm'`, `'md'`, `'lg'`, `'xl'`, `'2xl'`–`'7xl'`. | `'2xl'` |
| `preload` | `preload(): static` | Preload all available tags on init. Use for small sets. | `false` |
| `relationSearchable` | `relationSearchable(bool $value = true): static` | Enable/disable text search. | `true` |

**Fill behavior:** Uses `DeferredRelationSync` to sync pivot table after model save.

---

### KeyValue

Key-value pair editor stored as JSON. Hidden from index by default.

```php
KeyValue::make('metadata')
    ->keyLabel('Setting')
    ->valueLabel('Value')
    ->actionText('Add Setting')
    ->disableEditingKeys()
    ->disableAddingRows();
```

| Method | Signature | Description | Default |
|--------|-----------|-------------|---------|
| `keyLabel` | `keyLabel(string $label): static` | Label for the key column. | `'Key'` |
| `valueLabel` | `valueLabel(string $label): static` | Label for the value column. | `'Value'` |
| `actionText` | `actionText(string $text): static` | Label for the "add row" button. | `'Add Row'` |
| `disableEditingKeys` | `disableEditingKeys(): static` | Prevent editing existing keys. | `false` |
| `disableAddingRows` | `disableAddingRows(): static` | Prevent adding new rows. | `false` |

**Storage format:** `{"key1":"value1","key2":"value2"}` (JSON object).

**Defaults:** Hidden from index.

---

### Url

URL field with clickable links on index/detail. Adds `url` validation rule.

```php
Url::make('website')
    ->displayText('Visit Website');

// Dynamic text via inherited displayUsing
Url::make('homepage')->displayUsing(fn ($value, $model) => $model->name);
```

| Method | Signature | Description |
|--------|-----------|-------------|
| `displayText` | `displayText(string $text): static` | Static display text for the link. For dynamic text, use `displayUsing()`. |

---

### Code

Code editor with syntax highlighting and JSON mode. Hidden from index by default.

```php
Code::make('config')
    ->language('json')
    ->json();

Code::make('script')
    ->language('php');
```

| Method | Signature | Description | Default |
|--------|-----------|-------------|---------|
| `language` | `language(string $language): static` | Set syntax highlighting language. | `'javascript'` |
| `json` | `json(): static` | Enable JSON mode: pretty-prints for editing, decodes on save. | `false` |

**Supported languages:** `dockerfile`, `htmlmixed`, `javascript`, `markdown`, `nginx`, `php`, `ruby`, `sass`, `shell`, `sql`, `twig`, `vim`, `vue`, `xml`, `yaml-frontmatter`, `yaml`.

**Defaults:** Hidden from index.

---

### Color

Color picker with hex swatch preview. Stores raw hex string (e.g. `#ff5733`).

```php
Color::make('brand_color');
```

**Inherits all base Field methods.** No additional specific methods.

---

### Country

ISO 3166-1 alpha-2 country picker. Stores 2-letter country code (e.g. `US`, `BR`, `PT`).

```php
Country::make('country')
    ->required();

Country::make('nationality')
    ->withFlags()
    ->nullable();
```

| Method | Signature | Description | Default |
|--------|-----------|-------------|---------|
| `withFlags` | `withFlags(): static` | Enable flag emoji display alongside country names. Martis extension. | `false` |
| `withoutFlags` | `withoutFlags(): static` | Disable flag display (explicit reset). | — |

**Static utility methods:**
- `Country::countryList(): array` — Full ISO 3166-1 list with `{label, value, flag}`.
- `Country::resolveCountryName(string $code): ?string` — Get country name from code.
- `Country::resolveCountryFlag(string $code): ?string` — Get flag emoji from code.

---

### Currency

Monetary value input with currency formatting. Extends `Number`.

```php
Currency::make('price')
    ->currency('BRL')
    ->locale('pt_BR')
    ->asMinorUnits()
    ->min(0);

Currency::make('revenue')
    ->currency('USD')
    ->showBadge()
    ->badgeColor('green');
```

**Inherits all Number methods** (`min`, `max`, `step`, `integer`), plus:

| Method | Signature | Description | Default |
|--------|-----------|-------------|---------|
| `currency` | `currency(string $code): static` | Set ISO 4217 currency code. Auto-adjusts step based on decimals. | `'USD'` |
| `locale` | `locale(string $locale): static` | Override app locale for formatting. | app locale |
| `asMinorUnits` | `asMinorUnits(): static` | Treat stored value as minor units (cents). | `false` |
| `asMajorUnits` | `asMajorUnits(): static` | Treat stored value as major units (dollars). | — |
| `displayMode` | `displayMode(string $mode): static` | Display mode: `'text'`, `'badge'`, `'badge_text'`. Martis extension. | `'text'` |
| `showBadge` | `showBadge(): static` | Display as badge only. Martis extension. | — |
| `showText` | `showText(): static` | Display as text only (default). Martis extension. | — |
| `showBadgeText` | `showBadgeText(): static` | Display as badge + text. Martis extension. | — |
| `badgeColor` | `badgeColor(string $color): static` | Set badge color. Martis extension. | `null` |

**Supported currencies (27):** USD, EUR, GBP, BRL, JPY, CNY, CAD, AUD, CHF, INR, MXN, KRW, SEK, NOK, DKK, PLN, THB, ZAR, TRY, RUB, NZD, SGD, HKD, CLP, ARS, COP, PEN.

---

### Markdown

Markdown editor with live preview and optional file uploads. Hidden from index by default.

```php
Markdown::make('content')
    ->preset('default')
    ->alwaysShow()
    ->withFiles('s3');
```

| Method | Signature | Description | Default |
|--------|-----------|-------------|---------|
| `alwaysShow` | `alwaysShow(): static` | Show rendered content on detail view instead of behind a toggle. | `false` |
| `preset` | `preset(string $preset): static` | Markdown rendering preset: `'default'` (GFM), `'commonmark'`, `'zero'`. | `'default'` |
| `withFiles` | `withFiles(string $disk = 'public'): static` | Enable file uploads. Disk specifies storage. | disabled |

**Storage:** Raw Markdown (not HTML). Frontend handles rendering.

**Defaults:** Hidden from index.

---

### Trix

Rich-text HTML editor (Trix) with file upload support. Hidden from index by default.

```php
Trix::make('bio')
    ->alwaysShow()
    ->withFiles('public')
    ->toolbarSize('sm');
```

| Method | Signature | Description | Default |
|--------|-----------|-------------|---------|
| `alwaysShow` | `alwaysShow(): static` | Show HTML content on detail view instead of behind a toggle. | `false` |
| `withFiles` | `withFiles(string $disk = 'public'): static` | Enable file/image uploads in the editor. | disabled |
| `toolbarSize` | `toolbarSize(string $size): static` | Toolbar button size: `'sm'`, `'md'`, `'lg'`. | `'md'` |

**Storage:** Raw HTML.

**Defaults:** Hidden from index.

---

### Sparkline

Inline mini chart for trend visualization. Display-only, hidden from forms.

```php
Sparkline::make('trend_data')
    ->height(40)
    ->width(120)
    ->color('#10b981');

Sparkline::make('daily_revenue')
    ->data([10, 25, 15, 30, 20, 35, 28])
    ->asBarChart()
    ->height(30);

// Callable data source
Sparkline::make('performance')
    ->data(fn ($model) => json_decode($model->metrics, true));
```

| Method | Signature | Description | Default |
|--------|-----------|-------------|---------|
| `data` | `data(array\|callable $data): static` | Set chart data. Array of numbers or callable receiving the model. | model attribute value |
| `asBarChart` | `asBarChart(): static` | Render as bar chart. | line chart |
| `asLineChart` | `asLineChart(): static` | Render as line chart (default). | — |
| `height` | `height(int $px): static` | Chart height in pixels. | `30` |
| `width` | `width(int $px): static` | Chart width in pixels. | auto |
| `color` | `color(string $color): static` | Chart line/bar color (hex or CSS color). | `'#6366f1'` |

**Data resolution:** If `data()` is not set, falls back to model attribute (JSON array or comma-separated string).

**Defaults:** Hidden from forms. Display-only. Fill is a no-op.

---

### Gravatar

Avatar from Gravatar service based on email hash. Display-only, hidden from forms.

```php
Gravatar::make()              // Uses 'email' attribute by default
    ->rounded()
    ->size(80);

Gravatar::make('alt_email')   // Custom email attribute
    ->squared()
    ->size(60);
```

| Method | Signature | Description | Default |
|--------|-----------|-------------|---------|
| `squared` | `squared(): static` | Display with square edges. | — |
| `rounded` | `rounded(): static` | Display with rounded (circle) edges. | `'rounded'` |
| `size` | `size(int $size): static` | Avatar size in pixels. | `40` |

**Static utility:** `Gravatar::gravatarUrl(string $email, int $size = 40): string`

**Resolve output:** Gravatar URL string (not the raw email).

**Defaults:** Hidden from forms. Display-only. Fill is a no-op. Default attribute is `'email'`.
