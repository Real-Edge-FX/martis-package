# Nova v5 Parity Map

Feature parity comparison between Martis and Laravel Nova v5.

> **Mandate:** 1:1 functional parity with Laravel Nova is the minimum requirement.
> **Differentiator:** Architectural superiority in customization.

## Legend

| Status | Meaning |
|--------|---------|
| ✅ | Implemented |
| 🔨 | In progress |
| 📋 | Planned |
| ➖ | Not applicable / by design |

## Resources & CRUD

| Feature | Nova v5 | Martis | Notes |
|---------|---------|--------|-------|
| Resource auto-discovery | ✅ | ✅ | `app/Martis/` auto-discovered |
| Index / List | ✅ | ✅ | Paginated with sorting and search |
| Detail / Show | ✅ | ✅ | |
| Create form | ✅ | ✅ | |
| Update form | ✅ | ✅ | |
| Delete | ✅ | ✅ | |
| Soft delete / Restore | ✅ | ✅ | |
| Context-aware fields | ✅ | ✅ | 6 contexts: index, detail, create, update, inline-create, preview |
| Lifecycle hooks | ✅ | ✅ | beforeSave, afterSave, beforeDelete, afterDelete |
| Event dispatching | ✅ | ✅ | Decoupled event listeners |
| Validation rules | ✅ | ✅ | Per-field Laravel rules |
| Custom messages | ✅ | ✅ | All CRUD messages overridable |

## Fields

| Field Type | Nova v5 | Martis | Notes |
|------------|---------|--------|-------|
| Text | ✅ | ✅ | |
| Textarea | ✅ | ✅ | |
| Number | ✅ | ✅ | min/max/step |
| Boolean | ✅ | ✅ | Toggle switch |
| Select | ✅ | ✅ | |
| MultiSelect | ✅ | ✅ | Chips display |
| Date | ✅ | ✅ | |
| DateTime | ✅ | ✅ | |
| Email | ✅ | ✅ | |
| Password | ✅ | ✅ | Visibility toggle |
| File | ✅ | ✅ | Drag & drop, download |
| Image | ✅ | ✅ | Preview, thumbnail |
| BelongsTo | ✅ | ✅ | Async search, displayAsLink |
| Hidden | ✅ | ✅ | |
| Heading | ✅ | ✅ | Section divider |
| Badge | ✅ | ✅ | Colored status |
| Status | ✅ | ✅ | Loading/success/failed |
| Tag | ✅ | ✅ | |
| KeyValue | ✅ | ✅ | Key-value editor |
| Url | ✅ | ✅ | Clickable links |
| Code | ✅ | ✅ | Syntax highlighting |
| Color | ✅ | ✅ | Color picker |
| Markdown | ✅ | ✅ | Preview + file upload |
| Trix | ✅ | ✅ | Rich-text editor |
| Id | ✅ | ✅ | |
| HasMany | ✅ | 📋 | Relationship management |
| HasOne | ✅ | 📋 | |
| BelongsToMany | ✅ | 📋 | |
| MorphTo | ✅ | 📋 | |
| MorphMany | ✅ | 📋 | |

## Field Features

| Feature | Nova v5 | Martis | Notes |
|---------|---------|--------|-------|
| Sortable | ✅ | ✅ | |
| Searchable | ✅ | ✅ | |
| Required/Nullable | ✅ | ✅ | |
| Placeholder | ✅ | ✅ | |
| Readonly | ✅ | ✅ | |
| Help text | ✅ | ✅ | |
| Visibility flags | ✅ | ✅ | hideFromIndex, onlyOnForms, etc. |
| Custom components | ✅ | ✅ | 4-tier resolution system |
| resolveUsing | ✅ | ✅ | |
| fillUsing | ✅ | ✅ | |
| displayUsing | ✅ | ✅ | |
| Computed fields | ✅ | 📋 | |
| Conditional fields | ✅ | 📋 | dependsOn |

## Authorization

| Feature | Nova v5 | Martis | Notes |
|---------|---------|--------|-------|
| Policy integration | ✅ | ✅ | |
| Per-resource auth | ✅ | ✅ | viewAny, view, create, update, delete |
| Per-action auth | ✅ | ✅ | |
| Gate checks | ✅ | ✅ | |

## UI & Navigation

| Feature | Nova v5 | Martis | Notes |
|---------|---------|--------|-------|
| Sidebar navigation | ✅ | ✅ | |
| Resource grouping | ✅ | ✅ | via group() |
| Global search | ✅ | ✅ | Debounced, grouped results |
| Dark mode | ✅ | ✅ | Toggle + persistent preference |
| Breadcrumbs | ✅ | ✅ | |
| Toast notifications | ✅ | ✅ | Configurable position |
| Dashboard | ✅ | ✅ | Metrics + resource cards |
| Custom dashboards | ✅ | 📋 | Widgets / custom cards |

## Customization

| Feature | Nova v5 | Martis | Notes |
|---------|---------|--------|-------|
| Custom fields | ✅ | ✅ | `martis:field` artisan command |
| Custom components | ➖ | ✅ | `martis:component` artisan command |
| Custom themes | ✅ | ✅ | `martis:theme` artisan command |
| Component overrides | ✅ | ✅ | 4-tier resolution (superior to Nova) |
| Layout presets | ➖ | ✅ | sidebar, topnav, minimal, custom |

## Localization

| Feature | Nova v5 | Martis | Notes |
|---------|---------|--------|-------|
| i18n support | ✅ | ✅ | Laravel lang files |
| Built-in locales | ✅ | ✅ | en-US, pt-BR |
| Custom locales | ✅ | ✅ | Publish and extend |

## CLI / Artisan

| Command | Nova v5 | Martis | Notes |
|---------|---------|--------|-------|
| Install | ✅ | ✅ | `martis:install` |
| Make resource | ✅ | ✅ | `martis:resource` |
| Make field | ✅ | ✅ | `martis:field` (PHP + React) |
| Make component | ➖ | ✅ | `martis:component` |
| Make theme | ➖ | ✅ | `martis:theme` |
| Create user | ✅ | ✅ | `martis:user` |
| Publish assets | ✅ | ✅ | `martis:vendor-publish` |

## Testing

| Feature | Nova v5 | Martis |
|---------|---------|--------|
| PHP tests | ✅ | ✅ (Pest) |
| JS tests | ➖ | ✅ (Vitest) |
| Static analysis | ➖ | ✅ (PHPStan Level 8) |
| CI pipeline | ✅ | ✅ (`make ci`) |
