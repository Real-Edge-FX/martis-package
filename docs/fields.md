# Fields Reference

Fields map model attributes to UI components. All fields extend the base `Field` class and implement `FieldContract`.

## Common Methods

All fields support these fluent methods:

| Method | Description |
|--------|-------------|
| `make(attribute, label)` | Create field instance (label auto-generated from attribute if omitted) |
| `placeholder(text)` | Set input placeholder text |
| `sortable()` | Enable sorting on index view |
| `searchable()` | Include field in search queries |
| `required()` | Mark as required (adds validation rule) |
| `nullable()` | Allow null values |
| `readonly()` | Prevent modification through the UI |
| `rules([...])` | Set Laravel validation rules |
| `help(text)` | Display help text below the field |
| `withMeta([...])` | Merge additional metadata into the field descriptor |
| `component(key)` | Override the React component used to render this field |
| `resolveUsing(fn)` | Custom resolve callback |
| `fillUsing(fn)` | Custom fill callback |
| `displayUsing(fn)` | Custom display transformation |

## Visibility Methods

| Method | Effect |
|--------|--------|
| `hideFromIndex()` | Hidden on the index (list) view |
| `hideFromDetail()` | Hidden on the detail (show) view |
| `hideWhenCreating()` | Hidden on the create form |
| `hideWhenUpdating()` | Hidden on the update form |
| `showOnIndex()` | Visible on the index view |
| `showOnDetail()` | Visible on the detail view |
| `showOnCreating()` | Visible on the create form |
| `showOnUpdating()` | Visible on the update form |
| `onlyOnIndex()` | Visible only on the index view |
| `onlyOnDetail()` | Visible only on the detail view |
| `onlyOnForms()` | Visible only on create/update forms |
| `exceptOnForms()` | Visible everywhere except forms |

## Field Types

### Id

Auto-incrementing primary key. Hidden on forms by default.

```php
Id::make('id');
```

### Text

Single-line text input.

```php
Text::make('title')
    ->sortable()
    ->searchable()
    ->required()
    ->placeholder('Enter title');
```

### Textarea

Multi-line text input with configurable rows.

```php
Textarea::make('body')
    ->rows(6)
    ->hideFromIndex();
```

### Number

Numeric input with min/max/step.

```php
Number::make('price')
    ->min(0)
    ->max(10000)
    ->step(0.01);
```

### Email

Email input with validation.

```php
Email::make('email')->required();
```

### Password

Password input with visibility toggle.

```php
Password::make('password')
    ->required()
    ->hideWhenUpdating();
```

### Boolean

Toggle switch for boolean values.

```php
Boolean::make('active');
```

### Select

Single-select dropdown.

```php
Select::make('status')
    ->options([
        'draft' => 'Draft',
        'published' => 'Published',
        'archived' => 'Archived',
    ]);
```

### MultiSelect

Multi-select with chips display.

```php
MultiSelect::make('tags')
    ->options([
        'php' => 'PHP',
        'react' => 'React',
        'laravel' => 'Laravel',
    ]);
```

### Date

Date picker.

```php
Date::make('birth_date')->nullable();
```

### DateTime

Date and time picker.

```php
DateTime::make('published_at')
    ->sortable()
    ->nullable();
```

### File

File upload with drag-and-drop and download support.

```php
File::make('document')
    ->disk('public')
    ->path('documents');
```

### Image

Image upload with preview and thumbnail.

```php
Image::make('avatar')
    ->disk('public')
    ->path('avatars');
```

### BelongsTo

Searchable relationship dropdown with async search.

```php
BelongsTo::make('category_id', 'Category')
    ->titleAttribute('name')
    ->searchable()
    ->displayAsLink();
```

### Hidden

Hidden field — rendered in forms but not visible to users.

```php
Hidden::make('type')->withMeta(['value' => 'page']);
```

### Heading

Section heading / visual divider. Does not map to a model attribute.

```php
Heading::make('Personal Information');
```

### Badge

Colored status badge for index and detail views.

```php
Badge::make('status')
    ->map([
        'active' => 'success',
        'inactive' => 'danger',
        'pending' => 'warning',
    ]);
```

### Status

Status indicator (loading / success / failed).

```php
Status::make('sync_status')
    ->loadingWhen(['syncing'])
    ->failedWhen(['failed']);
```

### Tag

Tag and chip display.

```php
Tag::make('labels');
```

### KeyValue

Key-value pair editor.

```php
KeyValue::make('metadata')
    ->keyLabel('Key')
    ->valueLabel('Value');
```

### Url

URL field with clickable links and custom display text.

```php
Url::make('website')
    ->displayText('Visit Website');
```

### Code

Code editor with syntax highlighting and JSON mode.

```php
Code::make('config')
    ->language('json')
    ->json();
```

### Color

Color picker with hex value persistence.

```php
Color::make('brand_color');
```

### Markdown

Markdown editor with live preview and file upload support.

```php
Markdown::make('content')
    ->preset('default');
```

### Trix

Rich-text HTML editor (Trix) with file upload support.

```php
Trix::make('bio');
```
