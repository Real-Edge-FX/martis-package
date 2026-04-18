# Parity Map — Martis vs Laravel Nova v5

> Last updated: 2026-04-07
> Coverage: Track B Foundation (Blocks 1-10) + Extended Fields + HasMany

## Legend

| Status | Meaning |
|--------|---------|
| DONE | Implemented and tested |
| PARTIAL | Partially implemented |
| TODO | Not yet implemented |

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

## Fields (41 Types)

| Field | Nova v5 | Martis | Status | Notes |
|-------|---------|--------|--------|-------|
| Text | Text field | Text::make() | DONE | Block 4 |
| Textarea | Textarea | Textarea::make() + rows() | DONE | Block 4 |
| Number | Number field | Number::make() + min/max/step | DONE | Block 4 |
| Boolean | Boolean/Toggle | Boolean::make() + trueLabel/falseLabel | DONE | Block 4 |
| Select | Select dropdown | Select::make() + options() | DONE | Block 4 |
| Date | Date picker | Date::make() | DONE | Block 4 |
| DateTime | Date + time picker | DateTime::make() | DONE | — |
| BelongsTo | Belongs To | BelongsTo::make() + relatable endpoint | DONE | Block 4+8 |
| HasMany | Has Many | HasMany::make() + inline CRUD | DONE | Inline DataTable |
| Email | Email field | Email::make() + format validation | DONE | — |
| Password | Password field | Password::make() + masked | DONE | — |
| ID | ID display | Id::make() | DONE | — |
| Hidden | Hidden field | Hidden::make() | DONE | — |
| Heading | Section divider | Heading::make() + content() | DONE | — |
| File | File upload | File::make() + disk/path/types/maxSize | DONE | — |
| Image | Image upload | Image::make() + thumbnail/disk | DONE | — |
| Badge | Badge display | Badge::make() + colors + closure maps/labels + resolveBadgeUsing() | DONE | ⭐ Extended |
| Status | Status badge | Status::make() + colors | DONE | Extended |
| Code | Code editor | Code::make() | DONE | Extended |
| Color | Color picker | Color::make() | DONE | Extended |
| Country | Country selector | Country::make() | DONE | Extended |
| Currency | Currency display | Currency::make() + symbol | DONE | Extended |
| Gravatar | Gravatar avatar | Gravatar::make() | DONE | Extended |
| KeyValue | Key-value pairs | KeyValue::make() | DONE | Extended |
| Markdown | Markdown editor | Markdown::make() | DONE | Extended |
| MultiSelect | Multi-select dropdown | MultiSelect::make() | DONE | Extended |
| Sparkline | Mini chart | Sparkline::make() | DONE | Extended |
| Tag | Tag input | Tag::make() | DONE | Extended |
| Trix | Rich text editor | Trix::make() | DONE | Extended |
| Url | URL with validation | Url::make() | DONE | Extended |
| Slug | — | Slug::make() + live collision check + freezeAfterPublish | DONE | ⭐ Extended |
| PasswordConfirmation | Password confirmation | PasswordConfirmation::make() + live match | DONE | — |
| Timezone | — | Timezone::make() + grouped dropdown + live clock | DONE | ⭐ Extended |
| Icon | — | Icon::make() + Phosphor picker / palette / colorFrom | DONE | ⭐ 100% Martis |
| Stack | Stack (detail-only) | Stack::make() + renders on index + divider() | DONE | ⭐ Extended |
| Line | Line | Line::make() + asHeading/asSmall/asMuted/asCode + subtitleFrom() | DONE | ⭐ Extended |
| BooleanGroup | BooleanGroup | BooleanGroup::make() + grouped() + minChecked/maxChecked + requireAny/All | DONE | ⭐ Extended |
| Avatar | Avatar (Image subclass) | Avatar::make() + per-row fallback(Closure) + AvatarShape enum | DONE | ⭐ Extended |
| UiAvatar | UiAvatar | UiAvatar::make() + deterministic palette + colorFrom() + initials(Closure) | DONE | ⭐ Extended |
| Audio | Audio | Audio::make() + client-side waveform + downloadable() | DONE | ⭐ Extended |
| Field Visibility | showOnIndex/hideFromIndex | All 4 contexts supported | DONE | Block 4 |
| Field Validation | Built-in validation | required/nullable/rules() | DONE | Block 4 |
| Field Sorting | Sortable columns | sortable() | DONE | Block 4 |
| Field Search | Searchable fields | searchable() | DONE | Block 4 |
| Field Component Override | Custom component key | ->component('key') | DONE | Block 9 |
| Field Unique Validation | Unique validation | ->unique(['table','col'],'msg') | DONE | — |
| Column Span | Grid layout | colSpan/colSpanMd/colSpanLg | DONE | Extended |
| Placeholder | Input placeholder | placeholder() | DONE | Extended |

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

## Not Yet Implemented (Post Track B)

| Feature | Nova v5 | Status | Priority |
|---------|---------|--------|----------|
| Actions | Batch actions on records | DONE |: Full system + icons, groups, tooltips, pivot, disabled states |
| Filters | Column filters | DONE | Full system: Select, Boolean, Date, DateRange + collapsible panel |
| Lenses | Custom filtered views | TODO | Medium |
| Metrics | Dashboard metric cards | DONE | Full system: Value, Trend, Partition, Progress + dashboards + filters + polling |
| Impersonation | Admin impersonation | TODO | Low |
| Notifications | In-app notifications | TODO | Medium |
| Panels/Tabs | Field grouping in tabs | DONE | Panel, Section, Tab, TabGroup + description + help text |
| Custom Tools | Sidebar tools/pages | TODO | Medium |
| ManyToMany | Pivot relationships | DONE | — |
| BelongsToMany | Pivot with sync | DONE | — |
| MorphTo/MorphMany | Polymorphic relations | TODO | Low |
| Repeater | Dynamic field groups | TODO | Low |

---

## Coverage Summary

- **32 field types** implemented (15 core + 16 extended)
- **Full CRUD** — Index + Detail + Create + Edit + Delete with soft-delete support
- **HasMany** — Inline DataTable on detail page with search, sort, pagination, CRUD
- **Auth** — Login/logout + Sanctum session
- **Override System v1** — 4-tier component resolution + drawer overrides + layout overrides
- **i18n** — EN + PT-BR + PT-PT with dynamic loading
- **File/Image Upload** — Disk config, thumbnails, drag-drop, type/size validation
- **Icons** — 1,512 Phosphor Icons
- **Test Coverage** — 180+ PHP + 43 TS + 13 E2E = **236+ tests**
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
| Action Log | action_events table | DB logging + withoutActionEvents | DONE | — |
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
| 26 Extended Field Types | Badge, Status, Code, Color, Country, Currency, Icon⭐, Slug⭐, Stack⭐, Timezone⭐, Audio⭐, Avatar⭐, BooleanGroup⭐, UiAvatar⭐, etc. | Separate packages | Built-in |
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
