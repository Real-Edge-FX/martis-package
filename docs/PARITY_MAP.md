# Parity Map — Martis vs Laravel Nova v5

> Last updated: 2026-04-27 (post `v0.9.0-beta` tag)
> Coverage: Track B Foundation (Blocks 1–10) + Extended Fields + full Relation suite (12 types) + Lenses + Metrics + Dashboards + Menus + SSO + Reactive forms + Locale extensibility

## Deltas since `v0.7.0-beta`

### `v0.8.0-beta`
- **Sticky views** — per-resource session storage of search / sort / filters / pagination / trashed-toggle / `filtersOpen`. Survives back-navigation, drops on resource change.
- **Notifications subsystem** — in-app bell + standard `notifications` table + `MartisNotification::make(title:, message:, level:)` inline factory + Laravel's `Notifiable` trait on the recipient.
- **Cache control surface** — `MartisCache::extend('name', enabled, ttl)` for host-app layers + `/martis/system/cache` admin page (toggle/version/clear per-type).

### `v0.9.0-beta`
- **Reactive fields** — `Field::dependsOn(['attr'], Closure)` ships server-side resolution via `POST /api/resources/{r}/sync-field` with debounce + AbortController.
- **Closure-aware setters across the field API** — `nullable`, `required`, `readonly`, `default`, `placeholder`, `help`, `tooltip`, `withLabel`, `rules`, `Select::options`, `MultiSelect::options`, `BooleanGroup::options` all accept a `Closure` resolved at request time.
- **Customisation hooks gain `?Request` 4th argument** — `resolveUsing` / `fillUsing` / `displayUsing` callbacks. Plus `displayUsing(array)` accepts a chainable transformation pipeline.
- **Context-aware validation** — `creationRules()` / `updateRules()` layer on top of `rules()` for create / update branches; `immutable()` flags a field as writable on create, readonly on update.
- **Save variants** — `Create & add another`, `Create & view list`, `Save & continue editing`, `Save & view list`.
- **Reset filters toolbar button** — clears only the active filter set; coexists with `Reset view`.
- **Global Search per-resource config** — `globallySearchable()` accepts `bool|array{enabled?, limit?, min_query?}`. New `searchOrderBy()` hook applied AFTER the search filter.
- **"View all N matches in {resource}" footer** — palette overflow item that lands on the resource index with the search query pre-applied (URL `?search=` hydration on mount).
- **Locale extensibility** — per-key deep merge of consumer overrides, configurable host-app namespaces (`martis.locales.app_namespaces`), configurable fallback chain (`martis.locales.fallback_chain`).
- **SSO subsystem** — pluggable provider contract (`AzureProvider` reference impl), identity-to-user resolver, role mapping (column / config / callable), permission adapters (Spatie / native / callable), idempotent `martis:sso <provider>` generator.
- **Layout-flatten audit** — closed five latent bugs across `HasManyController`, `MorphManyController`, `LensController`, `ResourceController` sync-field lookup, and `ResourceController` relatable search. Every code path that iterates `Resource::fields()` raw now flattens `Section` / `Panel` / `TabGroup` first.

## Legend

| Status | Meaning |
|--------|---------|
| DONE | Implemented and covered by ≥ 1 Feature test |
| PARTIAL | Partial coverage; gap noted in the row |
| TODO | Not yet implemented (planned in roadmap) |
| WON'T | Out of scope / will not ship in Martis (rationale in Notes) |
| ⭐ | Martis differential — feature does not exist in Nova v5 |

---

## Core Features

| Feature | Nova v5 | Martis | Status | Notes |
|---------|---------|--------|--------|-------|
| Resource System | Resources with Model binding | Resource class + model() | DONE | Block 3 |
| Resource Discovery | Auto-discovery | ResourceDiscovery + ServiceProvider | DONE | Block 3 |
| Resource Hooks | beforeSave/afterSave etc | Event dispatching hooks | DONE | Block 3 |
| Resource Groups | Sidebar grouping | group() method | DONE | — |
| Resource Subtitle | Description line | subtitle() static method | DONE | — |
| Resource Title | Dynamic record title | titleAttribute() | DONE | — |
| Resource Icons | Sidebar icons | icon() + Phosphor Icons | DONE | — |
| Custom Messages | CRUD notification messages | createdMessage() etc | DONE | — |
| Contracts/Interfaces | — | FieldContract, ResourceContract | DONE | Extensibility |

---

## Fields (50 concrete types)

Alphabetical list of every concrete field in `src/Fields/` (excluding the abstract `Field.php`
base class and the `DeferredRelationSync.php` helper registry). The "Nova v5?" column
indicates whether Laravel Nova v5 ships an equivalent field out of the box.

| Field | Nova v5? | Martis | Notes |
|-------|:--------:|--------|-------|
| Audio | ✓ | `Audio::make()` + client-side canvas waveform + `downloadable()` | ⭐ waveform is Martis-only |
| Avatar | ✓ | `Avatar::make()` + per-row `fallback(Closure)` + `AvatarShape` enum | ⭐ Extended |
| Badge | ✓ | `Badge::make()` + closure maps/labels/types/icons + `resolveBadgeUsing()` | ⭐ Extended |
| BelongsTo | ✓ | `BelongsTo::make()` + `/relatable/{field}` endpoint + inline create + multiple mode | DONE |
| BelongsToMany | ✓ | `BelongsToMany::make()` + pivot fields + attach/detach + `RelationshipTableShell` | DONE |
| Boolean | ✓ | `Boolean::make()` + `trueLabel` / `falseLabel` | DONE |
| BooleanGroup | ✓ | `BooleanGroup::make()` + `grouped([sec => keys])` + `minChecked`/`maxChecked` + `requireAny`/`All` | ⭐ Extended |
| Code | ✓ | `Code::make()` | DONE |
| Color | ✓ | `Color::make()` | DONE |
| Country | ✓ | `Country::make()` | DONE |
| Currency | ✓ | `Currency::make()` + symbol | DONE |
| Date | ✓ | `Date::make()` | DONE |
| DateTime | ✓ | `DateTime::make()` | DONE |
| Email | ✓ | `Email::make()` + format validation | DONE |
| File | ✓ | `File::make()` + disk/path/types/maxSize | DONE |
| Gravatar | ✓ | `Gravatar::make()` | DONE |
| HasMany | ✓ | `HasMany::make()` + inline CRUD + soft-delete dropdown + toolbar hide flags | DONE |
| HasManyThrough | ✓ | `HasManyThrough::make()` + read-only defaults + `throughBreadcrumb()` + `countBadge()` | ⭐ Extended |
| HasOne | ✓ | `HasOne::make()` + `ofMany()` static constructor | DONE |
| HasOneOfMany | ✓ | `HasOneOfMany::make()` + `latestByTimestamp` + `aggregateVia` + Latest-of-N pill | ⭐ Extended |
| HasOneThrough | ✓ | `HasOneThrough::make()` + read-only defaults + `throughBreadcrumb()` | ⭐ Extended |
| Heading | ✓ | `Heading::make()` + `content()` | DONE |
| Hidden | ✓ | `Hidden::make()` | DONE |
| Icon | ✗ | `Icon::make()` + Phosphor picker / palette / `colorFrom()` / computed mode | ⭐ 100% Martis |
| Id | ✓ | `Id::make()` | DONE |
| Image | ✓ | `Image::make()` + thumbnail/disk | DONE |
| KeyValue | ✓ | `KeyValue::make()` | DONE |
| Line | ✓ | `Line::make()` + `asHeading`/`asBase`/`asSmall`/`asMuted`/`asCode` + `subtitleFrom()` | ⭐ Extended |
| Markdown | ✓ | `Markdown::make()` | DONE |
| MorphMany | ✓ | `MorphMany::make()` + inline CRUD + soft-delete dropdown + toolbar hide flags | DONE |
| MorphOne | ✓ | `MorphOne::make()` + `ofMany()` static constructor | DONE |
| MorphOneOfMany | ✓ | `MorphOneOfMany::make()` + `latestByTimestamp` + `aggregateVia` | ⭐ Extended |
| MorphTo | ✓ | `MorphTo::make()` + inline create | DONE |
| MorphToMany | ✓ | `MorphToMany::make()` + pivot fields + attach/detach + `RelationshipTableShell` | DONE |
| MultiSelect | ✓ | `MultiSelect::make()` | DONE |
| Number | ✓ | `Number::make()` + min/max/step | DONE |
| Password | ✓ | `Password::make()` + masked | DONE |
| PasswordConfirmation | ✓ | `PasswordConfirmation::make()` + live match | DONE |
| Select | ✓ | `Select::make()` + `options()` + enum support | DONE |
| Slug | ✗ | `Slug::make()` + live collision check + `freezeAfterPublish()` | ⭐ 100% Martis |
| Sparkline | ✓ | `Sparkline::make()` | DONE |
| Stack | ✓ | `Stack::make()` + renders on index + `divider()` | ⭐ Extended (Nova detail-only) |
| Status | ✓ | `Status::make()` + colors | DONE |
| Tag | ✓ | `Tag::make()` | DONE |
| Text | ✓ | `Text::make()` | DONE |
| Textarea | ✓ | `Textarea::make()` + `rows()` | DONE |
| Timezone | ✗ | `Timezone::make()` + grouped IANA dropdown + live clock | ⭐ 100% Martis |
| Trix | ✓ | `Trix::make()` + attachment uploads + `ImageModal` soft history lock | DONE |
| UiAvatar | ✓ | `UiAvatar::make()` + deterministic palette + `colorFrom()` + `initials(Closure)` | ⭐ Extended (client-side) |
| Url | ✓ | `Url::make()` + auto-scheme normalisation | DONE |

### Field-wide behaviour

| Feature | Nova v5 | Martis | Status | Notes |
|---------|---------|--------|--------|-------|
| Field Visibility | showOnIndex/hideFromIndex | All 4 contexts supported | DONE | Block 4 |
| Field Validation | Built-in validation | required/nullable/rules() | DONE | Block 4 |
| Context-aware Validation | creationRules/updateRules | `creationRules()` / `updateRules()` layered on `rules()` | DONE | v0.9.0 |
| Immutable on Update | immutable() | `immutable()` (writable on create, readonly on update) | DONE | v0.9.0 |
| Field Sorting | Sortable columns | sortable() | DONE | Block 4 |
| Field Search | Searchable fields | searchable() | DONE | Block 4 |
| Field Component Override | Custom component key | ->component('key') | DONE | Block 9 |
| Field Unique Validation | Unique validation | ->unique(['table','col'],'msg') | DONE | — |
| Column Span | Grid layout | colSpan/colSpanMd/colSpanLg | DONE | Extended |
| Placeholder | Input placeholder | placeholder() | DONE | Extended |
| Reactive Fields | dependsOn() | `Field::dependsOn(['attr'], Closure)` + `POST /api/resources/{r}/sync-field` | DONE | v0.9.0 |
| Closure-aware Setters | Limited closures | `nullable`, `required`, `readonly`, `default`, `placeholder`, `help`, `tooltip`, `withLabel`, `rules`, `Select::options`, `MultiSelect::options`, `BooleanGroup::options` accept `Closure` | DONE | v0.9.0 |
| Customisation Hooks `?Request` arg | resolveUsing/fillUsing/displayUsing | 4th arg = `?Request`; `displayUsing(array)` chainable transformation pipeline | DONE | v0.9.0 |
| Relationship Toolbar Controls | — | `ControlsRelationshipToolbar` trait (9 hide flags) | DONE | ⭐ Martis extension |

---

## API and CRUD

| Feature | Nova v5 | Martis | Status | Notes |
|---------|---------|--------|--------|-------|
| Pagination | Auto pagination | JSON paginated response | DONE | Block 5 |
| Sorting (API) | Sort by column | ?sort=col&direction=asc/desc | DONE | Block 5 |
| Search (API) | Global search | ?search=term | DONE | Block 5 |
| API Schema | Field metadata | /schema endpoint | DONE | Block 5 |
| Authentication | Admin login | Sanctum session-based auth | DONE | Block 6 |
| Soft Deletes | Restore archived | SoftDeletes detection + restore | DONE | Block 8 |
| BelongsTo Options | Relationship dropdown | /relatable/{field} endpoint | DONE | Block 4+8 |
| HasMany API | Related records | /has-many/{relationship} endpoint | DONE | HasMany |
| Validation Errors | Inline/toast | errorDisplay() configurable | DONE | Extended |
| File Upload | Multipart form | FormData with file detection | DONE | — |
| Reactive Field Sync | Form state sync | `POST /api/resources/{r}/sync-field` with debounce + AbortController | DONE | v0.9.0 |
| Save Variants | Create/Save & continue | `Create & add another`, `Create & view list`, `Save & continue editing`, `Save & view list` | DONE | v0.9.0 |
| Global Search per-resource config | bool | `globallySearchable()` accepts `bool\|array{enabled?, limit?, min_query?}` | DONE | v0.9.0 |
| Global Search ordering | — | `searchOrderBy()` hook applied AFTER the search filter | DONE | ⭐ v0.9.0 |

---

## Frontend

| Feature | Nova v5 | Martis | Status | Notes |
|---------|---------|--------|--------|-------|
| React SPA | — | React 18 + Router + Query + Vite | DONE | Block 7 |
| Index Page | List with table | ResourceIndex.tsx | DONE | Block 8 |
| Detail Page | Show record | ResourceDetail.tsx + HasMany inline | DONE | Block 8 |
| Create Form | Create record | ResourceCreate.tsx | DONE | Block 8 |
| Edit Form | Update record | ResourceUpdate.tsx | DONE | Block 8 |
| Delete | Hard/soft delete | Delete + DeleteModal + restore | DONE | Block 8 |
| Override System | Custom components | 4-tier componentRegistry | DONE | Block 9 |
| Drawer Overrides | — | DrawerCreate/Update/Detail | DONE | Block 9 |
| Layout Overrides | — | layoutRegistry + 3 presets | DONE | Block 9 |
| i18n | Multiple languages | react-i18next + API translations | DONE | — |
| Dark/Light Theme | — | ThemeContext + CSS variables | DONE | Full support |
| Phosphor Icons | — | 1,512 icons via icon() | DONE | — |
| File/Image Display | — | Upload preview + thumbnail | DONE | — |
| Global Search | — | GlobalSearch component | DONE | Top bar |
| Toast Notifications | — | ToastContext + PrimeReact Toast | DONE | Extended |
| In-app Notifications | Bell + center | topbar bell dropdown over Laravel's standard `notifications` table + `MartisNotification::make(title:, message:, level:)` inline factory | DONE | v0.8.0 |
| Sticky Views | Per-resource session state | search / sort / filters / pagination / trashed / `filtersOpen` survive back-navigation | DONE | ⭐ v0.8.0 |
| Reset Filters | — | Toolbar button clears active filter set; coexists with `Reset view` | DONE | v0.9.0 |
| "View all N matches" overflow | Palette footer | Lands on resource index with `?search=` hydration | DONE | v0.9.0 |
| Responsive Grid | — | colSpan/colSpanMd/colSpanLg | DONE | Extended |
| Code Splitting | — | React.lazy() per page | DONE | Performance |

---

## Quality and Infrastructure

| Feature | Notes | Status |
|---------|-------|--------|
| CI via Makefile | make ci: lint + typecheck + test | DONE |
| PHP Tests (Pest) | 180+ tests | DONE |
| TypeScript Tests (Vitest) | 43 tests | DONE |
| E2E Tests (Playwright) | 13 tests covering main flows | DONE |
| PHPStan Level 8 | Static analysis | DONE |
| ESLint | TypeScript linting | DONE |
| Laravel Pint | PHP formatting | DONE |
| GitHub Actions CI/CD | Self-hosted runner | DONE |
| Post-merge Deploy Hook | Auto build after merge | DONE |
| API Docs (Scramble) | Swagger at /docs/api | DONE |
| Pre-push Hook | Blocks push if CI fails | DONE |
| Artisan Commands | martis:component scaffold | DONE |

---

## Status by Subsystem (Post Track B)

| Feature | Nova v5 | Status | Notes |
|---------|---------|--------|-------|
| Actions | Batch actions on records | DONE | Full system + icons, groups, tooltips, pivot, disabled states |
| Filters | Column filters | DONE | Full system: Select, Boolean, Date, DateRange + collapsible panel + pills |
| Lenses | Custom filtered views | DONE | `src/Lenses/Lens.php` + summary rows + per-lens cache + default filters + URL sync |
| Metrics | Dashboard metric cards | DONE | Value, Trend, Partition, Progress + polling + filters + 12-col grid |
| Dashboards | Multi-dashboard layout | DONE | `src/Dashboards/` + dashboard filters + refresh + fallback |
| Menus | Declarative navigation | DONE | `src/Menu/` — `Menu`, `MenuSection`, `MenuItem`, `Martis::mainMenu()` |
| Panels/Tabs | Field grouping in tabs | DONE | Panel, Section, Tab, TabGroup + description + help text |
| ManyToMany | Pivot relationships | DONE | `BelongsToMany`, `MorphToMany` share `RelationshipTableShell` |
| MorphTo/MorphMany | Polymorphic relations | DONE | `MorphTo`, `MorphOne`, `MorphOneOfMany`, `MorphMany`, `MorphToMany` |
| HasOneOfMany / HasOneThrough / HasManyThrough | Extended relations | DONE | Full parity + `throughBreadcrumb()` + `countBadge()` |
| Relationship Toolbar Controls | — | DONE | 9 hide flags via `ControlsRelationshipToolbar` (Martis extension) |
| Soft-delete trashed dropdown in relation panels | — | DONE | Shared `?trashed=with\|only` toolbar filter |
| Modal history locks | — | DONE | Hard + soft locks (`useModalHistoryLock`, `useModalHistoryBackToClose`) |
| `resolvedPerPage()` clamp | — | DONE | Shared between `Resource` and `Lens` |
| Impersonation | Admin impersonation | TODO | Low priority |
| Notifications | In-app notifications | DONE | v0.8.0 — topbar bell dropdown over Laravel's standard `notifications` table + `MartisNotification::make(title:, message:, level:)` inline factory |
| Custom Tools | Sidebar tools/pages | TODO | Medium priority |
| Repeater | Dynamic field groups | DONE | ⭐ asPolymorphic() + rowTemplates + duplicate row + bulk-paste CSV/JSON + collapse/reorder/min-max + dependsOn |
| Sticky Views | — | DONE | ⭐ v0.8.0 — per-resource session state for search/sort/filters/pagination/trashed/`filtersOpen` |
| Cache Control Surface | — | DONE | ⭐ v0.8.0 — `MartisCache::extend(...)` + `/martis/system/cache` admin page (toggle/version/clear per-type) |
| Reactive Fields | dependsOn() | DONE | v0.9.0 — server-side resolution via `POST /api/resources/{r}/sync-field` with debounce + AbortController |
| Save Variants | Create/Save & continue | DONE | v0.9.0 — 4 variants across Create + Update branches |
| Locale Extensibility | Lang publishing | DONE | v0.9.0 — per-key deep merge + `martis.locales.app_namespaces` + `martis.locales.fallback_chain` |
| SSO | Nova Auth Tools | DONE | v0.9.0 — pluggable provider contract (`AzureProvider` reference impl) + identity-to-user resolver + role mapping (column / config / callable) + permission adapters (Spatie / native / callable) + idempotent `martis:sso <provider>` generator |

---

## Coverage Summary

- **50 field types** implemented (38 core + 12 relation types; 7 carry a ⭐ Martis differential)
- **12 relation fields** — `BelongsTo`, `HasOne`, `HasOneOfMany`, `HasOneThrough`, `HasMany`, `HasManyThrough`, `BelongsToMany`, `MorphTo`, `MorphOne`, `MorphOneOfMany`, `MorphMany`, `MorphToMany` — with shared toolbar, soft-delete dropdown and per-field hide flags
- **Full CRUD** — Index + Detail + Create + Edit + Delete with soft-delete support
- **Relation panels** — Inline tables with search, sort, pagination, CRUD, attach/detach, pivot fields
- **Lenses** — `summary()`, `cacheFor()`, `withDefaultFilters()`, URL state sync
- **Metrics + Dashboards + Menus** — feature-complete
- **Reactive Forms** — `Field::dependsOn(['attr'], Closure)` + `POST /api/resources/{r}/sync-field` with debounce + AbortController
- **Closure-aware Field API** — 13 setters accept `Closure` resolved at request time
- **Context-aware Validation** — `creationRules()` / `updateRules()` + `immutable()` flag
- **Auth** — Login/logout + Sanctum session + SSO (pluggable provider contract, AzureProvider reference, role mapping, permission adapters)
- **Notifications** — topbar bell dropdown over Laravel's standard `notifications` table + `MartisNotification::make(title:, message:, level:)` inline factory
- **Sticky Views** — per-resource session state for search / sort / filters / pagination / trashed / `filtersOpen`
- **Cache Control Surface** — `MartisCache::extend(...)` + `/martis/system/cache` admin page
- **Locale Extensibility** — per-key deep merge + configurable host-app namespaces + configurable fallback chain
- **Override System v1** — 4-tier component resolution + drawer overrides + layout overrides
- **Modal history locks** — hard + soft variants in `resources/js/lib/historyLock.ts`
- **i18n** — EN + PT-BR + PT-PT with dynamic loading
- **File/Image Upload** — Disk config, thumbnails, drag-drop, type/size validation
- **Icons** — 1,512 Phosphor Icons
- **CI** — make ci PASS, GitHub Actions self-hosted runner

---

## Authorization (Nova v5 Parity —)

| Feature | Nova v5 | Martis | Status | Notes |
|---------|---------|--------|--------|-------|
| Policy Resolution | Gate + model policy | Explicit + auto-discovery + Gate | DONE | 4-level chain |
| Resource Policy | static $policy | static $policy property | DONE | — |
| Auto-Discovery | Convention-based | {namespace}\{Resource}Policy | DONE | — |
| viewAny | Policy method | authorizedToViewAny() | DONE | Nav + index |
| view | Policy method | authorizedToView() | DONE | Detail page |
| create | Policy method | authorizedToCreate() | DONE | Create button |
| update | Policy method | authorizedToUpdate() | DONE | Edit button |
| delete | Policy method | authorizedToDelete() | DONE | Delete button |
| restore | Policy method | authorizedToRestore() | DONE | Soft deletes |
| forceDelete | Policy method | authorizedToForceDelete() | DONE | Permanent delete |
| replicate | Policy method | authorizedToReplicate() | DONE | Duplicate record |
| runAction | Policy method | authorizedToRunAction() | DONE | Normal actions |
| runDestructiveAction | Policy method | authorizedToRunDestructiveAction() | DONE | Destructive actions |
| add{Model} | Relationship policy | authorizedToAdd() | DONE | Inline create |
| attach{Model} | Relationship policy | authorizedToAttach() | DONE | Attach related |
| attachAny{Model} | Relationship policy | authorizedToAttachAny() | DONE | Attach button |
| detach{Model} | Relationship policy | authorizedToDetach() | DONE | Detach related |
| authorizable() | Disable auth per resource | authorizable() | DONE | — |
| Field canSee | Field visibility callback | canSee() + canSeeWhen() | DONE | — |
| before() callback | Pre-check in policy | before() support | DONE | — |
| Auth Metadata | _authorization in responses | authorizationMetadata() | DONE | — |
| Frontend Enforcement | Button visibility | _authorization consumed | DONE | — |
| Policy Defaults | Missing method behavior | Nova v5 compatible matrix | DONE | — |
| Policy Generator | make:policy | martis:make-policy | DONE | Custom stub |


---

## Actions System (Nova v5 Parity —)

| Feature | Nova v5 | Martis | Status | Notes |
|---------|---------|--------|--------|-------|
| Action Base Class | Action | Action::make() | DONE | Full parity |
| DestructiveAction | DestructiveAction | DestructiveAction::make() | DONE | Red UI + confirm |
| handle() Contract | ActionFields + Collection | Same contract | DONE | — |
| Action Fields | fields(Request) | Same contract + Field reuse | DONE | — |
| ActionResponse | message/danger/redirect/visit | Full parity + emit/modal | DONE | — |
| Action Registration | actions(Request) | Same contract | DONE | — |
| canSee | Visibility callback | canSee(Closure) | DONE | — |
| canRun | Per-model authorization | canRun(Closure) | DONE | — |
| Policy Fallback | runAction/runDestructiveAction | 4-level chain | DONE | — |
| showOnIndex | Index visibility | showOnIndex() | DONE | — |
| showOnDetail | Detail visibility | showOnDetail() | DONE | — |
| showInline | Per-row buttons | showInline() | DONE | — |
| onlyOnIndex | Index only | onlyOnIndex() | DONE | — |
| onlyOnDetail | Detail only | onlyOnDetail() | DONE | — |
| onlyInline | Inline only | onlyInline() | DONE | — |
| exceptOnIndex | Hide from index | exceptOnIndex() | DONE | — |
| exceptOnDetail | Hide from detail | exceptOnDetail() | DONE | — |
| exceptInline | Hide from inline | exceptInline() | DONE | — |
| standalone() | No models required | standalone() | DONE | — |
| sole() | Exactly one model | sole() | DONE | — |
| Closure Actions | Action::using() | Action::using(name, fn) | DONE | — |
| Queued Actions | ShouldQueue | ShouldQueue trait | DONE | — |
| Queue Customization | connection/queue | Property-based config | DONE | — |
| Action Log | action_events table | martis_action_events + withoutActionEvents | DONE | — |
| markAsFinished | Queued status | markAsFinished($model) | DONE | — |
| markAsFailed | Queued error | markAsFailed($model, $e) | DONE | — |
| Confirm Text | confirmText() | confirmText() | DONE | — |
| Confirm Button | confirmButtonText() | confirmButtonText() | DONE | — |
| Cancel Button | cancelButtonText() | cancelButtonText() | DONE | — |
| Without Confirm | withoutConfirmation() | withoutConfirmation() | DONE | — |
| Modal Sizes | sm/md/lg/.../fullscreen | ModalSize enum + fullscreen | DONE | — |
| Pivot Actions | pivotAction() | pivotAction() + referToPivotAs() | DONE | — |
| then() Callback | Post-process callback | then(Closure) | DONE | See [Post-processing with then()] in actions.md |
| Action Icons | — | icon('phosphor-name') | DONE | Martis extension |
| Action Groups | — | group('name') + dot-notation | DONE | Martis extension |
| Artisan Command | nova:action | martis:action (--destructive) | DONE | — |
| Inline Multi-select | Bulk select | Checkbox column | DONE | — |
| Disabled Actions | — | Visual disabled state for canRun | DONE | Martis extension |
| PrimeReact Tooltips | — | Tooltip on all action buttons | DONE | Martis extension |

---

## Martis Differentials vs Nova v5

> Features unique to Martis that do NOT exist in Laravel Nova v5.
> These are marked as **Martis extensions** and are fully configurable per-action/per-resource.

### Built-in Components

| Component | Description | Status |
|-----------|-------------|--------|
| Drawer Overrides | Create/Update/Detail as slide-out drawers instead of full pages | DONE |
| Layout Registry | 3 layout presets (default, topnav, minimal) with hot-swap | DONE |
| 4-Tier Component Override | Global → Resource → Field → Instance override resolution | DONE |
| PrimeReact Tooltip System | Tooltips on action buttons, fields, and UI elements | DONE |
| Custom Action Components | Actions can render custom React components inside modals | DONE |

### Action System Extensions

| Feature | Description | Nova v5 | Martis |
|---------|-------------|---------|--------|
| Action Icons | Phosphor icon per action via icon() | Not available | `->icon('trash')` |
| Action Groups | Menu organisation with dot-notation | Not available | `->group('Export.CSV')` |
| Disabled Action State | Actions shown as disabled when canRun fails | Hidden entirely | Visible but greyed out |
| Dry-Run Preview | Optional preview mode before execution | Not available | `->withDryRun()` |
| Custom Component | Render custom React inside action modal | Not available | `->component('key', props)` |
| Inline Action Tooltips | PrimeReact tooltips on icon-only buttons | Not available | Automatic |
| Mobile-Aware Dropdowns | Viewport-bounded dropdown positioning | Not guaranteed | Auto-repositioning |

### Field System Extensions

| Feature | Description | Nova v5 | Martis |
|---------|-------------|---------|--------|
| Extended Field Types | Badge, Status, Code, Color, Country, Currency, Icon⭐, Slug⭐, Stack⭐, Timezone⭐, Audio⭐, Avatar⭐, BooleanGroup⭐, UiAvatar⭐, etc. | Separate packages | Built-in |
| BooleanGroup grouped sections | `grouped([section => keys])` collapsible panels for long flag lists | Flat list only | ⭐ |
| BooleanGroup min/max counter | Live UI counter + server validation | Not available | ⭐ |
| Avatar per-row fallback | Closure receives the model; each row picks its own fallback URL | Static URL only | ⭐ |
| UiAvatar deterministic palette | 16-slot hash palette — same name → same colour, no DB column | Not available | ⭐ |
| Audio waveform preview | Client-side canvas waveform via Web Audio API | Not available | ⭐ |
| Stack on Index | Compact identity cell without custom component | Detail-only | `Stack::make(...)` ⭐ |
| Line `subtitleFrom()` | Emit a second muted line from another attribute | Declare second Line manually | One-liner sugar ⭐ |
| Icon Picker Field | Phosphor icon picker with palette restriction | Not available | `Icon::make()` ⭐ |
| Slug Field | Live auto-generation + collision check + freeze-after-publish | Not available | `Slug::make()->freezeAfterPublish()` ⭐ |
| Timezone Field | Grouped IANA dropdown with live clock | Not available | `Timezone::make()` ⭐ |
| Badge Closures | Per-value closures for map/labels + per-row `resolveBadgeUsing()` | Static arrays only | `Badge::make()->labels(fn ($v) => ...)` ⭐ |
| Unsaved Changes Guard | Uniform dirty protection (drawers + pages + modals) | Not available | `Resource::confirmUnsavedChanges()` ⭐ |
| Standardised Button Classes | `.martis-btn-primary/secondary/danger/warning/filled` | Not available | CSS primitive ⭐ |
| Column Span Grid | colSpan/colSpanMd/colSpanLg for form layout | Separate plugin | Built-in |
| Component Key Override | Any field renders custom React component | Limited | `->component('key')` |
| Inline Create for BelongsTo | Create related record from dropdown | Modal only | Drawer + Modal |
| Rich Text Display | Custom component for HTML content | Trix only | Trix + Markdown + custom |

### Architecture Extensions

| Feature | Description | Nova v5 | Martis |
|---------|-------------|---------|--------|
| React 18 Frontend | Modern React with hooks, Suspense, concurrent | Vue 3 (Inertia) | React 18 + Router + Query |
| Open Source | MIT License | Proprietary ($199+/yr) | MIT License |
| 1,512 Phosphor Icons | Full icon library built-in | Heroicons subset | Phosphor Icons |
| Vite HMR | Hot module reload for development | Mix/Vite | Vite with HMR |
| Scramble API Docs | Auto-generated Swagger docs | — | Built-in |
| Self-Hosted CI/CD | GitHub Actions on local runner | — | Built-in |

### v0.8.0 / v0.9.0 Differentials

| Feature | Description | Nova v5 | Martis |
|---------|-------------|---------|--------|
| Sticky Views | Per-resource session storage of search / sort / filters / pagination / trashed-toggle / `filtersOpen`; survives back-navigation; drops on resource change | Not available | ⭐ Built-in |
| Cache Control Surface | `MartisCache::extend('name', enabled, ttl)` for host-app layers + `/martis/system/cache` admin page (toggle/version/clear per-type) | Not available | ⭐ Built-in |
| Per-Resource `notification()` builder | Declarative notification template per resource lifecycle event | Manual `Notification::send()` | ⭐ `notification()` builder |
| Closure-aware Field Setters | 13 setters accept `Closure` resolved at request time (`nullable`, `required`, `readonly`, `default`, `placeholder`, `help`, `tooltip`, `withLabel`, `rules`, `Select::options`, `MultiSelect::options`, `BooleanGroup::options`) | Limited closures | ⭐ Pervasive |
| Customisation Hooks `?Request` 4th arg | `resolveUsing` / `fillUsing` / `displayUsing` callbacks receive `?Request`; `displayUsing(array)` accepts a chainable transformation pipeline | 3-arg signature, single callable | ⭐ Extended |
| `immutable()` Field Flag | Field is writable on create, readonly on update (no need to duplicate fields per context) | Not available | ⭐ `Field::immutable()` |
| Save Variants | `Create & add another`, `Create & view list`, `Save & continue editing`, `Save & view list` | Not available | ⭐ 4 variants |
| Reset Filters Toolbar Button | Clears only the active filter set; coexists with `Reset view` | Single reset only | ⭐ Two-axis reset |
| `globallySearchable()` per-resource config | Accepts `bool\|array{enabled?, limit?, min_query?}` per resource | `bool` only | ⭐ Granular |
| `searchOrderBy()` Hook | Custom ordering applied AFTER the search filter | Not available | ⭐ Built-in |
| Locale Extensibility | Per-key deep merge of consumer overrides; configurable host-app namespaces (`martis.locales.app_namespaces`); configurable fallback chain (`martis.locales.fallback_chain`) | Lang publishing only | ⭐ Layered |
| SSO Provider Contract | Pluggable provider contract + identity-to-user resolver + role mapping (column / config / callable) + permission adapters (Spatie / native / callable) + idempotent `martis:sso <provider>` generator + `AzureProvider` reference impl | Auth Tools (paid add-on) | ⭐ Built-in MIT |
| Layout-flatten Audit | Every code path that iterates `Resource::fields()` raw flattens `Section` / `Panel` / `TabGroup` first (closes 5 latent bugs in `HasMany`, `MorphMany`, `Lens`, `sync-field`, `relatable` controllers) | n/a | ⭐ Hardened |
