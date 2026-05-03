# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.8.15] — 2026-05-03

### Added

- **`martis:roles --promote=<email>` flag** to assign the freshly-seeded `admin` role to a user in the same command. Pass an email (case-insensitive exact match) or the literal `first` to grab the lowest-id user — handy for fresh installs where there's exactly one account. Without the flag the command keeps the previous "promote yourself manually" hint, but the operator no longer has to drop into tinker for the most common case.
- **`martis:roles` now auto-runs `MartisRolesSeeder`** so the `admin` role exists immediately. Idempotent (`firstOrCreate` in the seeder), opt out with `--no-seed` for CI / scripted setups that handle seeding separately.

### Fixed

- **Restored the `safeUserArray()` docblock** dropped accidentally during the v1.8.14 patch. PHPStan flagged the missing `@return array<string, mixed>` annotation. CI is green again.

## [1.8.14] — 2026-05-03

### Changed

- **Login + user-bootstrap endpoints now gate on email verification.** Previously `POST /api/auth/login` returned the full user payload regardless of verification status, the SPA navigated to `/martis/`, and the dashboard would only redirect on the next API 409. To users it read as "I logged in without verifying." Now, when `martis.auth.email_verification.enabled=true` AND the user has not confirmed their email (column null on the standard table OR `hasVerifiedEmail()=false` for a `MustVerifyEmail` model), `POST /api/auth/login` returns `200 { email_verification_required: true }` and `GET /api/auth/user` returns `200 { email_verification_pending: true }`. The session is still established so the resend-link endpoint behind `auth:` keeps working — only the post-login destination changes. The SPA `AuthContext` raises `EmailVerificationRequiredError` (mirroring `TwoFactorRequiredError`); `Login.tsx` catches it and routes to `/email/verify`. The bootstrap branch in `useEffect` mirrors the existing `two_factor_pending` redirect for refresh / deep-link reload. Resolution chain matches `EnsureEmailIsVerified` so login, bootstrap, and middleware never disagree.

## [1.8.13] — 2026-05-03

### Fixed

- **Registration crashed with `Route [verification.verify] not defined` when the consumer's `User` model implemented `MustVerifyEmail`.** Laravel's bundled `VerifyEmail` notification builds its signed URL via `route('verification.verify', ...)`, but Martis registers the verification route under `martis.email.verify`. Registration would 500 before the verification email could be sent. Fixed by overriding `VerifyEmail::createUrlUsing()` from the service provider, mirroring the precedent set in v1.8.3 for `ResetPassword`. The override is gated on `martis.auth.email_verification.enabled` and probes the static `createUrlCallback` reflection slot so consumer-side callbacks are respected.
- **`EmailVerifyNoticePage` rendered the literal string `{email}` instead of the user's address.** The `verify_sub` default value used i18next single-brace syntax, but the i18n instance is configured with the default `{{var}}` interpolation. The placeholder is now `{{email}}`, so the verify notice page reads "We sent a verification link to admin@example.com." correctly.
- **SPA dashboard rendered the empty shell for unverified users.** `EnsureEmailIsVerified` redirects HTML requests to `/email/verify` and returns `409 { message: "Your email address is not verified." }` for `Accept: application/json`. After login, the SPA navigates client-side and never re-hits the middleware, so the dashboard mounted with every API call returning 409 and no actionable redirect. The shared `request()` interceptor now matches that 409 envelope and forces `window.location` to `/email/verify`, mirroring the existing 401 → `/login?expired=1` behaviour.

## [1.8.12] — 2026-05-03

### Fixed

- **`brandColor` (custom hex accent) now drives the full 6-token palette.** When a user's preferences declared `brandColor`, only `--martis-accent` was overridden; `--martis-accent-hover`, `-active`, `-bg-light`, `-bg` and `--martis-focus-ring` kept inheriting the bundled named-accent defaults. Most visible regression: a button with a teal `--martis-accent` flashed bundled-blue on hover. The fix derives the five remaining tokens via `color-mix(in srgb, ...)` — the same chain the SSR rule emits for `MARTIS_CUSTOM_ACCENTS`. Applied in both the pre-paint bootstrap (`app.blade.php`) so the FOUC stays clean, and `PreferencesContext::applyToDom()` so runtime accent changes stay consistent. Clearing `brandColor` removes all six overrides, falling cleanly back to the data-accent-driven palette.


## [1.8.11] — 2026-05-03

### Fixed

- **Consumer translation overrides loaded on Laravel 11+.** The `TranslationsController` looked for published vendor overrides under `resource_path("lang/vendor/martis/<locale>/")` only, missing the new top-level `lang/` directory that ships with Laravel 11 and up. Every host on Laravel 11 / 12 / 13 was silently ignoring its `lang/vendor/martis/<locale>/` overrides — the API kept returning the package's bundled English defaults. Now probes `lang_path()` first, falls back to `resource_path()`, matching Laravel's own translator priority. Three call sites patched: layer-4 consumer override, host-app namespace lookup, validation message lookup.

## [1.8.10] — 2026-05-03

### Fixed

- **Register page no longer prompts "Leave blank to keep current" under the Password input.** `PasswordField` defaulted its placeholder to the profile-edit hint whenever the consumer didn't pass one. On registration the field is `required:true` and an empty value is invalid, so the copy was actively misleading. Now: `required` → empty placeholder; optional edit flows keep the original copy.
- **Spatie role/permission listeners register on Laravel 11.** Spatie renamed the events between v6 (`RoleAttached`) and v7 (`RoleAttachedEvent`); Laravel 11 forces v6.x, so the package was silently failing to wire the audit trail. The provider now probes both class names per event and registers a listener on whichever variant is installed. Test suite resolves the FQN via `class_exists()` so it covers both majors.

### Changed

- **CI hygiene.** Pint applied 21 auto-fixes accumulated during the v1.8.x cycle; PHPStan baseline regenerated to cover residual warnings; one real omission fixed (`Models\\CacheState::$casts` had no value-type annotation).
- **Documentation.** `docs/authentication.md` documents the three mailer states when `MARTIS_AUTH_EMAIL_VERIFICATION_ENABLED=true` (`MAIL_MAILER=log` in dev → link in `laravel.log`; configured + reachable → standard flow; configured but broken → registration 500s by design — fix the mailer).

## [1.8.9] — 2026-05-03

### Security

- **Closed an email-verification bypass.** Hosts running with `MARTIS_AUTH_EMAIL_VERIFICATION_ENABLED=true` on a standard Laravel users table (column `email_verified_at` present, but `App\\Models\\User` not declared as `implements MustVerifyEmail`) let registered users log in and reach every protected Martis route without ever verifying their email. The `martis.verified` middleware used `$user instanceof MustVerifyEmail` as the gate; the standard Laravel User model has the column from the default migration but doesn't ship the trait — opting in was easy to forget, and forgetting silently turned the flag into a no-op.

### Fixed

- **`EnsureEmailIsVerified` falls back to the `email_verified_at` attribute** when the User does not implement `MustVerifyEmail`. Column present + null → blocked. Column present + populated → allowed. Column missing → blocked (fail-safe). Standard Laravel installs are now gated correctly out of the box.
- **`DefaultRegistersUsers` writes a clear warning to `laravel.log`** when the verification flag is on AND the User model does not implement `MustVerifyEmail`. Surfaces the misconfiguration on the first signup so the dev sees why the verification email never arrived.

### Tests

- Two new regression specs in `tests/Feature/EmailVerificationFeatureTest.php` lock the column-fallback path: unverified non-`MustVerifyEmail` user redirects to `/email/verify`; verified non-`MustVerifyEmail` user passes through.

## [1.8.8] — 2026-05-03

The big v1.8.x consolidation tag. Three threads of work landed: navigation polling rework (breaking), DB-backed cache state, and a sidebar / topbar / preferences UX cluster. Plus the page-by-page documentation audit.

### Added

- **`/api/navigation/badges` lightweight endpoint.** Flat `{ uriKey: count }` map keyed by resource `uriKey`. Skips menu structure, icons, system section, host-app `mainMenu` resolver and per-section `MenuItem` rendering — only `menuCount()` calls run. 5–10× cheaper server-side than the full tree on a typical sidebar. Cache shares the `navigation` layer with a separate `badges:` prefix.
- **`martis_cache_state` DB-backed operational metadata.** Per-layer version counter, `cleared_at` timestamp and runtime override flag now live in a dedicated table — survives `php artisan cache:clear`, `redis-cli FLUSHDB`, container restarts and LRU eviction. Cache entries themselves still live in `Cache::store()`. Transparent to consumers — same `MartisCache` API, same Artisan commands, same admin page. Migration: `php artisan vendor:publish --tag=martis-cache-state-migration && php artisan migrate`. `martis:install --force` already publishes it on fresh installs.
- **`php artisan martis:list-env-vars`.** Auto-generates the env-var table inside `docs/configuration.md` from the live `config/martis.php`. 130 vars in this build. CI runs it during release-cut so the doc table never drifts.
- **`Resource::accentColor()` page-scoped.** The accent override is now applied on a wrapper element inside the resource page (via `data-resource-accent`), not on `<html>`. Sidebar / topbar / sibling badges (the InvoiceResource "PRO" pill, etc.) keep the user's global accent. The active sidebar leaf still mirrors the resource accent so page header and selection agree.
- **Per-action global toggles for the default row actions column.** New `config('martis.index.default_row_actions.view')`, `.edit`, `.delete` keys (each defaults to `true`) plus matching env vars `MARTIS_DEFAULT_ROW_ACTION_VIEW`, `MARTIS_DEFAULT_ROW_ACTION_EDIT`, `MARTIS_DEFAULT_ROW_ACTION_DELETE`. Flipping a single one to `false` hides that icon across every resource. The global flag AND-composes with the per-resource `defaultRowActions()` override: a resource can subtract further but never force a globally-disabled action back on.
- **`Select::displayUsingLabels()` and `Select::displayUsingValues()`.** API parity with `MultiSelect`. Default `true` so existing payloads keep rendering labels on index and detail. Call `displayUsingValues()` when the raw stored value is meaningful (ISO codes, slugs, identifiers). The flag travels through `extraAttributes()` as `displayLabels` and the React `SelectFieldDisplay` reads it.
- **Resource `static $with` for declarative eager loading.** Add `protected static array $with = ['author', 'tags']` and Martis applies the `with()` call on every index, relationship-picker, and global-search query. Saves the boilerplate `indexQuery` override that previously had to wrap `$query->with([...])`. Hooked through a new `Resource::applyWith()`.
- **`Resource::redirectAfterCreate()` / `redirectAfterUpdate()` hooks.** Optional per-Resource overrides that customise the post-save destination for the **primary** submit button. Returning a string ships it to the SPA via `meta.redirectTo`; the React `ResourceCreate` and `ResourceUpdate` pages respect it before falling back to the standard "navigate to detail" path.

### Changed

- **BREAKING:** `MARTIS_NAV_POLL_MS` / `martis.navigation.poll_interval` removed. Replaced by `MARTIS_NAV_BADGES_POLL_MS` / `martis.navigation.badges_poll_interval` (default `300_000` ms = 5 min, up from 60 s). The full navigation tree is no longer auto-polled — only `/api/navigation/badges` is. Apps still setting the old env will see the value silently ignored; rename to migrate.
- **Polling cadences rebalanced** (non-breaking — same env vars, bumped defaults):
  - `MARTIS_NOTIFICATIONS_POLL_INTERVAL`: `60_000` → `90_000` ms.
  - `MARTIS_IMPERSONATION_POLL_MS` (new env): default `120_000` ms (was hardcoded 30 s).
- **Internal task IDs scrubbed from the published `config/martis.php`.** Six section banners leaked development-time labels (`Task 07.1 ⭐ D2`, `D1`, `Task 12`, `Task 15`, `Task 14 ⭐ differential`, `Task 17 ⭐ runtime control`) into a vendor-publish target that consumers see on every install. Replaced each with the plain feature name. No behavioural change.
- **Internal task IDs and Nova mentions scrubbed from `src/`.** Same hygiene pass across `Resource.php`, `Contracts/ResourceContract.php`, `MartisServiceProvider.php`, `Preferences/PreferencesResolver.php`, `Models/UserPreference.php`, `Http/Controllers/PreferencesController.php`, `Http/Controllers/CacheController.php`, `Console/InstallCommand.php`, `Enums/AccentColor.php`, `Enums/UiDensity.php`, `Tools/Tool.php`, `Tools/ToolServiceProvider.php`.

### Fixed

- **Sidebar active state — deepest-match-wins.** When a `MenuSection` or `MenuGroup`'s `path()` collided with a child leaf URL, all three NavLinks lit up active simultaneously. New `isGroupActive()` helper enforces "deepest match wins" — a group is only styled active when its path matches AND no descendant leaf would itself be active.
- **Pre-login preferences survive into the authenticated session.** `readInitialPrefs()` now honours the `guest-modified` localStorage flag over a stale `source=user` SSR payload. Theme/locale chosen on `/login` no longer reverts after the redirect for users with an existing server preference row.
- **Topbar narrow-mode no longer overlaps the bell into the search.** At ≤ 1024 px the absolute-centred search bar hides; a search icon mounted inside `.martis-tb-right` takes over (in flex flow alongside bell / preferences / user chip), so no element is absolute-positioned where it can collide with another. Cmd/Ctrl-K + the icon click still open the full command palette.
- **`MartisNotification::message` is now optional** (was required despite the doc claim).
- **`PreferencesResolver` accepts the four CSS hex forms** (`#RGB`, `#RGBA`, `#RRGGBB`, `#RRGGBBAA`).

### Documentation audit

Full pass across **configuration**, **cache**, **notifications**, **keyboard-shortcuts**, **preferences**, **differentials** (renamed **Highlights**), **api/overview**, **menus**, **impersonation**, **fields**, **resources**, **relationships**, **troubleshooting**, **installation guide**, **quick start**. Internal refs (D1/D2/D3, Task IDs) stripped. False Nova-parity claims removed. Every claim defensible against current `src/`.

Per-page highlights:

- **Installation guide**: rewritten to match shipped code. PHP 8.3+, Laravel 11/12/13. Translation tag corrected to `martis-lang`. Lists the four real migration tags and drops the phantom `martis-profile-migration`. Documents `--with-2fa` and `--existing-avatar-column`. Manual install grew to 9 steps. Boot-file path corrected to `resources/martis-extensions/martis/boot.ts`. New sections: Host MartisServiceProvider and Vendor Publish Tags Reference. Artisan reference grew from 8 commands to all 28.
- **Quick start**: `Select::displayUsingLabels()` example now works because the method exists. Step 3 no longer instructs users to add a `'resources' => [...]` array (which never existed); explains auto-discovery. Action `handle()` signature corrected to `ActionResponse|Action|null`. Badge type `'secondary'` (not built-in) replaced with `'info'`.
- **Relationships**: 6 fact corrections. `MorphTo::make('commentable_id', ...)` → `MorphTo::make('commentable', ...)`. `canAttach()/canDetach()` no longer claim a closure signature. `modalSize()` real signature documented. `default_trashed_filter` shown nested under `index`. Internal "Task 10" reference removed.
- **Fields**: 12 fact corrections. `BelongsTo::multiple()` removed (method doesn't exist). `modalSize()` takes the `ModalSize` enum, not a string. `Currency` and `Code` setters take typed enums. `Slug` gains the missing `badgeVariant()` / `badgeAccent()` / `badgeColor()`. `BelongsTo` gains `relatableQueryUsing()`. New `GuardSelect` section. `MorphTo` moved to its logical position.
- **Resources**: "31 field types" corrected to 50. `BelongsTo::make('owner', 'owner', UserResource::class)` label cleaned. Internal "Phase 5 of Task 09" leak removed. Added documentation for previously-undocumented public API: `fieldsForIndex/Detail/Create/Update/InlineCreate/Preview`, `filters()/lenses()/cards()/dashboards()` on the Resource class, `static $with`, `title()`, `indexSearchable() / searchPlaceholder()`, `showMenuCount() / menuCount()`, `belongsToSystemSection()`, `searchOrderBy()`, `redirectAfterCreate() / redirectAfterUpdate()`.
- **Troubleshooting**: 12 fact corrections. `--with-profile` no longer claims to create a `Profile` model. Theme tokens live under `theme.*` (no separate `config/martis-theme.php`). The login-redirect-loop snippet's fabricated `martis.auth.guard/provider/driver` block replaced with the real `MARTIS_GUARD` env var. 2FA reset now clears all four columns. `relationshipSearchable()` corrected to `relationSearchable()`. `Profile->locale` and `Martis::setLocale()` (neither exist) replaced with the real `PreferencesResolver` chain and `app()->setLocale($locale)`.

### Tests

- 1887 backend Pest specs + 160 frontend Vitest specs, both green.
- New suites: `NavigationBadgesEndpointTest` (6), `CacheStatePersistenceTest` (6), `sidebar-active-state.test.ts` (9), `use-resource-accent.test.tsx` (6), `preferences-readinitialprefs.test.ts` (7).
- 3 new Pest cases on `Select` (default flag, `displayUsingLabels()` setter, `displayUsingValues()` opt-out).
- 6 new cases in `ResourceWithAndRedirectsTest.php` (`applyWith`, `redirectAfterCreate`, `redirectAfterUpdate`).
- 6 new cases in `DefaultRowActionsResolverTest.php` (per-action global toggle composition).

## [1.8.7] — 2026-05-01

Patch fix for a bug visible on every toast across the app.

### Fixed

- **Double close-button on every toast.** The `ToastContext` `show()` call passed `closable: true` to the PrimeReact `<Toast>`, which renders the framework's own close glyph, while our custom `content` renderer painted its own Phosphor `<XIcon>` styled with `.martis-toast-close`. Both surfaced — operators saw two stacked X icons in the bottom-right of every toast. Now `closable: false`, only the custom Martis-styled button shows.

### Tests

- New `resources/js/toast.test.tsx` (Vitest) asserts exactly one close button per toast.

### Validation

- Pest 1751 / 1 skipped / 0 failed.
- Vitest 120 / 5 skipped (one new).
- PHPStan L8 0 errors. Pint clean.

## [1.8.6] — 2026-05-01

Hotfix: in v1.8.5 the multi-locale `auth.copy.*` was resolved server-side. That meant the copy was frozen at the locale the SSR knew about (always `en` for guests because the resolver doesn't see localStorage). Switching the language picker on `/login` failed to swap the copy.

### Fixed

- **`auth.copy.<page>.<key>` now resolves on the client.** The blade exposes the entry verbatim (string OR `Record<locale, string>`); `useAuthCopy()` picks the active i18n language at render time, so a language flip on `/login` re-renders the title and subtitle without a server round-trip. Resolution order: exact locale → base locale (`en_US` → `en`) → `'en'` → first non-empty.

### Internal

- `MartisAuthCopyEntry = string | Record<string, string> | null` now exported from `lib/config.ts`.
- `useAuthCopy()` re-uses `useTranslation('auth')` (which subscribes to i18next's `languageChanged` event) so the consumer override and the bundled fallback both react to the same trigger.

## [1.8.5] — 2026-05-01

Two UX patches reported during edge-flow validation: `auth.copy` is now multi-locale aware and guest preference picks (theme / locale / etc.) carry into the authenticated shell instead of being silently overwritten by the server-saved row.

### Added

- **Multi-locale `auth.copy.<page>.<key>`.** Each entry now accepts THREE shapes:
  - `null` — fall back to the bundled `auth.php` translation (default).
  - `string` — applied verbatim on every locale (the v1.8.0 behaviour).
  - **`array<locale, string>`** — resolved server-side per the active locale. Resolution order: active locale → `config('martis.locale')` → `'en'` → first available. Edit `config/martis.php` directly when you need multi-locale; env vars stay on the single-string path.
- **Guest preference promotion on login.** The first time `update()` runs while the user is unauthenticated, it sets a `martis-preferences-guest-modified` flag in `localStorage`. The post-login effect consumes the flag, PUTs the cached snapshot to `/api/preferences` (single round-trip) instead of GETting the older server row, and clears the flag on success or failure. Effect: a theme / locale / accent picked on `/login` carries into the authenticated shell instead of "disappearing" the moment the user signs in.

### Internal

- `app.blade.php` exposes `auth.copy` as plain strings to the SPA — the locale resolver runs server-side, so the `useAuthCopy()` hook stays simple. No frontend code change needed for multi-locale support.
- `PreferencesContext` reads `prefsRef.current` for the post-login PUT, so the optimistic state captured during the guest session is exactly what reaches the server.

### Validation

- Pest 1751 / 1 skipped / 0 failed.
- Vitest 119 / 5 skipped.
- PHPStan L8 0 errors. Pint clean.

## [1.8.4] — 2026-05-01

Patch release aligning the relationship-fields default visibility with Nova / Filament conventions: pickers that depend on the parent record having an id are now hidden from the CREATE form by default.

### Changed

- **`BelongsToMany` and `MorphToMany` are now hidden on the create form by default.** Pivot rows need both `(parent_id, related_id)` and the parent does not exist yet when the create form is rendered. Showing the picker before save is misleading — the user expects the attach to "stick" but the parent has nothing to attach to. Override with `->showOnCreating()` if you have a custom `afterSave` hook that drains the picker. Update form behaviour and detail page behaviour are unchanged.

  Affected resources you may want to revisit on consumers that previously relied on the picker being visible at create time: any `RoleResource` / `PermissionResource` with a `BelongsToMany` permissions/roles picker, any tag-style resource with a `MorphToMany` picker, etc.

### Already aligned (no change in v1.8.4)

For reference, the Nova-style "detail-only" defaults that were already in place:

- `HasOne`, `HasOneThrough`, `HasOneOfMany` — `hideFromForms()` since the original implementation.
- `HasMany`, `HasManyThrough` — `hideFromForms()` since the original implementation.
- `MorphOne`, `MorphOneOfMany`, `MorphMany` — `onlyOnDetail()` since the original implementation.

`BelongsTo` and `MorphTo` continue to appear on the create form (their FK lives on the record being created, so picking the related row before save is correct).

### Tests

- New `tests/Unit/Fields/RelationshipHideOnCreateTest.php` (9 cases) covering the defaults plus the `->showOnCreating()` override path.

### Validation

- Pest 1751 / 1 skipped / 0 failed.
- Vitest 119 / 5 skipped.
- PHPStan L8 0 errors. Pint clean.

## [1.8.3] — 2026-05-01

Multiple polish items reported during edge-flow validation. The headliners are the password-reset email finally going out without consumer config, automatic required-field detection, and the BelongsToMany dependsOn mechanism actually firing in CREATE mode.

### Added

- **Automatic password-reset URL builder.** `MartisServiceProvider::boot()` now wires `Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(...)` to point at the Martis-prefixed `martis.password.reset` route whenever `martis.auth.passwordReset.enabled === true`. Defensive: the static probe respects callbacks already configured by the consumer (host apps that want a custom URL builder remain in control). Closes the `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [password.reset] not defined` crash that prevented the Spatie / Laravel password broker from rendering reset emails.
- **Auto-detect `required` flag from `->rules([...])`.** When a field declares `'required'` (or any `required_*` variant, or a `Rule::*` instance whose string form contains `required`) in its base `rules()`, the visual asterisk now appears in the form without the consumer having to repeat `->required()`. `creationRules` / `updateRules` are intentionally NOT consulted to keep the visual contract context-free.
- **`GuardSelect` is required by default.** Spatie always requires `guard_name`; the field now sets `->required()` itself and ships `Rule::in(GuardCatalog::available())` so a guard outside `config/auth.guards` can never reach the DB (this was the root cause of the "Class name must be a valid object or a string" Spatie crash on Role delete).

### Fixed

- **`BelongsToMany::dependsOn([...])` without a callback was being dropped from the schema.** `Field::isDependent()` requires both a watched-field list AND a callback — but the picker only needs the list to forward `?form[*]` to `/attachable`. The schema now surfaces `dependsOn.fields` whenever the watched list is non-empty (sync-field handler still gates on `isDependent()` for backwards compat). This was the actual reason the v1.8.2 dependsOn mechanism didn't fire on CREATE.
- **Auth surface error styling now matches the resource forms.** The pill-with-icon `<FieldError>` introduced in v1.8.2 was visually heavier than the `.martis-input-error` used in every resource form. It now renders as a single row of icon + tiny danger-coloured text — same visual rhythm as the rest of the product.

### Internal

- New `Field::rulesHaveRequired()` helper. Public `isRequired()` chains to it after the explicit flag and the closure resolver are checked.
- `MartisServiceProvider::registerPasswordResetUrl()` runs after routes are loaded so the `martis.password.reset` name is resolvable.

### Validation

- Pest 1742 / 1 skipped / 0 failed.
- Vitest 119 / 5 skipped.
- PHPStan L8 0 errors. Pint clean.

## [1.8.2] — 2026-04-30

UX patches reported during edge-flow validation, plus two reusable DX upgrades that other Resources can now leverage.

### Added

- **`Martis\Fields\Slug::badgeVariant(...)` / `->badgeAccent()` / `->badgeColor('#hex')`.** The slug display badge can now be tinted with the brand accent, semantic colours (`success`/`warning`/`danger`), or any CSS colour value (hex / rgb / hsl / oklch). Useful when the slug IS the row identity — e.g. `Slug::make('name')->badgeAccent()` on PermissionResource / RoleResource so the names pop on the index. Default look unchanged.
- **`BelongsToMany::dependsOn([...])` + 3-arg `relatableQueryUsing` closure.** The picker now forwards declared sibling fields from the unsaved parent form draft (`?form[guard_name]=…`) so server-side filters can scope on what the user just typed in the parent form even before save. Existing arity-2 closures are unaffected (detected via `ReflectionFunction`). Closes the create-time gap on Spatie Permission/Role pickers — admins can pick `guard='sanctum'` on a brand-new Role and the Permissions list narrows to that guard before save.
- **`<FieldError>` component for the auth surfaces.** Replaces the bare `<p className="martis-field-error">` styling with an icon-plus-text pill on a tinted `--martis-danger-bg`. Login / Register / ForgotPassword / ResetPassword all use it now.

### Fixed

- **`PasswordField` and `PasswordConfirmationField` now expose the strength meter + checklist on the Register page.** The register page was rendering hand-rolled `<input type="password">` blocks that bypassed the shared `Password` field stack — Profile got the strength UI but Register did not. Migrated to the same component pair as `PasswordSection`, including the live confirmation-match indicator.
- **`ResourceController::destroy` now catches `\Throwable`, not just `QueryException`.** Spatie's permission-cache invalidation, observer hook errors, third-party policy listeners — anything that throws on `$model->delete()` was bubbling up as a generic 500 with the toast "Error deleting record." The specific message now reaches the operator (still goes through `report()` for monitoring).

### Internal

- Slug field schema payload gains `badgeVariant` / `badgeColor` keys (omitted when default). Existing schemas unchanged.
- BelongsToManyController has a new `collectFormDraft(Request)` helper that parses `?form[*]` into a typed array. Used as the 3rd arg passed to `relatableQueryUsing`.

### Validation

- Pest 1742 / 1 skipped / 0 failed.
- Vitest 119 / 5 skipped.
- PHPStan L8 0 errors. Pint clean.

## [1.8.1] — 2026-04-30

Patch fixes for the v1.8.0 cosmetic regressions reported during edge-flow validation.

### Fixed

- **Theme / locale tooltips clipping above viewport on auth surfaces.** `AuthControls` (top-right of Login / Register / ForgotPassword / ResetPassword / 2FA challenge) now declares `data-pr-position="bottom"` explicitly so the bubble drops below the strip instead of trying to render above it (where the strip is flush against the viewport edge). `MartisTooltip` itself gains a smart fallback: if the requested position would put the bubble within ~36 px of the viewport edge, it flips to the opposite side. Same logic mirrors for top / bottom / left / right.
- **`[DOM] Input elements should have autocomplete attributes (suggested: "new-password")`** on every screen rendering the shared `PasswordField` / `PasswordConfirmationField` components (Profile password change, Register, custom forms with the Password field type). Both components now declare `autoComplete="new-password"`. Sign-in flows already used a hand-rolled `<input>` with `current-password` and were unaffected.

### Validation

- Pest: 1742 passing, 1 skipped, 0 failed.
- Vitest: 119 passing, 5 skipped.
- PHPStan L8: 0 errors. Pint clean.

## [1.8.0] — 2026-04-30

UX polish for the unauthenticated auth surfaces, two new fields aimed at Spatie Permission resources, sidebar count compaction, and package hygiene.

### Added

- **`Martis\Fields\GuardSelect`** — dropdown of auth guards configured in `config/auth.guards`. Default value is `config('auth.defaults.guard')`. `->only([...])` restricts the list to a subset. Designed for the `guard_name` column on Spatie Permission / Role tables. See `docs/roles.md`.
- **`Martis\Auth\GuardCatalog`** — programmatic helper exposing `available()` and `default()`. Same source of truth as `GuardSelect` so consumer-built selectors cannot drift.
- **`GET /martis/api/_meta/guards`** — auth-protected endpoint returning `{guards, default}` for custom admin UIs.
- **`Martis\Http\Controllers\MetaController`** — landing place for future meta endpoints (route catalog, middleware aliases) once concrete consumer demand exists.
- **Configurable per-page copy on auth surfaces** — `config/martis.php` `auth.copy.{login,register,forgot_password,reset_password}` accepts string overrides for title and subtitle. Each entry's default `null` falls back to the bundled `auth.php` translations (i18n still wins when present). The bridge in `app.blade.php` exposes the block as `window.MartisConfig.auth.copy`; the React helper `useAuthCopy()` resolves overrides → translations.
- **Sidebar count compaction** — `MARTIS_NAV_COUNT_COMPACT_THRESHOLD` (default `10000`) switches badges from full digits to compact notation (`10K`, `123.5K`, `1.2M`). Set to `null` to disable, `0` to always compact. Threshold is exposed via SSR (`window.MartisConfig.navigation.countCompactThreshold`); the formatter is `formatItemCount()` in `resources/js/lib/navigation.ts`. New Vitest fixtures cover the boundary (`tests/.../navigation.test.ts`).
- **Translation keys** for forgot/reset password and AuthControls tooltips (theme labels, language label) — `auth.php` in `en`, `pt_PT`, `pt_BR` is now exhaustive for the unauthenticated surfaces.
- **`permissions.php` translation namespace** — `name_help`, `guard_help`, `role_guard_help`, `multi_guard_explanation` for tooltip help text on Permission / Role forms.

### Fixed

- **MartisTooltip not rendering on auth surfaces.** `AuthControls.tsx` declared `data-pr-tooltip` but the listener was only mounted in the authenticated shell. `AuthFrame` now renders its own `<MartisTooltip />` so theme / locale tooltips actually appear in Martis style instead of the native browser bubble.
- **Password forms missing username field for accessibility.** `PasswordSection` (Profile) now ships an off-screen `autoComplete="username"` input bound to `user.email`. `Login`, `Register`, and `ResetPassword` use `autoComplete="username email"` on their email input so password managers and Chrome's a11y audit treat the form as well-formed (no more "Password forms should have a username field" console warning).
- **Theme / density / accent highlight invisible in light mode.** `--martis-input-bg` and `--martis-surface` were both `#FFFFFF` in light theme, so the active segment had no contrast against the track. The `.martis-segmented` track now uses `--martis-surface-alt` in light theme so the active button reads clearly. Dark theme already had distinct values (no visual change there).
- **Password-reset 500 when the mailer is misconfigured.** `AuthController::sendPasswordResetLink` wraps the broker call in a `try/catch`; SMTP / queue / API-key failures now return `503` with `{message: __('auth.forgot_password_mailer_unavailable')}` and a Martis toast, instead of a raw 500 stack trace. The original throwable still goes through `report()` so the host can monitor it.
- **`SyntaxError: Unexpected token <` from non-JSON error responses.** `lib/api.ts` now reads the body as text and parses defensively; non-JSON 404 / 405 responses surface as a clean `ApiError` with the standard "Request failed" toast.
- **Forgot-password page when feature is disabled** redirects back to `/login` with a Martis info toast instead of throwing on mount (rules-of-hooks violation in the previous redirect logic).
- **Reset-password page** had a hardcoded English `aria-label` for the show/hide-password button. Now uses `t('reset_password_show')` / `t('reset_password_hide')`.

### Internal

- `.gitattributes` added with `export-ignore` for `.github/`, `tests/`, `test-results/`, `playwright.config.ts`, `phpstan.neon`, `phpunit.xml`, etc. — these no longer ship to consumers via the composer dist zip.
- `test-results/` added to `.gitignore`; the previously tracked `.last-run.json` is now untracked.
- `PreferencesContextValue.update` no longer accepts only a `Partial<Preferences>` — also accepts `(prev) => Partial<Preferences>` (functional patch). Plain-object call sites unchanged. Documented in `docs/preferences.md`.

### Validation

- Pest: 1742 passing (3 new in `MetaGuardsEndpointTest`), 1 skipped, 0 failed.
- Vitest: 119 passing (9 new in `navigation.test.ts`), 5 skipped.
- PHPStan L8: 0 errors. Pint clean.

## [1.7.6] — 2026-04-30

Comprehensive guest-flow hardening for the Login / Register / 2FA challenge surfaces, plus a CSS specificity fix for the theme-aware logo variants.

### Fixed

- **Guest login page no longer fires GET / PUT 401 on `/api/preferences`.** `PreferencesContext` previously refetched on every mount (GET) and round-tripped every theme/locale tweak (PUT). Both endpoints live behind the `martis.auth` middleware group, so each guest interaction logged a `401 Unauthorized` in the console. Both calls now skip when `user` is `null` — localStorage and the SSR payload are already authoritative for guests; the server has nothing extra to add.
- **Theme cycle / locale picker on the login page did not persist across refresh.** `update()` relied on assigning `nextState` inside a `setPrefs(updater)` callback, which under React 18 automatic batching could run asynchronously — `nextState` stayed null and the `localStorage.setItem` was silently skipped. The merged snapshot is now computed from a `useRef` mirror BEFORE calling `setPrefs`, deterministic regardless of when React flushes the update.
- **Theme toggle felt intermittent on rapid clicks.** Three back-to-back clicks before React re-rendered all read the same `prefs.theme` from the captured render closure, so the cycle "skipped" steps. `update()` now accepts a functional patch — `AuthControls.onThemeCycle` derives the next mode from the LATEST committed prefs (read via the ref mirror) so chained clicks compound correctly.
- **Native browser tooltip on auth controls (off-brand styling).** `AuthControls` now uses the Martis-wide `data-pr-tooltip` pattern, so the global PrimeReact tooltip renders consistently with the rest of the shell.
- **Logo variant did not swap on theme toggle, and a faint silhouette of the inactive variant peeked through.** The theme-aware logo CSS in `martis.css` now uses `display: none !important` to win against the logo-mode rule's higher specificity. A single image is rendered at any time.

### Internal

- `PreferencesContextValue.update` is now typed as `(patch: Partial<Preferences> | ((prev: Preferences) => Partial<Preferences>)) => Promise<void>`. Existing call sites that pass a plain object continue to work unchanged.

### Validation

- Pest: 1739 passing, 1 skipped, 0 failed.
- Vitest: 110 passing, 5 skipped.
- Visual smoke in production (edge-flow): theme toggle persists across refresh in both auth and guest, locale picker persists, no 401 in console, tooltip styled.

## [1.7.5] — 2026-04-30

Hotfix: dev-changed defaults now reach every guest who has not explicitly picked their own.

### Fixed

- **`MARTIS_DEFAULT_THEME` / `MARTIS_DEFAULT_ACCENT` / `MARTIS_DEFAULT_DENSITY` were silently masked by stale localStorage** for any visitor who had loaded the page even once before. The v1.7.3 `readInitialPrefs()` priority gives localStorage precedence over SSR defaults (correct, so a guest who picks pt_PT survives refresh), but the `useEffect([prefs])` write was also persisting the SSR defaults on the very first mount — every visitor's localStorage immediately mirrored whatever defaults shipped that day. A later `MARTIS_DEFAULT_*` change never reached anyone who had already visited.
- localStorage is now written ONLY by `update()` (an explicit user pick) and cleared by `reset()`. The DOM-apply effect is split out and runs without the persistence side effect. SSR defaults are no longer locked into localStorage on first render, so a dev who flips `MARTIS_DEFAULT_THEME=dark → light` reaches every visitor without a local override.

### Migration notes

- Existing visitors who already have stale localStorage from v1.7.0–v1.7.4 still see their cached values until they pick something explicitly OR clear `martis-preferences` from their browser. New visitors after this release start clean and get whatever `MARTIS_DEFAULT_*` says.

### Validation

- Pest: 1739 passing, 1 skipped, 0 failed.
- Vitest: 110 passing, 5 skipped.

## [1.7.4] — 2026-04-30

Two persistence bugs that survived v1.7.0–1.7.3.

### Fixed

- **Custom accent names were silently dropped on persistence (auth users).** `Martis\Models\UserPreference` cast `accent` as `AccentColor::class`. When the user picked a custom name (e.g. `edgeflow` from `MARTIS_CUSTOM_ACCENTS`), the cast's `tryFrom()` returned null on read; `toPayload()` then crashed on `null->value`. Bundled accents persisted because they matched the enum; custom ones never round-tripped. The cast is removed — `accent` is a plain string column. `PreferencesResolver::normaliseAccent()` already validates the value (bundled enum + custom names) on every read, so the model layer doesn't need to.
- **i18n initialised with the SSR locale instead of the user's localStorage choice.** `getLocale()` returned `window.MartisConfig.locale` (server's view), so a guest who picked `pt_PT` on the login page saw the language picker show pt_PT after refresh but the texts were still English. The resolver now consults localStorage first (same priority as the React `readInitialPrefs()` and the blade pre-paint script), falling through to SSR + hard fallback only when no cached value exists.

### Tests

- 3 new Pest cases under `tests/Feature/UserPreferenceCustomAccentTest.php` — bundled accent round-trip, custom accent round-trip, `toPayload()` survives an unknown name written by an older version.

### Validation

- Pest: 1739 passing, 1 skipped, 0 failed.
- Vitest: 110 passing, 5 skipped.
- PHPStan L8: 0 errors. Pint clean.

### Migration notes

- No DB migration required. The `accent` column was already `string` — only the model cast changed. Rows previously written by v1.7.0–1.7.3 with bundled enum values continue to work.
- Hosts with their own UserPreference cast override (rare) should remove `'accent' => AccentColor::class` from `$casts` to avoid the same silent-drop behaviour.

## [1.7.3] — 2026-04-30

Hotfix for guest preference persistence. Authenticated user persistence already worked; this is the missing twin.

### Fixed

- **Guest preferences (theme / accent / locale picked on the login or register page) reverted to the config defaults on every refresh.** `PreferencesContext::readInitialPrefs()` returned the SSR-injected payload unconditionally — for guests that payload is just `source: 'default'` from `config('martis.preferences.defaults')`, NOT what the guest actually picked. localStorage was never consulted. A guest who picked light + pt_PT on the login page reverted to dark + en after refresh.
- The resolver now inspects `injected.source`. When it is `'user'` or `'preset'`, the SSR payload wins (real persisted state for an authenticated account, beating any stale localStorage from a previous account on the same browser). When it is `'default'`, the resolver checks localStorage first and only falls back to the SSR defaults / hard-coded fallback if nothing is cached locally. This mirrors the pre-paint resolver in `app.blade.php` (added in v1.6.4) — the same priority is now applied at the React layer.

### Validation

- Pest: 1736 passing, 1 skipped, 0 failed.
- Vitest: 110 passing, 5 skipped.

## [1.7.2] — 2026-04-30

Two more hotfixes for v1.7.0 / v1.7.1 custom accents.

### Fixed

- **Server-side validator now accepts custom accent names.** The `PUT /martis/api/preferences` endpoint validated `accent` against `AccentColor::cases()` only — picking a custom swatch silently failed validation (422), so the choice never persisted. On refresh the user reverted to the previous (or default) accent. The validator now unions `AccentColor` enum values with the keys parsed from `MARTIS_CUSTOM_ACCENTS`.
- **Custom-accent CSS now wins the cascade.** The bundled `app.css` declares `html:not(.dark) { --martis-accent: ... }` and `html.dark { --martis-accent: ... }` as theme defaults. Those selectors share specificity (1 type + 1 attr/class = 11) with our `html[data-accent="<name>"]` rule, so cascade order decides the tie. The inline `<style>` block was emitted BEFORE the bundle `<link>` — the bundle defaults silently overrode the custom accent. The custom-accent style block now ships AFTER the bundle CSS link in `app.blade.php`, so a clicked custom swatch actually re-tints buttons / sidebar highlight / focus rings. The brand-height `:root` block stays in its early position because no bundled rule competes for those variables.

### Validation

- Pest: 1736 passing, 1 skipped, 0 failed.
- Vitest: 110 passing, 5 skipped.
- PHPStan L8: 0 errors. Pint clean.

## [1.7.1] — 2026-04-30

Hotfix for v1.7.0 custom accents.

### Fixed

- **Custom accents now propagate to the UI.** v1.7.0 emitted only 5 CSS variables (`--martis-accent`, `-hover`, `-soft`, `-strong`, `-text`) for each custom accent. The bundled accent rules in `resources/css/martis.css` use a different (and larger) variable set: `--martis-accent`, `--martis-accent-hover`, `--martis-accent-active`, `--martis-accent-bg-light`, `--martis-accent-bg`, `--martis-focus-ring`. Buttons, sidebar highlight, focus rings, and accent backgrounds were therefore reading values from a fallback chain — clicking a custom swatch updated the persisted preference but the UI stayed on the previous accent.
- The inline `<style>` block injected by `app.blade.php` now mirrors the exact variable set the rest of the stylesheet consumes. Hover / active darken via `color-mix(in srgb, hex 88/78%, black)`; the soft fills + focus ring use `color-mix(in srgb, hex 14/24/45%, transparent)` to match the translucency of the bundled rules.

### Validation

- Pest: 1736 passing, 1 skipped, 0 failed.
- Vitest: 110 passing, 5 skipped.

## [1.7.0] — 2026-04-30

Brand surfaces gain theme-aware variants, per-surface sizing knobs, smarter sidebar collapse behaviour, and the preferences subsystem opens up to env-driven defaults plus arbitrary consumer-defined accent colours.

### Added

- **`brand.logo_dark` + `brand.icon_dark`** (env `MARTIS_BRAND_LOGO_DARK`, `MARTIS_BRAND_ICON_DARK`). When the consumer ships separate light/dark assets, the SPA renders both `<img>` tags in the DOM and CSS hides one based on `<html data-theme>` — instant toggle, no React re-render, no fetch. Setting only one variant reuses it for both themes (zero migration for v1.6.x consumers).
- **Per-surface logo height** (env `MARTIS_BRAND_LOGO_HEIGHT_MENU`, `MARTIS_BRAND_LOGO_HEIGHT_AUTH`). Drives two CSS variables on `:root`. Server clamps menu to 20–56 px, auth to 24–80 px so a typo cannot break the layout.
- **Sidebar collapse prefers the icon.** When the sidebar is collapsed AND the consumer shipped `brand.icon` (or `brand.icon_dark`), the icon wins regardless of `brand.logo`. A horizontal lockup at 64 px rail width gets distorted; the square icon fits cleanly.
- **Default theme / accent / density via env** (`MARTIS_DEFAULT_THEME`, `MARTIS_DEFAULT_ACCENT`, `MARTIS_DEFAULT_DENSITY`). Invalid values fall through to safe defaults via `PreferencesResolver`.
- **Custom accent colours** via `MARTIS_CUSTOM_ACCENTS="name:#hex,name:#hex,..."`. Each parsed entry adds an extra swatch to PreferencesMenu, becomes a valid value for `MARTIS_DEFAULT_ACCENT`, and persists in `user_preferences.accent`. Hover / soft / strong CSS variants auto-derive via `color-mix(in srgb, ...)` so the consumer only ships one colour per accent. Validation: lowercase `[a-z][a-z0-9_-]{0,31}`, `#RRGGBB`, no collision with bundled enum values, last-wins, capped at 24 entries.

### Changed

- `MartisConfigShape` (TypeScript) gains `logoDark`, `iconDark`, `logoHeight`. `MartisPreferencesConfig` gains `customAccents`.
- `Sidebar.tsx`, `TopnavLayout.tsx`, `AuthFrame.tsx` consolidate brand-mark resolution into a single chain that handles light/dark variants. Existing single-variant consumers keep their behaviour.
- `app.blade.php` injects two inline `<style>` blocks at boot: brand-height CSS variables + custom-accent CSS rules.

### Tests

- 18 new Pest cases (`CustomAccentsParserTest`, `BrandV170ConfigTest`, `PreferencesResolverCustomAccentTest`).
- Pest: 1736 passing, 1 skipped, 0 failed.
- Vitest: 110 passing, 5 skipped.
- PHPStan L8: 0 errors. Pint clean.

### Documentation

- `docs/configuration.md` — Brand block extended; new sub-sections "Theme-aware variants", "Asset sizing", "Sidebar collapse behaviour". New top-level "Preferences (v1.7.0 defaults)" + "Custom accent colours" sections. End-of-doc env table updated with all 8 new keys.

### Migration notes for v1.6.x consumers

- All new keys are additive. No `.env` change required to keep v1.6.x behaviour.
- Hosts with published `config/martis.php` should re-sync the brand block to expose the new keys (`vendor:publish --tag=martis-config --force` or copy-paste).
- Custom accents added at runtime do NOT require a frontend rebuild — `php artisan config:cache && php artisan martis:cache:clear` is enough.

## [1.6.5] — 2026-04-30

Defensive CSS for logo-only mode after the v1.6.4 production smoke surfaced overflow on tall horizontal lockups.

### Fixed

- `.martis-sb-logo` gains `max-height: 56px` + `overflow: hidden` so the row can never grow past the bar height.
- Logo-mode rules add `max-height` on both the mark wrapper and the img, plus `object-fit: contain` + `display: block`.
- Auth-card logo grows from 40px to 48px and the cap from 220px to 240px.

## [1.6.4] — 2026-04-30

Two production-surfaced bugs + a doc rewrite.

### Fixed

- **Guest theme / locale preferences now persist across refresh.** The pre-paint resolver in `resources/views/app.blade.php` was `prefs.theme || cached.theme || ...`, which always honoured the server-injected payload first. For guests the server has no row to honour — it falls back to the config defaults (dark / en) — so a guest who picked light + pt_PT on the login page reverted on every refresh. The resolver now checks `preferences.initial.source`: when it is `'user'` or `'preset'`, the server payload wins (correct behaviour for authenticated users); when it is `'default'`, the localStorage cache wins, falling through to the server defaults only when nothing is cached.
- **Logo-only mode no longer crams the asset into the 28×28 icon slot.** The CSS for `.martis-sb-logo-mark` and the topnav / auth-card equivalents kept the mark constrained to a square box, so a wide horizontal lockup looked tiny. The brand container now ships a `data-mode` attribute (`"logo"` / `"icon"` / `"bundled"`) and CSS frees the height (40px sidebar, 36px topnav, 40px auth card) and lets the image scale to its natural width, capped to prevent overflow.

### Documentation

- `docs/configuration.md` — "Logo vs icon" subsection rewritten as **"Choosing your brand mode"** with explicit walk-through of all four modes, ASCII previews, the env var for each knob, the surfaces each one reaches, and the cache-clear sequence to switch modes after deployment. Closes the "where do I set each mode?" question raised against v1.6.3.

### Validation

- Pest: 1718 passing, 1 skipped, 0 failed.
- Vitest: 110 passing, 5 skipped.
- PHPStan L8: 0 errors.
- Pint: clean.

## [1.6.3] — 2026-04-30

Brand surfaces gain a proper `icon` knob and the AuthFrame (Login / Register / Forgot / 2FA / error screens) finally honours the consumer's brand assets.

### Added

- **`brand.icon` config** + `MARTIS_BRAND_ICON` env var. Small square brand mark that replaces the bundled cube next to `brand.name` in the sidebar / topnav. Independent from `brand.logo`, so a consumer can ship a square icon AND a horizontal lockup at the same time. Surfaced to the SPA at boot via `window.MartisConfig.icon` (also added to `MartisConfigShape` in `resources/js/lib/config.ts`).

### Changed

- **AuthFrame now reads `config.logo` / `config.icon`** instead of always rendering the bundled Martis logo. Resolution order on Login / Register / Forgot password / Reset password / 2FA challenge / error pages:
    1. `config.logo` set → render the lockup alone (no separate brand text).
    2. `config.icon` set → render the icon + `config.brand` text side-by-side.
    3. Neither set → bundled Martis lockup (the previous behaviour).
- **Sidebar + TopnavLayout** apply the same fallback chain. When `brand.logo` is set, the brand-name text next to the mark is now **hidden** so a horizontal lockup with built-in wordmark does not show the wordmark twice.

### Tests

- 3 new Pest cases under `tests/Feature/BrandIconConfigTest.php` — env wrapper, default `null`, independence from `brand.logo`.

### Documentation

- `docs/configuration.md` — Brand block extended with the new `icon` row, plus a "Logo vs icon" subsection that walks through the four brand modes (logo-only, icon + text, default, both).

### Migration notes for v1.5.x / v1.6.x consumers

- **`config/martis.php` republish (one-time).** Hosts that published `config/martis.php` before v1.5.2 still have `'logo' => null` / `'text' => null` hard-coded — no env wrappers, no `brand.icon` row, no `welcome` block. The new keys default to `null` so nothing breaks, but the env vars `MARTIS_BRAND_LOGO`, `MARTIS_FOOTER_TEXT`, `MARTIS_WELCOME_*`, `MARTIS_BRAND_ICON` are silently ignored until the published config is updated. Two paths:
    1. **Re-publish + re-apply your edits**: `php artisan vendor:publish --tag=martis-config --force`, then port any host-specific changes back in.
    2. **Edit in place**: open `config/martis.php` and replace the static values for `brand.logo`, `brand.icon`, `footer.text` with `env('MARTIS_BRAND_LOGO')`, `env('MARTIS_BRAND_ICON')`, `env('MARTIS_FOOTER_TEXT')`. Add the `welcome` block. The package tracks the env wrappers from this release on, so future `vendor:publish --force` lands the canonical version.
- The AuthFrame visual changes only when the consumer has `brand.logo` or `brand.icon` set. Hosts that never set either keep the bundled Martis logo on Login.

## [1.6.2] — 2026-04-29

Patch the v1.6.0/1.6.1 `martis:roles` stubs after live deployment surfaced two production-facing bugs.

### Fixed

- **Empty NAME / GUARD_NAME / Email columns on the scaffolded index pages.** `Field::make($attribute, ?$label)` takes the attribute first; the previous stubs called `Text::make('Name', 'name')` which made `Name` the attribute and `name` the label — the resource then read `$model->Name` (null for every row). Re-ordered every `Text::make` / `Email::make` / `DateTime::make` / `Boolean::make` call in `roles-{user,role,permission}-resource.stub` to attribute-first.
- **`Field::help(null)` fatal on the User schema endpoint.** The conditional `->help($hasSsoColumn ? '...' : null)` violates the `Closure|string` typehint. The User stub now uses `tap(...)` so `->help(...)` is only chained when the SSO migration column exists.
- **Computed Boolean field signature.** Replaced `Boolean::make('Email verified', fn ($user) => ...)` (closure as second arg, which the base `Field::make` does not accept) with `Boolean::make('email_verified_at', 'Email verified')->displayUsing(fn ($value) => $value !== null)` — the canonical computed-display pattern documented in `docs/fields.md`.

### Tests

- New regression assertions in `tests/Feature/RolesScaffoldCommandTest.php` walk every generated resource and pin the attribute-first `Field::make` signature for every text / email / datetime / boolean field.

### Recovery for v1.6.0/1.6.1 consumers

```bash
composer require martis/martis:^1.6.2 -W
php artisan martis:roles --force
```

If you customised the generated resources before this patch, the safer path is to re-order the `Field::make` arguments in your existing `app/Martis/Resources/{User,Role,Permission}Resource.php` files manually instead of using `--force` (which would overwrite your edits).

## [1.6.1] — 2026-04-29

Patch the `martis:roles` policy stub when the User model and the policy's target model are the same class (`UserPolicy`).

### Fixed

- **`UserPolicy` no longer renders two `use App\Models\User;` lines.** The 1.6.0 stub emitted both `use {{ userModelImport }};` and `use {{ modelImport }};` unconditionally, which collapsed to two identical `use` lines for `UserPolicy` and produced a fatal `Cannot use App\Models\User as User because the name is already in use` at autoload time. The command now collapses duplicates to a single import.
- New regression test under `tests/Feature/RolesScaffoldCommandTest.php` walks every generated policy and asserts no duplicate `use` lines.

### Validation

- Pest: 1715 passing, 1 skipped, 0 failed.

## [1.6.0] — 2026-04-29

`martis:roles` — one-shot admin UI for users, roles, and permissions; ActionEventResource joins the new System sidebar group; `Resource::belongsToSystemSection()` opens the same shelf to host-app resources.

### Added

- **`php artisan martis:roles`** — scaffolds a Spatie-backed admin surface end-to-end:
    - Runs `composer require spatie/laravel-permission` if missing (skip with `--no-install`).
    - Publishes Spatie's config + migrations and runs them (`--no-publish-spatie` / `--no-migrate` to opt out).
    - Patches the host's `User` model with the `HasRoles` trait via cirurgical regex injection.
    - Generates `UserResource`, `RoleResource`, `PermissionResource` under `app/Martis/Resources/` (override the namespace via `--namespace=`).
    - Generates `UserPolicy`, `RolePolicy`, `PermissionPolicy` under `app/Policies/` — admin-only by default.
    - Registers the policies in `App\Providers\AuthServiceProvider::boot()` via a `/* martis:roles policies */` marker (idempotent re-run).
    - Emits `database/seeders/MartisRolesSeeder.php` that creates the `admin` role.
    - Idempotent: re-running without `--force` skips files already on disk.
- **`Resource::belongsToSystemSection(): bool`** (default `false`) — when `true`, the resource skips the regular grouping loop and renders inside a single **System** sidebar section alongside the Cache admin link.
- **System sidebar group is now multi-source.** `NavigationController` merges:
    1. Resources marked with `belongsToSystemSection() === true` (new).
    2. The Cache admin link (gated by `martis.cache.admin_ui` + the `manage-martis-cache` Gate, unchanged).

  The section appears whenever there is at least one item visible to the current user. No items, no section.
- **SSO-managed role lock in the generated `UserResource`.** The `Roles` BelongsToMany picker filters out any role with a non-null `provider_group_name` (set by `martis:sso ... --with-migration`). Those roles are owned by the IdP; the next sign-in would overwrite a manual change. The field's help text explains the behaviour to the operator.

### Changed

- **`ActionEventResource`** — `belongsToSystemSection()` now returns `true`, so the audit log lives inside the System sidebar section instead of as an unlabelled top-level entry.

### Tests

- 4 new Pest cases under `tests/Feature/SystemNavigationSectionTest.php` — pin the contract that system-grouped resources are lifted out of the regular grouping loop, that the Cache admin link still appears alongside them, and that the section disappears when nothing is visible to the user.
- 7 new Pest cases under `tests/Feature/RolesScaffoldCommandTest.php` — registration, three-resource scaffold, User-model patch, three-policy generation, AuthServiceProvider registration, seeder emission, idempotency under re-run.

### Documentation

- New `docs/roles.md` — full guide: command flags, resource surfaces, customisation, SSO interaction, removal procedure.
- `docs/README.md` — index entry for the new guide.

### Validation

- Pest: **1715 passing**, 1 skipped, 0 failed (was 1704 in v1.5.2).
- Vitest: 110 passing, 5 skipped.
- PHPStan L8: 0 errors.
- Pint: clean.

### Migration notes for v1.5.x consumers

- The audit log now lives inside the **System** sidebar group. Hosts that hide it via custom navigation (`Martis::mainMenu(...)` callback) should re-check the section the resource lands in.
- `belongsToSystemSection()` is a new method on `Martis\Contracts\ResourceContract`. The base class `Martis\Resource` provides a `false` default — every existing resource keeps its current behaviour. Custom contract implementors must add the method.

## [1.5.2] — 2026-04-29

Brand surfaces gain proper env-driven knobs. No code changes required to ship a fully branded SaaS dashboard — set the env vars and the SPA picks them up at boot.

### Added

- **`MARTIS_BRAND_LOGO` env wrapper** (`brand.logo`). Was a config-only key on 1.5.1; now wired to the env so the host app can swap the sidebar logo without publishing the config file. Accepts a relative path under `public/` (`/img/logo.svg`) or a full URL (`https://cdn.example.com/logo.svg`).
- **`MARTIS_FOOTER_TEXT` env wrapper** (`footer.text`). Single-string override for the footer line; when unset, the bundled translation renders ("© {brand.name} · Powered by Martis").
- **`MARTIS_WELCOME_HEADING` + `MARTIS_WELCOME_DESCRIPTION` env wrappers** (new `welcome` config block). Override the dashboard's hero card without touching translations. The values flow into the SPA via `window.MartisConfig.welcome` and `WelcomeCard` consults them after props but before the `martis::resources.welcome_card_*` translations.
- **`welcome` shape exported on `MartisConfigShape`** so TypeScript hosts that read `window.MartisConfig` directly stay typed.

### Documentation

- `docs/configuration.md` — Brand block now lists `logo`, `version`, `docs_url` with their env wrappers; new "Welcome card" section with the resolution order and the i18n trade-off (env = single string for every locale; lang-publish path = per-locale copy); end-of-doc env table updated with the four new vars.

### Tests

- 6 new Pest cases under `tests/Feature/BrandEnvWrappersTest.php` — pin the env contract for `brand.logo`, `footer.text`, and the new `welcome.{heading,description}`.

### Validation

- Pest: 1704 passing, 1 skipped, 0 failed.
- Vitest: 110 passing, 5 skipped, 0 failed.

### Notes for consumers

- `brand.logo` already existed on 1.5.1 with `'logo' => null`; the on-disk default flips to `env('MARTIS_BRAND_LOGO')` so an env value now wins without re-publishing config. If you set `brand.logo` directly in your published `config/martis.php`, that value still wins (your file is the source of truth for that path).
- The four new env vars are intentionally locale-agnostic. If you need per-locale copy, leave them unset and use `vendor:publish --tag=martis-lang`.

## [1.5.1] — 2026-04-29

CI hygiene + PHP 8.2 dropped from the supported floor. **Breaking for 1.5.0 consumers still running PHP 8.2** (rare, since 1.5.0's lock was already 8.3-only via the platform pin); transparent for everyone on 8.3 or 8.4.

### Changed

- **`composer.json` `php` constraint: `^8.2` → `^8.3`** ([#118](https://github.com/Real-Edge-FX/martis-package/pull/118)). Pest 4 → PHPUnit 12 → requires PHP 8.3 (`gc_status()` returns null on PHP 8.2 for fields PHPUnit 12 expects as floats; the test runner crashes at boot). 1.5.0 already shipped a lock that didn't actually install on 8.2; this release makes the constraint match reality. Laravel 11 still supports 8.3/8.4, so consumers on Laravel 11 stay compatible by upgrading their PHP.
- **CI matrix widened**: was `PHP 8.2/8.3 × Laravel 11/12`; now `PHP 8.3/8.4 × Laravel 11/12/13`. Catches more issues at the same cost.
- **`config.platform.php` pinned to `8.3.99`** ([#117](https://github.com/Real-Edge-FX/martis-package/pull/117)). Lock resolves Symfony 7.4 (PHP 8.3+) cleanly on every CI runner. The Pint job, which runs `composer install` against the lock on PHP 8.3, no longer fails because Symfony 8.0.x was being pulled in.

### Fixed

- **Pint job pass on every PR + branch** ([#117](https://github.com/Real-Edge-FX/martis-package/pull/117)). Combined with #118, the full CI now matches the dev-environment expectations.

## [1.5.0] — 2026-04-29

Auth-page Layer 2 lands properly + email verification arrives as a first-class Martis feature.

### Added

- **Auth-page overrides via `martis:component --type=`**. Five new types — `login-page`, `register-page`, `forgot-password-page`, `reset-password-page`, `email-verify-notice-page` — each ships a stub at `stubs/component-{type}.tsx.stub` and registers the generated component under a fixed registry key (`auth:login`, `auth:register`, `auth:forgot-password`, `auth:reset-password`, `auth:email-verify-notice`). The SPA router resolves these keys via `componentRegistry.resolve()` before rendering the bundled defaults — same mechanism as the existing `--type=shell` / `--type=topbar` / etc. Closes the Layer 2 promise from the v1.4.0 doc.
- **Email verification feature**. Three pieces, all opt-in:
    - **Config knob** `auth.email_verification.enabled` (default `false`) — flipping it registers the middleware, themed pages, and POST endpoint together.
    - **Middleware `martis.verified`** — pass-through when disabled, redirects unverified users to `/{martis-path}/email/verify` (or a custom `notice_url`) when enabled. Auto-applied to every protected Martis route.
    - **Themed pages**: `/{martis-path}/email/verify` (notice) and `/{martis-path}/email/verify/{id}/{hash}` (Laravel signed link handler). The notice page is overridable via Layer 2 (`--type=email-verify-notice-page`).
    - **Custom email content**: bind `Martis\Contracts\SendsEmailVerification` to take full control of the resend flow (queueable notifications, branded templates, magic-link tokens).
- **Default `EmailVerifyNoticePage`** React component (`resources/js/pages/EmailVerifyNotice.tsx`) — same `AuthFrame` shell as Login/Register, with "Resend verification link" + "Sign in with a different account" actions.

### Documentation

- `docs/authentication.md` — Layer 2 section rewritten with the real mechanism (no more "planned for v1.5.0" placeholder). New "Email verification" top-level section: when does Martis send the email, three-step opt-in, customisation via Layer 2 + Layer 3, surface lifecycle table.

### Tests

- 12 new Pest cases under `tests/Feature/AuthPageOverrideTest.php` — every `--type` value scaffolds the right TSX file, registers under the right key, rejects unknown types, AUTH_PAGES const stays in sync.
- 9 new Pest cases under `tests/Feature/EmailVerificationFeatureTest.php` — middleware pass-through when disabled, redirect when enabled, off-platform `notice_url`, JSON 409 path, send endpoint, custom contract binding.

### Validation

- Pest: 1698 passing, 1 skipped, 0 failed (was 1677 pre-release).
- Vitest: 110 passing, 5 skipped, 0 failed.
- PHPStan L8: 0 errors.

## [1.4.0] — 2026-04-29

Auth surfaces complete. Martis now ships a themed page **and** a default backend handler for every guest auth flow (Login, Register, Forgot password, Reset password). All four are togglable, all four can point off-platform, and each backend handler is replaceable through a single service-container binding.

### Added

- **Themed Forgot password page** ([#112](https://github.com/Real-Edge-FX/martis-package/issues/112) / new). `resources/js/pages/ForgotPassword.tsx` plus `GET /{martis-path}/forgot-password`. Same `AuthFrame` shell as Login.
- **Themed Reset password page**. `resources/js/pages/ResetPassword.tsx` plus `GET /{martis-path}/reset-password/{token}`. Reads the token from the URL and the email from `?email=`.
- **Default backend handlers** for Register, Send-reset-link, and Reset-password. Each lives behind a single-method contract under `Martis\Contracts\` (`RegistersUsers`, `SendsPasswordResetLinks`, `ResetsUserPasswords`) and resolves the Martis-shipped default (`Martis\Auth\Default*`) unless the consumer rebinds in their own service provider.
- **API endpoints**: `POST /{martis-path}/api/auth/register`, `POST /{martis-path}/api/auth/password/email`, `POST /{martis-path}/api/auth/password/reset`. Throttled like login. Each 404s when the matching surface is disabled.
- **Public `/register` route**. The route was documented in 1.3.x but lived inside the `auth` middleware group, so anonymous visitors 302'd to `/login`. It is now a public route handled by the new `GuestPagesController`, which returns the SPA shell, redirects to `/login` (when disabled), or redirects off-platform (when `auth.registration.url` is set). The same controller serves `/forgot-password` and `/reset-password/{token}`.
- **Config additions**:
    - `auth.passwordReset.broker` (default `users`) — pick the Laravel password broker.
    - `auth.registration.default_role` — auto-assign a Spatie role (or any model that responds to `assignRole()`) to every new user.
- **Tests**: 12 new Pest cases under `tests/Feature/AuthSurfacesTest.php` covering on-platform / off-platform / disabled state for every surface, plus a service-container override example.
- **Doc rewrite** of `docs/authentication.md` (sections "Alternative sign-in flows", "Forgot password", "Registration", "Customising auth surfaces"). Three override layers documented end-to-end: config-only off-platform redirect, React page override via `martis:component`, and service-container binding swap with a working `App\Auth\MyRegistrar` example.

### Fixed

- **`/{martis-path}/register` reachable as a public route**. Was a documentation drift in 1.3.x — the doc claimed the route shipped, but it was auth-gated and 302'd to `/login`. Reported as part of the EdgeFlow integration.

## [1.3.0] — 2026-04-29

Laravel 13 support. Constraint widened to `^11.0|^12.0|^13.0`; the suite stays green on every Laravel major in the matrix.

### Added

- **Laravel 13 support** ([#109](https://github.com/Real-Edge-FX/martis-package/issues/109)). `laravel/framework` now accepts `^13.0` alongside the prior `^11|^12`. Transitive constraints widened: `dedoc/scramble: ^0.12|^0.13`, `pestphp/pest: ^3.0|^4.0`, `pestphp/pest-plugin-laravel: ^3.0|^4.0`, `orchestra/testbench: ^10.11|^11.0`, `laravel/scout: ^11.1|^12.0`. Pest 1665/1666 green and Vitest 110/115 green against L13.7.0 + Pest 4.6.3 + PHPUnit 12.5.23 + Scramble 0.13.22 + Testbench 11.1.0.

### Fixed

- **`JsonErrorResponse::validation()` accepts the natural validator output again**. Laravel 13's stricter type stubs declare the translator helper as `string|array<int|string, mixed>|null`, which made every controller call site fail PHPStan. The signature now accepts `array<string, iterable<mixed>>` and stringifies non-string entries internally; behaviour for clean `array<string, list<string>>` callers is unchanged. Removes 10 PHPStan baseline entries.
- **`ListOverridesCommand` types narrowed**. Symfony Console option helpers return `string|string[]|bool|null`; the command now narrows to nullable strings explicitly. The action serialisation path uses `instanceof JsonSerializable` instead of `method_exists()` to give PHPStan a real type.
- **`SearchResolver::orderByRaw()` uses parameter binding**. The MySQL Scout-relevance fallback now binds the id list rather than concatenating it into the SQL string. Same behaviour, no manual quoting hazard, and PHPStan's `literal-string` requirement on `orderByRaw()` is satisfied.

### Changed

- **PHPStan baseline regenerated**. 220 → 213 entries (7 errors fixed structurally; the remaining 213 are unchanged from 1.2.x except for line shifts).

## [1.2.1] — 2026-04-29

Hotfix for an unintentional route exposure introduced by v1.2.0.

### Fixed

- **Scramble default routes are suppressed unconditionally** ([#108](https://github.com/Real-Edge-FX/martis-package/pull/108)). When v1.2.0 graduated `dedoc/scramble` from `require-dev` to `require`, Scramble's own service provider began auto-registering `GET /docs/api` and `GET /docs/api.json` in every consumer app, regardless of the `MARTIS_API_DOCS_ENABLED` toggle. Surfaced via `php artisan route:list` against the playground after the v1.2.0 upgrade. The `Scramble::ignoreDefaultRoutes()` call moved from `MartisServiceProvider::boot()` (too late — Scramble's own boot() runs first) to `register()`, and is now unconditional. Consumers that want Scramble's default behaviour can opt back in via `Scramble::configure()->expose(true)` from their own service provider.

## [1.2.0] — 2026-04-29

Doc-driven release. A full audit of `martis-docs` against the package source uncovered every place the documentation taught contracts that did not exist. We fixed the docs, shipped the small features the docs implied, and added the OpenAPI surface that was advertised but never wired. Six issues, six PRs, all gated behind a single release branch.

### Added

- **OpenAPI / Swagger UI surface** ([#95](https://github.com/Real-Edge-FX/martis-package/issues/95) / [#105](https://github.com/Real-Edge-FX/martis-package/pull/105)). New routes `GET /{martis-path}/api-docs` (Stoplight Elements UI) and `GET /{martis-path}/api-docs.json` (raw OpenAPI 3.1) powered by Scramble. Off by default — flip `MARTIS_API_DOCS_ENABLED=true` to opt in. Default middleware `['web', 'auth']` so only authenticated users reach the schema. `dedoc/scramble` graduated from `require-dev` to `require`.
- **`martis:list-overrides` artisan command** ([#99](https://github.com/Real-Edge-FX/martis-package/issues/99) / [#104](https://github.com/Real-Edge-FX/martis-package/pull/104)). Lists every component key the PHP layer expects the frontend `componentRegistry` to resolve (Resources, Tools, Actions with `Action::component()`). Filters via `--kind=resource|tool|action` and `--filter=<substring>`. Useful for "my override does not pick up" debugging — pair with `componentRegistry.keys()` in the browser devtools to compare expected vs registered.
- **`MARTIS_LOADER_DISABLED` env wrapper** ([#98](https://github.com/Real-Edge-FX/martis-package/issues/98) / [#101](https://github.com/Real-Edge-FX/martis-package/pull/101)). The only `config/martis.php` toggle without an `env()` wrapper now has one. Default `false` (loader stays enabled), so behaviour is unchanged for existing installs. Set `MARTIS_LOADER_DISABLED=true` in `.env` to opt out per environment without editing the published config.

### Changed

- **`docs/authentication.md` rewritten** ([#97](https://github.com/Real-Edge-FX/martis-package/issues/97) / [#100](https://github.com/Real-Edge-FX/martis-package/pull/100)). Removed the fictional `auth.google` block and the `auth.sso.url` model, plus the matching `MARTIS_AUTH_SSO_*` and `MARTIS_AUTH_GOOGLE_*` env vars — none existed in code. Replaced with the real schema: `auth.sso` is `enabled` + `providers`, with each provider nested under `auth.sso.providers.{name}`. Pointed readers at `sso.md` for the per-provider specifics. The `passwordReset`, `registration`, and `controls` blocks (which are real) remain documented.
- **`docs/configuration.md` env keys corrected** ([#97](https://github.com/Real-Edge-FX/martis-package/issues/97) / [#100](https://github.com/Real-Edge-FX/martis-package/pull/100)). Removed the per-action `MARTIS_DEFAULT_ROW_ACTION_VIEW/EDIT/DELETE` envs (only the master switch `MARTIS_DEFAULT_ROW_ACTIONS` exists; per-action visibility lives in `Resource::defaultRowActions(Request)`). Fixed `MARTIS_PREFERENCES_ALLOW_BRAND_COLOR` typo to the real `MARTIS_ALLOW_BRAND_COLOR`.
- **`docs/cache.md` per-user scoping clarified** ([#96](https://github.com/Real-Edge-FX/martis-package/issues/96) / [#102](https://github.com/Real-Edge-FX/martis-package/pull/102)). The previous wording said scoping was "automatic" and that `MartisCache` "includes the user identifier in the cache key." The service does neither; scoping is a controller convention. The doc now says so explicitly and points at the `OrdersController` example as the template for custom layers.

### Removed (security cleanup)

- **Operational docs relocated to `internal/`** ([#94](https://github.com/Real-Edge-FX/martis-package/issues/94) / [#103](https://github.com/Real-Edge-FX/martis-package/pull/103)). `docs/architecture/{decisions,stack}.md`, `docs/setup/{quickstart,troubleshooting}.md`, and `docs/release-process.md` now live under the new `internal/` folder at the repo root and are excluded from the public docs site mirror. They contained the playground hostname, default admin credentials, an internal IP, and the path to the deploy key — fine for the team, never appropriate for consumers. `internal/README.md` documents the rule and links the leak-sweep deny-list in martis-docs.
- **`docs/README.md` Quick Links sanitised** ([#94](https://github.com/Real-Edge-FX/martis-package/issues/94) / [#103](https://github.com/Real-Edge-FX/martis-package/pull/103)). Removed the table that exposed `martis.realedgefx.com` URLs and `admin@martis.local / password` credentials.
- **`docs/api/overview.md` placeholders sanitised** ([#94](https://github.com/Real-Edge-FX/martis-package/issues/94) / [#103](https://github.com/Real-Edge-FX/martis-package/pull/103)). Replaced server-private examples with neutral placeholders (`admin@example.com`, `<your-password>`).

### Notes for the docs site

`martis-docs.realedgefx.com` syncs from this repo's `docs/`. After v1.2.0 ships:

- The synced pages (auth, cache, configuration, overrides, api/overview) re-render with the corrected content automatically on the next `pnpm sync-docs`.
- The hand-authored `martis-docs/src/content/reference/api.mdx` should be deleted in a separate PR there so the sync map regenerates the page from the (now accurate) `docs/api/overview.md`.
- The hand-authored `martis-docs/src/content/getting-started/troubleshooting.mdx` already shipped a fix to use `martis:list-overrides` (martis-docs PR #9).

### Stats

- 6 issues closed (#94, #95, #96, #97, #98, #99)
- 6 PRs merged onto `release/v1.2.0` (#100, #101, #102, #103, #104, #105)
- New tests: 3 `LoaderConfigTest` + 5 `ListOverridesCommandTest` + 3 `ApiDocsRouteTest` = 11 specs
- `dedoc/scramble` promoted from `require-dev` to `require`

## [1.1.1] — 2026-04-28

Two bug fixes surfaced while bringing up the staging deploy at `martis.realedgefx.com`. No new features, no API changes — straight patch.

### Fixed

- **Resource pages now distinguish 5xx server errors from 404 / 403 / network failures** ([#91](https://github.com/Real-Edge-FX/martis-package/pull/91)). The five resource pages (`ResourceIndex`, `ResourceDetail`, `ResourceCreate`, `ResourceUpdate`, `ResourceLens`) used to render the same `<NotFoundPage />` for every query failure, including HTTP 500. Operators hitting a real backend bug saw the same "Resource not found" screen as someone mistyping a URL, with no signal that the backend had crashed. New `<ResourceErrorPage>` triages the error: 404 → existing copy, 403 → "Access denied", 5xx → "Server error" with the status code visible, network failure → "Cannot reach the server". The error response body is intentionally NOT rendered to avoid leaking trace info in production.
- **`BooleanGroup::minChecked()` / `maxChecked()` are now enforced on the backend** ([#92](https://github.com/Real-Edge-FX/martis-package/pull/92)). Both modifiers were only serialised to the frontend (the schema exposes them as a "0/11 min 1" counter), but no backend rule counted the checked flags. A tampered payload — or simply the form submitted with the count below the minimum — saved an empty map. `BooleanGroup` now overrides `buildRules()` to append a closure rule that calls `$fail()` with translated messages (`martis::messages.boolean_group_min_checked` / `max_checked`) when the count violates either bound. `requireAny()` and `requireAll()` are now real constraints rather than UI-only hints.

### Stats

- 1653 Pest tests passing (1 skipped, 0 failed) — +6 BooleanGroup specs, +7 ResourceErrorPage specs
- PHPStan level 8: 0 errors
- 2 PRs merged onto `main` (#91, #92)

## [1.1.0] — 2026-04-28

First minor release after v1.0.0. Three feature tiers (validated by source-grep audit), two pieces of UX polish, one repository-wide docblock refactor, and one config-wiring fix.

### Added

#### Field & Resource extensions

- **`Select::options(EnumClass::class)`** — `Martis\Fields\Select::options()` now accepts a class-string of a `UnitEnum` (backed or pure). The dropdown is derived from `EnumClass::cases()`: `value` from the case backing string (or case name for pure enums), `label` from `Str::headline($case->name)`. See [docs/fields.md](docs/fields.md).
- **`Resource::$relatableSearchResults`** — nullable int (default `null`) capping how many records a related field's picker (BelongsTo / MorphTo / BelongsToMany attach modal) returns. Request `?per_page=` is still honoured but never exceeds the cap. Default `null` keeps the request `per_page` clamped to 100. See [docs/resources.md](docs/resources.md).
- **`Image::thumbnail(Closure)` + `Image::preview(Closure)`** — both methods now accept a closure (in addition to the existing dimension shape for `thumbnail()`). Closure signature: `($value, Model $model)` returns the absolute URL. Use it for CDNs, image proxies (Imgproxy / Cloudinary), or private disks needing per-request signed URLs. `null` returns gracefully fall back to `$disk->url()`.
- **`Resource::$polling`, `$pollingInterval`, `$showPollingToggle`** — auto-refresh the index payload at a fixed cadence. Defaults to off; interval clamped to a 5-second floor via `Resource::resolvedPollingInterval()`. Surfaced in the schema payload as `polling`, `pollingInterval`, `showPollingToggle`.
- **`Metric::help(?string)`** — attach a tooltip rendered next to the metric title. Serialized as `help` in the metric payload; `null` (default) hides the icon entirely. See [docs/metrics.md](docs/metrics.md).

#### Generators

- **`martis:stubs` artisan command** — publishes every generator stub from the package into `stubs/martis/` in the consuming app. Edits to those files take effect on the next generator run with no cache to clear. New `Martis\Stubs\StubResolver` resolves each lookup against the project copy first, falling back to the bundled stub when no override exists. All 17 generator commands (resource, action, lens, field, dashboard, every metric type, filter, card, tool, component, theme, policy) now route through the resolver. See [docs/customizing-generators.md](docs/customizing-generators.md).

#### Frontend

- **Keyboard shortcuts registry** — new `resources/js/lib/keyboardShortcuts.ts` exposes `addShortcut()`, `disableShortcut()`, `listShortcuts()` (also reachable via `window.Martis.shortcuts`). Supports modifiers (`cmd`, `ctrl`, `mod`, `shift`, `alt`), two-key sequences (`g r`-style), input-focus suppression with `allowInInput` opt-in, and a built-in help overlay opened with `Shift+?`. The bundled `mod+k` and `/` palette shortcuts now route through the registry, so the help overlay always reflects the live set. Custom Tools and consumer plugins can register their own combos. See [docs/keyboard-shortcuts.md](docs/keyboard-shortcuts.md).

#### Config toggles

- **`config('martis.keyboard_shortcuts')`** — two new flags: `enabled` (master switch; when `false`, every `addShortcut()` call is a no-op including the bundled combos) and `helpOverlay` (independent toggle that skips the bundled `Shift+?` overlay registration only). Defaults to `true`/`true`. Env vars: `MARTIS_KEYBOARD_SHORTCUTS_ENABLED`, `MARTIS_KEYBOARD_SHORTCUTS_HELP_OVERLAY`.

### Changed

- **`martis:make-policy` renamed to `martis:policy`** — aligns with the rest of the generator naming (`martis:resource`, `martis:action`, …). The historical `martis:make-policy` name is preserved as a hidden alias on the same command, so existing scripts and tutorials continue to work.
- **`KeyboardShortcutsHelp` overlay redesigned** to match the design-system "Command Palette / Shortcuts" pattern. Replaces the PrimeReact `<Dialog>` with a hand-rolled overlay using the canonical Martis tokens (`var(--martis-overlay)` backdrop, `var(--martis-surface)` card with `var(--martis-radius-lg)` + `var(--martis-shadow-lg)`, hairline `var(--martis-border)` between rows, mono `<kbd>` chips on `var(--martis-hover)`). Adds Esc-to-close, click-outside dismiss, and a footer hint row mirroring the Command Palette.
- **Docblock standard alignment** — every method implementing an interface contract (or extending a base class declaring one) now carries only `/** {@inheritdoc} */`. The full contract documentation lives on the interface itself; implementations no longer duplicate `@param` / `@return` / `@throws` blocks. 209 redundant docblocks collapsed across 47 source files; 171 docblocks preserved where they carried unique value (PHPStan generics, `@deprecated` notes, multi-paragraph context). All casing normalised to lowercase. Net: 1104 lines removed, 518 added.
- **`FieldContract::buildRules()` widened** to `list<string|Rule|Closure>` — closure-based validation rules were already in use (`Url::buildRules` ships closures) but the contract type listed only `string|Rule`. Aligning the contract eliminates a real-world type drift PHPStan caught during the docblock sweep.
- **`CardContract`, `DashboardContract`, `LensContract` completed** — 5 + 5 + 5 methods that previously shipped without docblocks now carry full contract documentation so the new `{@inheritdoc}` references on implementations resolve to something useful.

### Fixed

- **`theme.allowToggle = false` now actually hides the theme picker.** The config flag has shipped since v0.x but was never read by the frontend (dead config). The Preferences overlay now drops the entire Theme section and the pre-login auth pages suppress the theme cycle button when the flag is `false`. See [docs/configuration.md](docs/configuration.md#theme).
- **TypeScript build passes again on a clean checkout.** A pre-existing `tsc` error on `resources/js/test-setup.ts` (`Cannot find name 'process'`) added in the v1.0 hotfix prevented `npm run build` from completing. The handler is preserved with a local `declare` shim that documents why `@types/node` is intentionally not pulled in for a frontend bundle.

### Stats

- 1647 Pest tests passing (1 skipped, 0 failed)
- 103 Vitest tests passing (5 skipped, 0 failed)
- PHPStan level 8: 0 errors
- 7 PRs merged (#81, #82, #83, #84, #85, #86, #87, #88) onto `release/v1.1.0`

## [1.0.0] — 2026-04-27

First stable release. The post-v0.10.0-rc1 cycle closed the documentation
audit, the v1.0 readiness checklist, and a handful of polish items found
during release-candidate baking.

### Added

- **Visual regression baseline** — `tests/e2e/visual-baseline.spec.ts`
  in the playground covers 7 canonical pages (dashboard, resource
  index, resource create, profile, system cache, custom tool, login)
  with `toHaveScreenshot()` assertions and committed PNG baselines.
- **README screenshot showcase** — six inline screenshots in the root
  README, sourced from the visual-baseline captures.
- **GitHub Actions CI** — new `.github/workflows/ci.yml` runs:
  - `pest` matrix: PHP 8.2/8.3 × Laravel 11.*/12.* (4 cells)
  - `phpstan` at level 8
  - `pint` style check
  - `vitest` JS test suite
- **PHPStan baseline** — `phpstan-baseline.neon` locks 220 pre-v1.0
  errors so CI fails on new errors without blocking the cut. Track the
  count down to zero post-1.0.
- **`docs/v1-roadmap.md`** — live readiness checklist used to drive
  this release.

### Changed

- **Documentation overhaul** — every doc reads as a self-contained
  Martis explainer. No comparative framing.
  - Removed historical / outdated docs (`release-v0.3.0-alpha.md`,
    `PROJECT_CONTEXT.md`).
  - Filled 4 missing config-key sections (`preferences`, `sticky_views`,
    `loader`, `impersonation`) in `docs/configuration.md`.
  - Filled 7 missing endpoint families (Dashboards, Tools, Preferences,
    Notifications, Cache Admin, Profile, Impersonation, Sync Field) in
    `docs/api/overview.md`.
  - Documented `RelationshipQueryResolver`, `RedirectAfter`,
    `MetricResult`, and `useUnsavedChangesGuard`.
- **`composer.json`** — `minimum-stability` bumped from `dev` to
  `stable` for the first stable release.
- **`composer.json`** — license corrected from `proprietary` to `MIT`
  (matches the LICENSE file content all along).

### Fixed

- **Route name collision** — RC-only routes registered under
  `Route::name('api.')->group(...)` and named `api.X` produced
  double-prefixed names (`martis.api.api.tools.index` etc). Affected
  7 routes from the v0.10.0-rc1 surface; dropped the inner `api.` so
  the canonical names are now `martis.api.tools.index`,
  `martis.api.impersonation.{status,start,stop}`,
  `martis.api.navigation`, `martis.api.command-palette`.
- **PHP 8.2/8.3 syntax compatibility** — `(new class { ... })::class`
  now wrapped in parens (8 occurrences across 4 test files). The
  unparenthesised form requires PHP 8.4+ and was breaking CI on the
  8.2/8.3 matrix cells.
- **Style baseline** — auto-fixed 80 files of pre-existing Pint drift
  (single_quote, ordered_imports, fully_qualified_strict_types, etc).
  Pure formatting; no semantic changes.
- **Playwright auth setup** — login form selector moved from
  `#email`/`#password` to `#login-email`/`#login-password` at some
  point; the test setups never picked up the rename and were silently
  timing out. Fixed in playground PR #6.

## [0.10.0-rc1] — 2026-04-27

First release candidate for v1.0.0. Closes the entire post-1.0 backlog
identified in the v0.9.0-beta planning round.

### Added — Custom Tools

- `Martis\Tools\Tool` base class + `Martis\Contracts\ToolContract` interface.
- Per-tool `boot()` lifecycle hook — runs once during host application
  boot (after Martis loads its own routes / views / config). Use it to
  register tool-owned routes, event listeners, gates, schedules, view
  namespaces. Idempotent; exception-swallowing so a broken tool cannot
  bring down the admin shell.
- `Martis\Tools\ToolServiceProvider` — abstract base for distributing a
  Tool as a standalone Composer package (auto-discovery via
  `extra.laravel.providers`).
- `Tool::publishes()` + `Tool::publishesAssets()` — per-tool config /
  migrations / lang / asset publishing through the standard
  `vendor:publish --tag=...` flow.
- `Martis::tools([...])` registration on the manager;
  `Martis::resolveTools(Request)` and `Martis::findTool(Request, $key)`
  for authorisation-filtered lookups.
- `MenuItem::tool($class|$instance)` factory + new `MenuItemType::Tool`
  enum case.
- REST surface: `GET /martis/api/tools` + `GET /martis/api/tools/{uriKey}`
  (404-when-denied so unauthorised users cannot probe which tools exist).
- `martis:tool` artisan generator with flags `--with-component`,
  `--component-key`, `--use-bundled`, `--menu-section`, `--icon`,
  `--force`. Prints "next steps" CLI block on success.
- Bundled `SystemStatusDemo` React component (`martis:tool:system-status-demo`)
  for zero-TSX consumer paths.
- React shell — `resources/js/pages/ToolPage.tsx` mounted at
  `/tools/:uriKey` resolves the registered React component via
  `componentRegistry`.

### Added — Impersonation

- Two-layer guard: `martis.impersonation.enabled` master switch
  (default `false`) + `martis-impersonate` Laravel Gate (no default —
  consumers define it explicitly in their `AuthServiceProvider`).
- `Martis\Impersonation\ImpersonationManager` with `start`, `stop`,
  `isActive`, `originalUser`, `currentTarget`, `enabled`, `guard`,
  `snapshot` methods.
- `Impersonation` facade.
- 3 endpoints: `GET /martis/api/impersonation/status`,
  `POST /start/{userId}`, `POST /stop`. Full 503 / 403 / 404 / 422 / 200
  error matrix; self-impersonation and chaining rejected by design.
- Built-in `ImpersonationBanner` React component, mounted in the layout
  shell. Boot-config short-circuit so disabled installs pay zero
  ongoing cost.

### Added — Documentation deliverables

- `docs/tools.md` rewritten as end-to-end walkthrough (architecture
  diagram, 5-min path, anatomy, lifecycle, distribution patterns,
  cookbook with 3 recipes, anti-patterns).
- `docs/tool-boot-patterns.md` companion: decision rubric for
  `Tool::boot()` vs `AppServiceProvider::boot()`.
- `docs/impersonation.md` — banner integration recipe + audit-log
  subclass example.

### Changed

- `MartisManager::bootTools()` runs every registered tool's `boot()`
  exactly once per request lifecycle.
- Test count — 1613 Pest + 84 Vitest = 1697 passing, 6 skipped, 0 failed.

### Fixed

- `ImpersonationBanner` skips its mount-time fetch entirely when the
  master switch is off at boot. Previously polled `/api/impersonation/status`
  on every authenticated page mount, adding ~1s under cold cache.
- `MartisManager::tools()` re-arms the boot lifecycle so tools registered
  after the first request (in tests, deferred providers) still fire.
- Test health metadata across the docs corrected — the historical
  "282 failing" was retired in v0.7.0-beta but never propagated to
  workspace metadata.

### Tests

- `tests/Feature/ToolsControllerTest.php` (13 tests) — registration,
  authorisation, boot lifecycle, exception swallowing, publishing,
  menu integration.
- `tests/Feature/ImpersonationControllerTest.php` (10 tests) — both
  guards, start error matrix, stop idempotency, snapshot shape.
- `tests/Feature/ParitySurfaceTest.php` — Reflection-based tripwire
  that locks the public surface of every v0.8 / v0.9 / v0.10 contract.
  18 tests, expanded to cover the new Tools + Impersonation surface.

## [0.9.0-beta] — 2026-04-26

Forms / Index UX, Locale Extensibility, Global Search, Visual UX, SSO.

### Added — Reactive forms (Task 09)

- `Field::dependsOn(['attr'], Closure)` reactive fields with server-side
  resolution via `POST /api/resources/{r}/sync-field`. Frontend
  debounces 200ms and uses `AbortController` so the latest value
  always wins.
- 13 closure-aware setters across the field API (`nullable`,
  `required`, `readonly`, `default`, `placeholder`, `help`, `tooltip`,
  `withLabel`, `rules`, `Select::options`, `MultiSelect::options`,
  `BooleanGroup::options`).
- Customisation hooks gain a `?Request` 4th argument
  (`resolveUsing` / `fillUsing` / `displayUsing`).
- `displayUsing(array)` accepts a chainable transformation pipeline.
- Context-aware validation: `creationRules()` / `updateRules()` layered
  on top of `rules()`; new `immutable()` flag (writable on create,
  readonly on update).
- 4 save variants: `Create & add another`, `Create & view list`,
  `Save & continue editing`, `Save & view list`.
- Reset-filters toolbar button (separate from Reset view).

### Added — Global Search (Task 11)

- Per-resource config: `globallySearchable()` accepts
  `bool|array{enabled?, limit?, min_query?}`.
- `searchOrderBy()` hook applied AFTER the search filter.
- "View all N matches in {resource}" palette overflow item.

### Added — Locale extensibility (Task 13)

- Per-key deep merge of consumer overrides.
- Configurable host-app namespaces via `martis.locales.app_namespaces`.
- Configurable fallback chain via `martis.locales.fallback_chain`.

### Added — SSO (Task 14)

- Pluggable provider contract (`SsoProviderContract`) with `AzureProvider`
  reference implementation.
- Identity-to-user resolver, role mapping (column / config / callable),
  permission adapters (Spatie / native / callable).
- Idempotent `martis:sso <provider>` generator.

### Fixed — Layout-flatten audit

- Closed five latent bugs across `HasManyController`,
  `MorphManyController`, `LensController`,
  `ResourceController::syncField`, and
  `ResourceController::relatableSearch`. Every code path that iterates
  `Resource::fields()` raw now flattens `Section` / `Panel` / `TabGroup`
  first.

### Fixed — Visual UX (Task 16)

- Loader dark-mode bug.
- Spinner under `prefers-reduced-motion`.
- Topbar search overlap.
- `applySorting` layout TypeError.
- Audio play-button on row click.

## [0.8.0-beta] — 2026-04-26

Sticky Views, Notifications, Cache control.

### Added — Sticky views

- Per-resource session storage of search / sort / filters / pagination
  / trashed-toggle / `filtersOpen`. Survives back-navigation; drops on
  resource change.
- `useStickyView()` hook in `resources/js/lib/`.
- Config: `martis.sticky_views.{enabled, scope, persist.*}`.

### Added — In-app notifications

- Topbar bell dropdown over Laravel's standard `notifications` table.
- `MartisNotification::make(title:, message:, level:)` inline factory
  (no `MartisNotifies` trait — consumer uses Laravel's standard
  `Notifiable`).
- REST: `/api/notifications`, `/unread-count`, `/read-all`,
  `/{id}/read`, single + bulk delete.
- Config: `martis.notifications.{enabled, poll_interval, max_in_dropdown}`.

### Added — Cache control surface

- `MartisCache::extend('name', enabled, ttl)` for host-app cache
  layers on top of the four built-ins (`metrics`, `navigation`,
  `dashboards`, `schema`).
- `/martis/system/cache` admin page (toggle / version / clear per type).
- Three control planes: config, env vars, runtime overrides
  (cache-stored, survives restarts).
- Per-request bypass via `X-Martis-No-Cache: 1` header or
  `?nocache=1` query.

## [0.7.0-beta] — 2026-04-25

### Added — Design-system phases 1–5
- **Phase 1 tokens** — 94 design tokens covering background layers,
  text / borders, accent / brand, semantic colours, interactive
  states, overlays / shadows, datatable, radius, typography, chart
  palette and file icons. Light + dark roots ship together.
- **Phase 2 Preferences panel** — per-user theme, accent, brand
  colour, density, locale, reduced-motion. Persisted in
  `martis_user_preferences`. Applied before first paint to prevent
  any flash. Optional named presets via `?preset=<name>` URL param.
- **Phase 3 Shell** — `.martis-shell` grid (sidebar / topbar / main),
  responsive collapse states, density-aware row heights, sidebar
  group polish (counts badge, expandable section toggle, kicker
  section heading).
- **Phase 4 Components** — buttons (primary / secondary / danger /
  success / warning / ghost + sizes + icon-only), badges (semantic
  + neutral + dot), avatars (xs / sm / md / lg / xl + circle /
  rounded / squared + stack), data table chrome.
- **Phase 5 Surfaces** — modals, peeks (BelongsTo card flip), trix
  editor chrome, action event JSON viewer, OfMany aggregate tile,
  through-relationship breadcrumb hint.

### Added — Authentication redesign (Fase 6)
- Six pre-login surfaces share a unified `AuthFrame.tsx` shell —
  Login, Register, 2FA challenge, 404, 403, 500.
- `auth.controls` config with independent toggles for the theme
  cycle button and language picker on every guest surface.
- 6-cell OTP row with auto-advance, paste-to-fill, 30s countdown,
  and a backup-code toggle that swaps the OTP grid for a recovery
  code input.
- Configurable SSO / Google / password reset / registration flows
  via `config/martis.auth`.

### Added — Command Palette (⌘K / Ctrl+K)
- Global overlay with four ordered sections: Resources, Actions,
  Recent activity, Records (debounced cross-resource search).
- Keyboard-first: `↑` / `↓` / `↵` / `esc`, `⌘K` / `Ctrl+K` to toggle
  even while typing.
- New `GET /api/command-palette` endpoint behind the standard
  Martis auth + 2FA + locale middleware stack. Standalone resource
  actions auto-register in the palette.

### Added — DataTable column widths
- Per-type column-width heuristics (Id 80px, Email/Url 280px max +
  truncate, Date 140px, title column 220px min). Explicit
  `->width()` / `->maxWidth()` / `->minWidth()` / `->truncate()`
  always wins. Global opt-out via
  `config('martis.index.column_defaults', false)` for apps that
  prefer the pre-v0.7.0 fully auto-sizing behaviour.

### Added — Sidebar polish
- Per-resource navigation count badges, polled at the configurable
  `config('martis.navigation.poll_interval')` interval.
- Top-level section headings (`group()` value) render as kickers.
- Group rows are now toggleable; default-open state remembered in
  `localStorage`.

### Added — Page title customization
- Resources can override the document title and breadcrumb segment
  via `pageTitle()` and `pageSubtitle()`.

### Added — Dashboard extras
- **Sparkline mode on `TrendCard`** — backend opt-in (`sparkline: true`
  on the result) renders an inline SVG sparkline + delta pill instead
  of the full Chart.js panel. New reusable `<Sparkline>` helper.
- **`ActivityFeedMetric`** + `ActivityFeedResult` — chronological event
  stream (actor / verb / target / time + coloured Phosphor avatar
  tile). Generator: `php artisan martis:activity-feed`.
- **`EndpointTableMetric`** + `EndpointTableResult` — compact HTTP
  route table with method chips, mono numeric columns, and a thin
  share bar. Generator: `php artisan martis:endpoint-table`.
- **`MetricType` enum** gains `ActivityFeed` and `EndpointTable`
  cases, wired end-to-end through `MetricCard` + the React layer.
- **`.martis-dash-kpis` / `.martis-dash-grid`** layout helpers (4-col
  KPI row, 3-col body grid, with `.span-2` / `.span-3` cell helpers
  and a 1100px breakpoint that collapses both).

### Added — Audio field redesign
- Custom on-brand player replaces `<audio controls>`: accent
  play/pause button, waveform OR progress bar, mono current/total
  timestamps, download affordance. Looks consistent in light and dark
  themes.
- Drop-zone empty state with dashed border, Phosphor MusicNote icon,
  drag-and-drop support.
- All strings via `messages.php` (`audio_empty_title`,
  `audio_empty_hint`, `audio_browse`, `audio_replace`, `audio_remove`,
  `audio_play`, `audio_pause`, `audio_download`) in en / pt_PT /
  pt_BR.

### Added — KPI typography + status dot
- New `.martis-kpi-label` / `.martis-kpi-label-icon` /
  `.martis-kpi-label-text` (12px uppercase muted with leading icon).
- New `.martis-kpi-value` (28px semibold + tabular numerals; collapses
  to 24px under `[data-density="dense"]`).
- New `.martis-kpi-delta` (`is-up` / `is-down` trend variants) with
  `.martis-kpi-delta-sub` for "vs previous" copy.
- New `.martis-status-dot` + `.martis-status-pulse` (canonical Live
  indicator). Reduced-motion aware via `[data-reduced-motion="true"]`
  and the `prefers-reduced-motion` media query.
- `MetricCard` Live badge unified with the dot.

### Added — Detail surfaces, drawer, and form density
- **Detail panel rows** — `.martis-detail-panel`,
  `.martis-detail-row`, `.martis-detail-label`, `.martis-detail-value`
  with an `is-drawer` variant + density overrides.
- **Detail kicker** — `.martis-detail-kicker` for kicker/eyebrow text.
- **Drawer shell** — `.martis-drawer-shell/head/head-main/head-row/
  title/subtitle/icon/actions/foot` primitives.
- **Form density helpers** — `.martis-form-body`,
  `.martis-form-stack`, `.martis-form-grid` for create/update + drawer
  forms; density overrides bring `martis-input` to 30px and tighten
  `.martis-input-wrap` gap under dense mode.
- **Tabs / Segmented / Skeleton** raw classes — `.martis-tabs`,
  `.martis-segmented`, `.martis-skeleton` — promoted from one-off
  surface usage to public utility classes.
- **MultiSelect chips** — code-like values render mono, regular values
  inherit the design-system badge family.

### Added — Avatars, badges, shell micro-fixes, auth controls
- **Avatar palette** — 16 deterministic hues declared as
  `--martis-avatar-1..16` (identical hex across light/dark) plus a new
  `lib/avatarPalette.ts` helper exposing `avatarColorForSeed(seed)` /
  `avatarHexForSeed(seed)`. `AvatarField` and `UiAvatarField` use the
  palette as the zero-config fallback when the backend doesn't supply
  a colour.
- **Avatar fallback icon** — empty initials render a muted UserIcon
  inside a new `.martis-avatar-fallback` chip instead of `?`.
- **Badge sizing** — height 20 → 22, font-weight 600 → 500, gap 4 →
  5, plus tabular numerals so trailing counts read evenly.
- **`.martis-notif-dot`** — public, reusable notification indicator
  any icon-button can stamp on top of itself.
- **Sidebar caret semantics** — closed = caret-right, open rotates
  90deg to point down (matches the design system).
- **`auth.controls.theme` / `auth.controls.locale`** — independently
  toggle the theme cycle button and language picker on every pre-login
  surface. Env vars: `MARTIS_AUTH_CONTROL_THEME`,
  `MARTIS_AUTH_CONTROL_LOCALE`.

### Changed
- **Drawer default width** bumped 560 → 720 (expanded 800 → 960) so
  relationship toolbars (Create / Search / Per-page / Trashed) fit
  inside the drawer at default size.
- **Detail page right rail** — refactored as a stacked detail panel
  with kicker support.
- **Sidebar section heading** colour switched from
  `--martis-text-faint` to `--martis-text-muted` (spec).

### Fixed
- **Generator stub paths** — 7 `Make*` artisan generators
  (`Action`, `Filter`, `Dashboard`, `Partition`, `Progress`, `Trend`,
  `Value`) had a wrong `__DIR__` chain that resolved to a path outside
  the package, throwing `FileNotFoundException` on first invocation.
- **`filter.date.stub`** — generated subclasses crashed at runtime
  because they passed the new `ComparisonOperator` enum directly to
  `whereDate()`. Stub now calls `->value` on it.

## [0.6.0-alpha] — 2026-04-19

### Task 07 — Field coverage completion (PRs A → E)

#### Added — Fields
- **`Repeater` + `Repeatable`** — full repeatable-row support. JSON
  storage (`asJson`), HasMany storage with 3-way upsert (`asHasMany` +
  `uniqueField`), multi-type Add menu, per-row validation
  (`attribute.0.field` errors), confirm removal. See [docs/repeater.md](docs/repeater.md).
- **`HasOneOfMany` / `MorphOneOfMany`** and the promoter API
  `HasOne::ofMany()` / `MorphOne::ofMany()` with `latestByTimestamp()`.
- **`HasOneThrough`** and **`HasManyThrough`** relationship fields.
- **`Stack` + `Line`** composite display field.
- **`Slug`, `PasswordConfirmation`, `Timezone`** form fields.
- **`BooleanGroup`, `Avatar`, `UiAvatar`, `Audio`** choice/media fields.
- **`Icon` field ⭐** — Phosphor icon picker (100% Martis differential).

#### Added — ⭐ Martis differentials
- **Repeater differentials** (5):
  - `asPolymorphic($typeColumn, $payloadColumn)` — single-table
    storage discriminated by row type, useful for page-builder rows.
  - `dependsOn([attributes])` — exposes parent record attributes to
    every field inside a row.
  - Cardinality + UX — `minRows`, `maxRows`, `collapsible`,
    `collapsedByDefault`, `reorderable(?$orderColumn)` with native
    HTML5 drag-and-drop.
  - `Repeatable::icon/color/title/badgeCount` — dynamic row header
    decorations (template strings or closures).
  - `rowTemplates()` pre-filled presets in the Add menu, one-click
    duplicate row, bulk-paste modal that parses TSV/CSV/JSON (with
    `->hideDuplicate()` / `->hideBulkPaste()` opt-outs).
- **HasOne::ofMany differentials** (3) — "Latest-of-N" pill, aggregate
  tile via `aggregateVia(AggregateFunction, column)`, promoter API.
- **Through-relationship differentials** (3) — `throughBreadcrumb()`
  tooltip, `countBadge()` index badge, `HasManyThrough` inherits
  `HasMany` toolbar/filter controls.
- **Stack differentials** (3) — Line `variant` enum, `subtitleFrom`,
  compact-divider mode.
- **Unsaved-changes guard** (Camada A+B) with `UnsavedChangesConfig`
  and full-page form guard via `useUnsavedChangesGuard`.
- **Action event JSON viewer** — Original/Changes rendered with
  syntax highlighting via the `Code` field.

#### Added — Enum-first API
Finite strings are now PHP enums throughout the public API:
`SortDirection`, `FilterType`, `MetricType`, `MetricRange`,
`MetricWidthPreset`, `TrendPeriod`, `TrashedFilter`, `DefaultRowAction`,
`LineVariant`, `BadgeType`, `MenuItemType`, `RepeaterStorage`. Contracts
(`ResourceContract`, `FilterContract`, `MetricContract`) updated to
return enums; `ModalSize::Fullscreen` replaces the boolean `fullscreen`
flag on Actions.

#### Added — Developer experience
- Field-level **`tooltip()`** with HTML content support.
- Effective-per-page resolution — if a user-submitted `perPage` is not
  in `perPageOptions`, the controller clamps to the first allowed value.
- Modal history locks audit — `useModalHistoryLock` (hard) vs.
  `useModalHistoryBackToClose` (soft). TrixField ImageModal upgraded.
- Drawer Detail → Update swap in place (no route change).
- Drawer footer buttons aligned to modal button classes.

#### Fixed
- Validator `customAttributes` wired package-wide so validation messages
  render the field label, not the underlying column name.
- BelongsToMany/MorphToMany "attach" SQL ambiguity on `id` column.
- Attach modal overlay full-page coverage via portal.
- Unsaved-changes dialog re-opening after discard — cleanup now checks
  the history sentinel before popping.
- `isDirty` false-positives when fields normalise on mount (rebases
  baseline 250 ms after the record loads).
- TeamMember, Skill, Tag seeds and playground extensions.

#### Documentation
- New [docs/repeater.md](docs/repeater.md) covering all storage modes,
  the Repeatable API and every ⭐ Martis differential with examples.
- differentials.md — dedicated Repeater section (D1–D5).
- fields.md — Repeater entry in the field-type reference.
- README.md — Repeater link in the documentation index.

#### Tests
- **1298 Pest tests** (11 new `RepeaterFieldTest`) — 3054 assertions.
- **8 Playwright specs** for the Repeater showcase
  (`repeater-field.spec.ts`).
- Playground showcases the JSON Repeater (Marcos & Fases) and the
  polymorphic Repeater (Blocos do Projeto) on `Project`.
