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
| Actions | Batch actions on records | TODO | High |
| Filters | Column filters | TODO | High |
| Lenses | Custom filtered views | TODO | Medium |
| Metrics | Dashboard metric cards | TODO | High |
| Impersonation | Admin impersonation | TODO | Low |
| Notifications | In-app notifications | TODO | Medium |
| Panels/Tabs | Field grouping in tabs | TODO | Medium |
| Custom Tools | Sidebar tools/pages | TODO | Medium |
| ManyToMany | Pivot relationships | TODO | High |
| BelongsToMany | Pivot with sync | TODO | High |
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
