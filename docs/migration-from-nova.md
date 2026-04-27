# Migrating from Laravel Nova v5

> Audience: developers with an existing Nova v5 application who are evaluating Martis as a replacement, partial replacement, or coexistence option.
> Scope: this guide covers what ports cleanly, what is renamed, what behaves differently on purpose, and what is intentionally not in Martis. It does not include automated codemod tooling — every migration we have run so far was a hand-port driven by this map.
> Companion documents: [PARITY_MAP.md](PARITY_MAP.md) for the feature-by-feature scorecard, and [differentials.md](differentials.md) for the things Martis ships that Nova does not.

## TL;DR

Martis is a different package. Nova resources do not load as-is. The two share a similar mental model — Resource classes, fields, filters, lenses, metrics, dashboards, actions — and the developer ergonomics map closely, but every namespace is different and the frontend is React (not Vue + Inertia). Plan for a port, not an upgrade.

What is *easy* to port:
- Resource skeletons (model binding, fields, filters, lenses, metrics, actions, search)
- Authorization (Policy resolution and per-method gates)
- Action contracts (`fields(Request)`, `handle(ActionFields, Collection)`)
- Filter / Lens / Metric / Dashboard hooks
- Soft-delete support, replication, preview, peek, inline relation create

What requires *real work*:
- Custom Nova components (Vue → React rewrite)
- Custom Nova fields (PHP → port + new TSX renderer)
- Custom Nova tools (no direct equivalent yet — see [§ Custom Tools](#custom-tools))
- Anything that relied on Nova-specific add-on packages (no marketplace; case-by-case)

What is *intentionally different*:
- The visual / interaction model (Martis design system, not Nova's)
- The frontend stack (React 18 + TanStack Query + PrimeReact, not Vue + Inertia)
- The override system (4-tier, drawer-aware) — Martis goes well beyond Nova-style customisation
- Several field APIs (closure-aware setters, `?Request` 4th argument, `displayUsing(array)` pipeline)

---

## Namespace map

The single biggest mechanical change is the root namespace. Replace `Laravel\Nova` with `Martis` everywhere, then check the resolver maps below.

| Nova v5 | Martis |
|---|---|
| `Laravel\Nova\Resource` | `Martis\Resource` |
| `Laravel\Nova\Fields\Field` | `Martis\Fields\Field` |
| `Laravel\Nova\Fields\Text` | `Martis\Fields\Text` |
| `Laravel\Nova\Fields\BelongsTo` | `Martis\Fields\BelongsTo` |
| `Laravel\Nova\Filters\Filter` | `Martis\Filters\Filter` |
| `Laravel\Nova\Lenses\Lens` | `Martis\Lenses\Lens` |
| `Laravel\Nova\Metrics\Value` | `Martis\Metrics\ValueMetric` |
| `Laravel\Nova\Metrics\Trend` | `Martis\Metrics\TrendMetric` |
| `Laravel\Nova\Metrics\Partition` | `Martis\Metrics\PartitionMetric` |
| `Laravel\Nova\Metrics\Progress` | `Martis\Metrics\ProgressMetric` |
| `Laravel\Nova\Dashboards\Dashboard` | `Martis\Dashboards\Dashboard` |
| `Laravel\Nova\Menu\MenuSection` | `Martis\Menu\MenuSection` |
| `Laravel\Nova\Menu\MenuItem` | `Martis\Menu\MenuItem` |
| `Laravel\Nova\Actions\Action` | `Martis\Actions\Action` |
| `Laravel\Nova\Actions\DestructiveAction` | `Martis\Actions\DestructiveAction` |
| `Laravel\Nova\Actions\ActionResponse` | `Martis\Actions\ActionResponse` |
| `Laravel\Nova\Http\Requests\NovaRequest` | `Illuminate\Http\Request` |
| `Laravel\Nova\Nova` (facade) | `Martis\Facades\Martis` |

Notable rename: Nova has `Value`, `Trend`, `Partition`, `Progress`. Martis suffixes them with `Metric` (`ValueMetric`, `TrendMetric`, ...) so the class name reads as the kind of card you are building.

`NovaRequest` becomes the standard `Illuminate\Http\Request`. Any custom logic that relied on `NovaRequest::resource()` should call `$request->route('resource')` instead, or use the `Martis::resourceFor($request)` helper.

---

## Resources

### Method-by-method map

| Nova v5 | Martis | Notes |
|---|---|---|
| `public static $model = User::class;` | `public static function model(): string { return User::class; }` | Method, not property. Allows runtime resolution. |
| `public static $title = 'name';` | `public static function titleAttribute(): string { return 'name'; }` | Method. |
| `public static $search = ['name', 'email'];` | Per-field `->searchable()` | Searchability lives on each field, not as a static array. |
| `public static $globallySearchable = true;` | `public static function globallySearchable(): bool\|array { ... }` | Accepts `true \| false \| array{enabled?, limit?, min_query?}` for per-resource config (v0.9). |
| `public function fields(NovaRequest $request)` | `public function fields(Request $request): array` | Same shape. Standard `Illuminate\Http\Request`. |
| `public function fieldsForIndex(NovaRequest)` | `public function fieldsForIndex(Request)` | Same. |
| `public function filters(NovaRequest)` | `public function filters(Request): array` | Returns `Filter[]`. |
| `public function lenses(NovaRequest)` | `public function lenses(Request): array` | Returns `Lens[]`. |
| `public function actions(NovaRequest)` | `public function actions(Request): array` | Returns `Action[]`. |
| `public function cards(NovaRequest)` | `public function cards(Request): array` | Returns metric cards / panels. |
| `public static $with = ['author'];` | Eager loading lives on the resource's `indexQuery()` / `relatableQuery()` | Use the query hooks. |
| `public static $perPageOptions = [10, 25, 50];` | `public static function perPageOptions(): array` | Method. The clamp helper `resolvedPerPage()` keeps the dropdown and the effective per-page in sync. |
| `public static $group = 'Content';` | `public static function group(): ?string { return 'Content'; }` | Method. |
| `public static $defaultsToGroup = 'Other';` | The same, on Martis side: configure via the menu builder | See [menus.md](menus.md). |
| `public function authorizedToView($request)` | `public function authorizedToView($request)` | Same. Full 4-level chain documented in [authorization.md](authorization.md). |

### What's the same

- The Resource class lifecycle is identical — Martis resolves a Resource per HTTP request, runs `fields()` for the right context, applies authorization, and serialises the result.
- `static::$policy` and convention-based policy discovery work the same way (`{namespace}\{Resource}Policy` is the default).
- Soft-delete support — Martis detects the trait on the model and exposes restore / force-delete UI automatically.
- Authorization gates (`authorizedToCreate`, `authorizedToUpdate`, ...) and policy-method names match Nova exactly.
- `before()` callback on Policies works as expected.

### What's different on purpose

- Resources expose data via methods, not properties. The Nova static-property style is replaced everywhere — including `$title`, `$search`, `$model`, `$group`, etc. This was a deliberate choice so the resource API is consistent with PHP 8 method visibility and gives every hook a `Request` to reason about.
- `globallySearchable()` accepts a per-resource `array` config (limit / min_query). Nova only allows `bool`.
- New `searchOrderBy(Builder, string)` hook (Martis-only) lets a resource boost prefix matches or apply custom ranking after the default search filter.

### What's missing

- `Resource::$preventFormAbandonment` — replaced by the Martis-wide `Resource::confirmUnsavedChanges()` flag (a uniform dirty-check across drawers + pages + modals). Default is the same: prompt before leaving.
- `Resource::$tableStyle = 'tight'` — Martis reads `Preferences::density()` user-side; per-resource override lives on the resource's `tableDensity()` method (see [resources.md](resources.md)).
- `Resource::$indexDefaultOrder` — use the standard `indexQuery(Request, Builder)` hook to apply ordering. The query builder is yours.

---

## Fields

### Field catalog — what maps directly

Every Nova v5 field has a Martis equivalent of the same name (`Text`, `Number`, `Email`, `Boolean`, `Date`, `DateTime`, `File`, `Image`, `Trix`, `Markdown`, `Code`, `Color`, `Country`, `Currency`, `KeyValue`, `Password`, `Select`, `MultiSelect`, `Slug`, `Status`, `Tag`, `Textarea`, `Url`, `Heading`, `Hidden`, `Id`, `Stack`, `Line`, `Sparkline`, `Avatar`, `Gravatar`, `BooleanGroup`, `Badge`).

Plus all 12 relation fields: `BelongsTo`, `HasOne`, `HasOneOfMany`, `HasOneThrough`, `HasMany`, `HasManyThrough`, `BelongsToMany`, `MorphTo`, `MorphOne`, `MorphOneOfMany`, `MorphMany`, `MorphToMany`.

Plus four fields that **do not exist in Nova**:
- `Icon` — Phosphor icon picker with palette restriction.
- `Slug` — live auto-generation + collision check + `freezeAfterPublish()`.
- `Timezone` — grouped IANA dropdown with a live clock.
- `Audio` — client-side waveform via Web Audio API + `downloadable()`.

### Field API map

| Nova v5 | Martis | Notes |
|---|---|---|
| `->required()` | `->required(bool\|Closure)` | Closure resolved at request time (v0.9). |
| `->nullable()` | `->nullable(bool\|Closure)` | Closure resolved at request time (v0.9). |
| `->readonly()` | `->readonly(bool\|Closure)` | Closure resolved at request time (v0.9). |
| `->default($v)` | `->default($v)` (`Closure` accepted) | Closure receives the request. |
| `->placeholder($v)` | `->placeholder(string\|Closure)` | Closure resolved at request time. |
| `->help($v)` | `->help(string\|Closure)` | Closure resolved at request time. |
| `->rules($r)` | `->rules(array\|Closure)` | Closure resolved at request time. |
| `->creationRules($r)` | `->creationRules(array)` | Same. |
| `->updateRules($r)` | `->updateRules(array)` | Same. |
| `->immutable()` | `->immutable(bool)` | Same intent (writable on create, ignored on update). |
| `->dependsOn(['attr'], fn)` | `->dependsOn(['attr'], ?Closure)` | Same shape; server-side resolution via `POST /api/resources/{r}/sync-field`. |
| `->displayUsing(fn)` | `->displayUsing(callable\|array)` | Array form ⭐ — chainable transformation pipeline. |
| `->resolveUsing(fn)` | `->resolveUsing(callable)` | Closure receives `?Request` as 4th argument. |
| `->fillUsing(fn)` | `->fillUsing(callable)` | Closure receives `?Request` as 4th argument. |
| `->showOnIndex()` etc. | Same | All four contexts supported. |
| `->onlyOnDetail()` etc. | Same | Same. |
| `->canSee(fn)` | `->canSee(fn)` + `->canSeeWhen(...)` | Both forms supported. |
| `->sortable()` | `->sortable()` | Same. |
| `->searchable()` | `->searchable()` | Same. |
| `->withMeta([...])` | Use `extraAttributes()` override on a custom field | Different surface — Martis prefers field subclassing. |

### Closure-aware setters

In Nova, most setters take literal values. In Martis (v0.9 onward) the following accept a `Closure` resolved at request time:

```
nullable, required, readonly, default, placeholder, help, tooltip,
withLabel, rules, Select::options, MultiSelect::options, BooleanGroup::options
```

This means UI-state that previously needed a `dependsOn` round-trip can frequently be handled directly. Example:

```php
// Nova-style — depends on a route parameter via dependsOn
Text::make('Discount')->dependsOn(['plan'], fn ($form, $r, $field) => $field->required($form['plan'] === 'pro'));

// Martis-style — closure resolved at request time
Text::make('Discount')->required(fn (Request $r) => $r->user()?->is_pro);
```

### `?Request` 4th argument on customisation hooks

`resolveUsing` / `fillUsing` / `displayUsing` callbacks receive `?Request` as a 4th argument. Existing 3-parameter callbacks keep working (PHP allows extra arguments).

### `displayUsing(array)` pipeline

```php
Text::make('amount')
    ->displayUsing([
        fn ($v) => (int) $v * 2,
        fn ($v) => "$ {$v}",
    ]);
// Index column shows "$ 10" when the model value is 5.
```

Each callable receives the output of the previous one. This replaces the Nova-style "wrap displayUsing in another displayUsing" anti-pattern.

---

## Filters

| Nova v5 | Martis | Notes |
|---|---|---|
| `Laravel\Nova\Filters\Filter` | `Martis\Filters\Filter` | Same base class. |
| `Laravel\Nova\Filters\BooleanFilter` | `Martis\Filters\BooleanFilter` | Same. |
| `Laravel\Nova\Filters\DateFilter` | `Martis\Filters\DateFilter` | Same. |
| `Laravel\Nova\Filters\SelectFilter` | `Martis\Filters\SelectFilter` | Same. |
| — | `Martis\Filters\DateRangeFilter` | Martis adds a range variant. |
| `apply(NovaRequest, Builder, $value)` | `apply(Request, Builder, $value): Builder` | Same contract. |
| `key()` (string) | `uriKey()` (string) | Renamed for symmetry with Lens / Action. |
| `name()` | `name()` | Same; localisable. |
| `default()` | `default()` | Same. |
| `options(NovaRequest)` | `options(Request): array` | Same. |

> **Watch out:** if a filter is referenced externally (lens default, deep link URL, E2E test) declare an explicit `uriKey()`. Localised names break the default kebab-case derivation.

---

## Lenses

| Nova v5 | Martis | Notes |
|---|---|---|
| `Laravel\Nova\Lenses\Lens` | `Martis\Lenses\Lens` | Same base class. |
| `query(LensRequest, Builder)` | `query(Request, Builder): Builder` | Same. |
| `fields(NovaRequest)` | `fields(Request): array` | Same. |
| `filters(NovaRequest)` | `filters(Request): array` | Same. |
| `cards(NovaRequest)` | `cards(Request): array` | Same. |
| `actions(NovaRequest)` | `actions(Request): array` | Same. |
| `name()` / `uriKey()` | Same | Same. |
| `canSee` / `canSeeWhen` | Same | Same. |
| — | `summary(): array` | ⭐ Martis-only — sticky summary row above the table. |
| — | `cacheFor(): ?int` | ⭐ Martis-only — per-lens query cache. |
| — | `withDefaultFilters([...])` | ⭐ Martis-only — pre-applied filters on first load. |

---

## Metrics

| Nova v5 | Martis | Notes |
|---|---|---|
| `Value` | `ValueMetric` | Renamed for clarity. |
| `Trend` | `TrendMetric` | Renamed. |
| `Partition` | `PartitionMetric` | Renamed. |
| `Progress` | `ProgressMetric` | Renamed. |
| `Range` enum on metric | `ranges()` method returning a labelled set | Same intent. |
| `count`, `sum`, `average`, `min`, `max` helpers | Same helpers on `ValueMetric` and `TrendMetric` | Same signatures. |
| `meta(['format' => '0.00a'])` | Property-style API on the result DTO (`->format(...)`) | Different surface; functionally equivalent. |
| `cacheFor()` | `cacheFor()` | Same. |
| `width()` | `width()` (snaps to a 12-column grid) | Same intent. |
| — | `polling()` + LIVE indicator | ⭐ Martis-only. |
| — | `color()` per-metric override | ⭐ Martis-only. |

---

## Dashboards

| Nova v5 | Martis | Notes |
|---|---|---|
| `Laravel\Nova\Dashboards\Dashboard` | `Martis\Dashboards\Dashboard` | Same. |
| `cards()` | `cards(Request): array` | Same. |
| `name()` / `uriKey()` | Same | Same. |
| Default dashboard | `Martis\Dashboards\DefaultDashboard` | Used as the fallback landing page. |
| — | Dashboard-level filters + refresh button | ⭐ Martis-only. |

Register dashboards in your `MartisServiceProvider`:

```php
Martis::dashboards([
    new \App\Martis\Dashboards\Operations(),
    new \App\Martis\Dashboards\Finance(),
]);
```

---

## Menus

Nova has both auto-generated grouped navigation and an explicit menu builder. Martis ships only the explicit menu builder, plus per-resource overrides.

```php
Martis::mainMenu(function (Request $request) {
    return [
        MenuSection::make('Content', [
            MenuItem::resource(Post::class),
            MenuItem::resource(Category::class),
        ])->icon('newspaper'),

        MenuSection::make('Reports', [
            MenuItem::link('Daily KPIs', '/martis/dashboards/operations'),
        ])->collapsable(),
    ];
});
```

Per-resource override:

```php
class PostResource extends Resource
{
    public function menuItem(Request $request): MenuItem
    {
        return parent::menuItem($request)->withBadge(static::model()::draft()->count());
    }
}
```

---

## Actions

| Nova v5 | Martis | Notes |
|---|---|---|
| `Action` / `DestructiveAction` | Same names | Same parent classes. |
| `fields(NovaRequest)` | `fields(Request): array` | Same. |
| `handle(ActionFields, Collection)` | `handle(ActionFields, Collection): ActionResponse` | Same. |
| `ActionResponse::message`, `danger`, `redirect`, `visit` | Same | Plus `emit(...)` and `modal(...)` (Martis-only). |
| `canSee(fn)` / `canRun(fn)` | Same | Same. |
| `showOnIndex` / `showInline` / `onlyOn*` / `exceptOn*` | All present | Same. |
| `standalone()` / `sole()` | Same | Same. |
| `withoutConfirmation()` | Same | Same. |
| `confirmText` / `confirmButtonText` / `cancelButtonText` | Same | Same. |
| `then(Closure)` | Same | Same — runs after the queued/inline execution. |
| Modal sizes | `ModalSize` enum (`sm`, `md`, `lg`, `xl`, `fullscreen`) | Enum-typed. |
| Action events table | `martis_action_events` | Renamed table. Use `withoutActionEvents()` to skip logging on a per-call basis. |
| `nova:action` artisan | `martis:action [--destructive]` | Same role. |
| — | `->icon('phosphor-name')` per action | ⭐ Martis-only. |
| — | `->group('Export.CSV')` dot-notation grouping | ⭐ Martis-only. |
| — | Disabled-state visual when `canRun` fails (Nova hides) | ⭐ Martis-only. |
| — | `->withDryRun()` | ⭐ Martis-only — preview the action's effect before committing. |

---

## Authorization

The Nova v5 policy contract is mirrored 1:1 — every method name (`viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`, `replicate`, `runAction`, `runDestructiveAction`, `add{Model}`, `attach{Model}`, `attachAny{Model}`, `detach{Model}`) lives in Martis with the same signature.

Field-level authorization:

```php
Text::make('Internal Notes')
    ->canSee(fn (Request $r) => $r->user()?->isAdmin())
    ->canSeeWhen('view-internal-notes');
```

`_authorization` metadata appears in API responses so the React frontend can render disabled / hidden buttons without an extra round-trip — same idea Nova has, same wire format.

---

## Forms / CRUD UX

What's the same:
- Create / Update / Detail flows resolve the same hooks and respect the same authorization model.
- Inline create on `BelongsTo` / `MorphTo`.
- Soft-delete restore + force-delete UI.
- Replication.
- Preview drawer.
- Peek (hover-card popover) for related records.
- Bulk select + bulk actions.

What's different:
- **Save variants (v0.9)** — `Create & add another`, `Create & view list`, `Save & continue editing`, `Save & view list`. Nova has only the first two; the latter pair stays on the edit page (with the unsaved-changes guard reset) or returns to the index.
- **Reset filters (v0.9)** — toolbar button clears only the active filter set; coexists with `Reset view`.
- **Sticky views (v0.8)** — search / sort / filters / pagination / trashed / `filtersOpen` are persisted per-resource in session storage. Survives back-navigation. Drops on resource change. Nova does not persist this state.
- **Drawer overrides** — Create / Update / Detail can be rendered as side-drawers instead of full pages. See [overrides.md](overrides.md).
- **Modal history locks** — hard + soft variants in `resources/js/lib/historyLock.ts`. Browser back closes the modal cleanly.

### Save-variant URLs

The frontend dispatches the variant via the `_save_variant` form field. Backend treats it as a no-op; the redirect destination is computed client-side. If you have a custom CRUD controller, copy the dispatch from `resources/js/pages/ResourceCreate.tsx` and `ResourceUpdate.tsx`.

---

## Global Search

| Nova v5 | Martis | Notes |
|---|---|---|
| `static::$globallySearchable = true` | `globallySearchable(): bool\|array` (method) | Per-resource `array{enabled?, limit?, min_query?}` — v0.9 differential. |
| Resource-wide search columns | `->searchable()` per field | Per-field, not per-resource array. |
| — | `searchOrderBy(Builder, string)` | ⭐ Martis-only — applied AFTER the search filter. |
| — | "View all N matches in {resource}" overflow item | ⭐ Martis-only — palette footer that lands on the resource index with `?search=` pre-applied. |

Config keys live under `martis.search` (`default_limit`, `min_query`, ...). See [global-search.md](global-search.md).

---

## In-app Notifications

Martis ships an in-app bell on top of Laravel's standard `notifications` table. Anything that delivers via the `database` channel automatically surfaces. There is **no `MartisNotifies` trait** — the recipient just needs Laravel's standard `Notifiable`.

```php
$user->notify(MartisNotification::make(
    title: 'Invoice paid',
    message: 'INV-2026-001 has been paid.',
    level: 'success',
));
```

The bell consumes the recommended payload shape (`title`, `message`, `level`, `icon`, `action_url`, `action_label`); only `title` is required. See [notifications.md](notifications.md).

> **Coming from Nova:** Nova v5 does not ship a comparable in-app notification surface. This is a Martis differential.

---

## Authentication and SSO

What ports cleanly:
- Login / logout flows over Sanctum sessions.
- `/login` page with branding hooks.
- 2FA challenge flow (TOTP + recovery codes).

What's new:
- **SSO subsystem (v0.9)** — pluggable provider contract (`SsoProviderContract`), reference `AzureProvider`, identity-to-user resolver, role mapping (column / config / callable), permission adapters (Spatie / native / callable), idempotent `martis:sso <provider>` generator. See [sso.md](sso.md).

```php
MartisSso::extend('okta', App\Sso\OktaProvider::class);

MartisSso::resolveRolesUsing(function (SsoIdentity $identity, string $provider) {
    return $identity->groups; // map your IdP claims to local roles
});
```

> **Coming from Nova:** Nova v5 does not ship SSO out of the box; teams typically build it via `socialite` + a custom controller. Martis bakes the wiring in.

---

## Locale / i18n

| Nova v5 | Martis | Notes |
|---|---|---|
| `php artisan vendor:publish --tag=nova-lang` | `php artisan vendor:publish --tag=martis-lang` | Same workflow. |
| Override a single key by re-publishing the whole namespace | **Per-key deep merge** (v0.9) | Consumer overrides only need to ship the keys they want to change. |
| — | `martis.locales.app_namespaces` | ⭐ Martis-only — surface host-app translation files under their namespace key. |
| — | `martis.locales.fallback_chain` | ⭐ Martis-only — configurable fallback chain (`['pt_PT', 'pt_BR', 'en']`). |

See [i18n.md](i18n.md).

---

## Cache control

Nova does not expose a per-subsystem cache toggle. Martis ships `MartisCache` — a central service for the four built-in cache types (`metrics`, `navigation`, `dashboards`, `schema`) plus any custom layer registered via:

```php
// In a service provider
MartisCache::extend('reports', enabled: true, ttl: 300);
```

The admin UI lives at `/martis/system/cache` (toggle / version / clear per-type, runtime overrides survive deploys). Per-request bypass via `X-Martis-No-Cache: 1` header or `?nocache=1` query. See [cache.md](cache.md).

---

## What is *not* in Martis

These are intentional gaps as of v0.9.0-beta. Some are on the post-1.0 backlog; some are unlikely to ship. Check [PARITY_MAP.md § Top Critical Gaps](../tasks/00_hubs/NOVA5_COMPATIBILITY_AUDIT.md#top-critical-gaps) for status.

- **Impersonation** — no first-class admin impersonation surface yet (post-1.0).
- **Custom Tools (sidebar pages)** — workaround: drawer overrides + custom routes; native primitive on the post-1.0 backlog.
- **Nova marketplace add-ons** — no equivalent ecosystem. Each integration must be ported manually or rebuilt; many do not need a port because Martis ships the equivalent built-in (theming, profile, 2FA, SSO, layout presets).
- **Nova's exact visual language** — Martis uses its own design system. The Visual UX parity sweep (v0.8) closed the worst regressions but Nova-clone is not the goal. Plan for a UX shift.

---

## Custom Tools

If your Nova app has a custom Tool (sidebar page rendered as a Vue component), the path forward in Martis today is:

1. Register a Laravel route (`routes/web.php` in your app).
2. Render a React page through the Martis layout — either a full page or a drawer override.
3. Surface it in the menu via `Martis::mainMenu(...)`.

A first-class `Tool` primitive is on the backlog. Track in [PARITY_MAP.md](PARITY_MAP.md).

---

## Migration cookbook

### 1. Bootstrap

```bash
composer require martis/martis
php artisan martis:install --with-profile
npm run build
```

### 2. Port a Resource

Start with `php artisan martis:resource Post`. Then:

1. Replace `Laravel\Nova\Resource` with `Martis\Resource`.
2. Convert static properties (`$model`, `$title`, ...) to methods.
3. Replace `NovaRequest` typehints with `Illuminate\Http\Request`.
4. Replace each `Laravel\Nova\Fields\*` import with the matching `Martis\Fields\*`.
5. For each filter, lens, metric — port the class, then update the `filters()` / `lenses()` / `cards()` methods on the resource.
6. Run `php artisan martis:install --force` to re-publish the asset bundle.

### 3. Port an Action

```bash
php artisan martis:action SendInvoice
```

Copy the `handle()` body. Confirm the `ActionResponse` shape — `message`, `danger`, `redirect`, `visit` all map 1:1; if you used Nova-specific `Action::download()` or `Action::openInNewTab()`, swap to `ActionResponse::redirect()` with the `target` flag.

### 4. Port a custom field

This is where most of the hand-port work lives. Nova fields ship a Vue renderer; Martis expects a TSX component:

1. `php artisan martis:field MyField` — generates the PHP class + a TSX stub.
2. Port the PHP class (signature + serialisation) — most of it carries over.
3. Re-implement the renderer in React. PrimeReact components cover most input shapes (`InputText`, `Calendar`, `Dropdown`, `MultiSelect`, ...).
4. Register the component key in `boot.ts` (the install command sets up the registry).

### 5. Port a Policy

No port needed in most cases — Laravel Policies are Laravel Policies. Make sure your namespace convention matches `App\Policies\{Model}Policy` so Martis auto-discovery picks it up. Otherwise, set `static $policy` explicitly on the Resource.

### 6. Port translations

If you have published `vendor/laravel/nova/resources/lang/*` files, the matching Martis lang files live under `resources/lang/vendor/martis/{locale}/*`. Per-key deep merge (v0.9) means you only need to ship the keys you want to override; the rest fall back to the package defaults.

---

## Verification checklist

After the port, walk through this sanity list before going live:

- [ ] Every Resource appears in the navigation.
- [ ] Every CRUD context renders for each Resource (Index / Detail / Create / Edit).
- [ ] Filters apply, reset, and survive a page reload via sticky views.
- [ ] Lenses render with the right summary / filters / actions.
- [ ] Metrics + Dashboards render and refresh.
- [ ] Bulk actions, inline actions, and destructive actions work and respect policy.
- [ ] Authorization gates hide the right buttons (compare against `_authorization` metadata in the API response).
- [ ] Soft-delete trashed dropdown works on relation panels.
- [ ] Locale switching works across every page (incl. 2FA challenge and login).
- [ ] Cache control surface — toggle each cache type, watch the page recompute.
- [ ] If SSO is configured, the round-trip works and `MartisSso::afterLogin()` fires once.

---

## Where to ask

- Bugs / parity gaps: open an issue on the `martis-package` repo.
- Architecture questions: see [architecture/decisions.md](architecture/decisions.md) for the ADRs.
- Status: [PARITY_MAP.md](PARITY_MAP.md) is updated per release.
