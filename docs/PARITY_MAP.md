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
| Resource Groups | Sidebar grouping | group() method | DONE | REA-1140 |
| Resource Subtitle | Description line | subtitle() static method | DONE | REA-1140 |
| Resource Title | Dynamic record title | titleAttribute() | DONE | REA-1140 |
| Resource Icons | Sidebar icons | icon() + Phosphor Icons | DONE | REA-1140 |
| Custom Messages | CRUD notification messages | createdMessage() etc | DONE | REA-1140 |
| Contracts/Interfaces | — | FieldContract, ResourceContract | DONE | Extensibility |

---

## Fields (31 Types)

| Field | Nova v5 | Martis | Status | Notes |
|-------|---------|--------|--------|-------|
| Text | Text field | Text::make() | DONE | Block 4 |
| Textarea | Textarea | Textarea::make() + rows() | DONE | Block 4 |
| Number | Number field | Number::make() + min/max/step | DONE | Block 4 |
| Boolean | Boolean/Toggle | Boolean::make() + trueLabel/falseLabel | DONE | Block 4 |
| Select | Select dropdown | Select::make() + options() | DONE | Block 4 |
| Date | Date picker | Date::make() | DONE | Block 4 |
| DateTime | Date + time picker | DateTime::make() | DONE | REA-1140 |
| BelongsTo | Belongs To | BelongsTo::make() + relatable endpoint | DONE | Block 4+8 |
| HasMany | Has Many | HasMany::make() + inline CRUD | DONE | Inline DataTable |
| Email | Email field | Email::make() + format validation | DONE | REA-1140 |
| Password | Password field | Password::make() + masked | DONE | REA-1140 |
| ID | ID display | Id::make() | DONE | REA-1140 |
| Hidden | Hidden field | Hidden::make() | DONE | REA-1140 |
| Heading | Section divider | Heading::make() + content() | DONE | REA-1140 |
| File | File upload | File::make() + disk/path/types/maxSize | DONE | REA-1140 |
| Image | Image upload | Image::make() + thumbnail/disk | DONE | REA-1140 |
| Badge | Badge display | Badge::make() + colors | DONE | Extended |
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
| Field Visibility | showOnIndex/hideFromIndex | All 4 contexts supported | DONE | Block 4 |
| Field Validation | Built-in validation | required/nullable/rules() | DONE | Block 4 |
| Field Sorting | Sortable columns | sortable() | DONE | Block 4 |
| Field Search | Searchable fields | searchable() | DONE | Block 4 |
| Field Component Override | Custom component key | ->component('key') | DONE | Block 9 |
| Field Unique Validation | Unique validation | ->unique(['table','col'],'msg') | DONE | REA-1140 |
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
| File Upload | Multipart form | FormData with file detection | DONE | REA-1140 |

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
| i18n | Multiple languages | react-i18next + API translations | DONE | REA-1094 |
| Dark/Light Theme | — | ThemeContext + CSS variables | DONE | Full support |
| Phosphor Icons | — | 1,512 icons via icon() | DONE | REA-1140 |
| File/Image Display | — | Upload preview + thumbnail | DONE | REA-1140 |
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
| Actions | Batch actions on records | DONE | REA-1102: Full system + icons, groups, tooltips, pivot, disabled states |
| Filters | Column filters | TODO | High |
| Lenses | Custom filtered views | TODO | Medium |
| Metrics | Dashboard metric cards | TODO | High |
| Impersonation | Admin impersonation | TODO | Low |
| Notifications | In-app notifications | TODO | Medium |
| Panels/Tabs | Field grouping in tabs | TODO | Medium |
| Custom Tools | Sidebar tools/pages | TODO | Medium |
| ManyToMany | Pivot relationships | PARTIAL | High |
| BelongsToMany | Pivot with sync | PARTIAL | High |
| MorphTo/MorphMany | Polymorphic relations | TODO | Low |
| Repeater | Dynamic field groups | TODO | Low |

---

## Coverage Summary

- **31 field types** implemented (15 core + 16 extended)
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

## Authorization (Nova v5 Parity — REA-1115)

| Feature | Nova v5 | Martis | Status | Notes |
|---------|---------|--------|--------|-------|
| Policy Resolution | Gate + model policy | Explicit + auto-discovery + Gate | DONE | 4-level chain |
| Resource Policy | static $policy | static $policy property | DONE | REA-1115 |
| Auto-Discovery | Convention-based | {namespace}\{Resource}Policy | DONE | REA-1115 |
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
| authorizable() | Disable auth per resource | authorizable() | DONE | REA-1115 |
| Field canSee | Field visibility callback | canSee() + canSeeWhen() | DONE | REA-1115 |
| before() callback | Pre-check in policy | before() support | DONE | REA-1115 |
| Auth Metadata | _authorization in responses | authorizationMetadata() | DONE | REA-1115 |
| Frontend Enforcement | Button visibility | _authorization consumed | DONE | REA-1115 |
| Policy Defaults | Missing method behavior | Nova v5 compatible matrix | DONE | REA-1115 |
| Policy Generator | make:policy | martis:make-policy | DONE | Custom stub |


---

## Actions System (Nova v5 Parity — REA-1102)

| Feature | Nova v5 | Martis | Status | Notes |
|---------|---------|--------|--------|-------|
| Action Base Class | Action | Action::make() | DONE | Full parity |
| DestructiveAction | DestructiveAction | DestructiveAction::make() | DONE | Red UI + confirm |
| handle() Contract | ActionFields + Collection | Same contract | DONE | REA-1102 |
| Action Fields | fields(Request) | Same contract + Field reuse | DONE | REA-1102 |
| ActionResponse | message/danger/redirect/visit | Full parity + emit/modal | DONE | REA-1102 |
| Action Registration | actions(Request) | Same contract | DONE | REA-1102 |
| canSee | Visibility callback | canSee(Closure) | DONE | REA-1102 |
| canRun | Per-model authorization | canRun(Closure) | DONE | REA-1102 |
| Policy Fallback | runAction/runDestructiveAction | 4-level chain | DONE | REA-1102 |
| showOnIndex | Index visibility | showOnIndex() | DONE | REA-1102 |
| showOnDetail | Detail visibility | showOnDetail() | DONE | REA-1102 |
| showInline | Per-row buttons | showInline() | DONE | REA-1102 |
| onlyOnIndex | Index only | onlyOnIndex() | DONE | REA-1102 |
| onlyOnDetail | Detail only | onlyOnDetail() | DONE | REA-1102 |
| onlyInline | Inline only | onlyInline() | DONE | REA-1102 |
| exceptOnIndex | Hide from index | exceptOnIndex() | DONE | REA-1102 |
| exceptOnDetail | Hide from detail | exceptOnDetail() | DONE | REA-1102 |
| exceptInline | Hide from inline | exceptInline() | DONE | REA-1102 |
| standalone() | No models required | standalone() | DONE | REA-1102 |
| sole() | Exactly one model | sole() | DONE | REA-1102 |
| Closure Actions | Action::using() | Action::using(name, fn) | DONE | REA-1102 |
| Queued Actions | ShouldQueue | ShouldQueue trait | DONE | REA-1102 |
| Queue Customization | connection/queue | Property-based config | DONE | REA-1102 |
| Action Log | action_events table | DB logging + withoutActionEvents | DONE | REA-1102 |
| markAsFinished | Queued status | markAsFinished($model) | DONE | REA-1102 |
| markAsFailed | Queued error | markAsFailed($model, $e) | DONE | REA-1102 |
| Confirm Text | confirmText() | confirmText() | DONE | REA-1102 |
| Confirm Button | confirmButtonText() | confirmButtonText() | DONE | REA-1102 |
| Cancel Button | cancelButtonText() | cancelButtonText() | DONE | REA-1102 |
| Without Confirm | withoutConfirmation() | withoutConfirmation() | DONE | REA-1102 |
| Modal Sizes | sm/md/lg/.../fullscreen | ModalSize enum + fullscreen | DONE | REA-1102 |
| Pivot Actions | pivotAction() | pivotAction() + referToPivotAs() | DONE | REA-1102 |
| then() Callback | Post-process callback | then(Closure) | DONE | REA-1102 |
| Action Icons | — | icon('phosphor-name') | DONE | Martis extension |
| Action Groups | — | group('name') + dot-notation | DONE | Martis extension |
| Artisan Command | nova:action | martis:action (--destructive) | DONE | REA-1102 |
| Inline Multi-select | Bulk select | Checkbox column | DONE | REA-1102 |
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
| 16 Extended Field Types | Badge, Status, Code, Color, Country, Currency, etc. | Separate packages | Built-in |
| Column Span Grid | colSpan/colSpanMd/colSpanLg for form layout | Separate plugin | Built-in |
| Component Key Override | Any field renders custom React component | Limited | `->component('key')` |
| Inline Create for BelongsTo | Create related record from dropdown | Modal only | Drawer + Modal |
| Rich Text Display | Custom component for HTML content | Trix only | Trix + Markdown + custom |

### Architecture Extensions

| Feature | Description | Nova v5 | Martis |
|---------|-------------|---------|--------|
| React 19 Frontend | Modern React with hooks, Suspense, concurrent | Vue 3 (Inertia) | React 19 + Router + Query |
| Open Source | MIT License | Proprietary ($199+/yr) | MIT License |
| 1,512 Phosphor Icons | Full icon library built-in | Heroicons subset | Phosphor Icons |
| Vite HMR | Hot module reload for development | Mix/Vite | Vite with HMR |
| Scramble API Docs | Auto-generated Swagger docs | — | Built-in |
| Self-Hosted CI/CD | GitHub Actions on local runner | — | Built-in |
