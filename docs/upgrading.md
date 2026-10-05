# Upgrading

> **These docs describe Martis v2.** Martis v1.x read `Select` and `MultiSelect` options as `[label => value]`; v2.0 and later read `[value => label]`, Nova's order. If your app still runs v1.x, flip the option arrays in the examples, or upgrade with the steps below.

The sections below list the breaking changes of each major version and what to change in an app.

## Upgrading to v2.5.0 from v2.4.x

v2.5.0 serves the docs MCP server through the official [`laravel/mcp`](https://github.com/laravel/mcp) package instead of `php-mcp/server` and ReactPHP. `php-mcp/server` kept `symfony/finder` below 8 and, through `react/http`, `psr/http-message` at 1, so `composer require martis/martis` on a fresh Laravel 13 app had to downgrade `symfony/finder` and `guzzlehttp/guzzle`. It no longer does.

### Requirements

- Laravel 12 apps need `laravel/framework` 12.41.1 or later (`laravel/mcp` requires `illuminate/json-schema` ^12.41.1). Laravel 13 is unaffected.

### The docs MCP server

- `php artisan martis:mcp-serve` is removed. Over stdio an agent now spawns `php artisan mcp:start martis-docs`; over HTTP the server is a route of your app. Run `php artisan martis:agents --with-mcp` again to rewrite your agents' MCP config.
- The default transport is now `stdio`. An `.env` written by an earlier `martis:agents` usually carries `MARTIS_MCP_TRANSPORT=http`: with it, Martis registers the route `POST /{MARTIS_PATH}/mcp` (`/martis/mcp` by default), which outside the `local` environment requires `MARTIS_MCP_HTTP_TOKEN`. Set `MARTIS_MCP_TRANSPORT=stdio` (or delete the line) if you do not need HTTP.
- The default HTTP path changes from `/mcp` to `/{MARTIS_PATH}/mcp`, and the URL `martis:agents` writes is built from `APP_URL` instead of a host and port.
- `MARTIS_MCP_HOST`, `MARTIS_MCP_PORT` and `MARTIS_MCP_HEALTH_PORT` are removed with the standalone daemon and its `/health` endpoint. Delete them (commented placeholders an earlier `martis:agents` wrote can stay or go). `martis:agents` refuses to wire the MCP while one of them is set.
- A systemd unit or a docker-compose service that ran `martis:mcp-serve` as a daemon is no longer needed: the app itself serves the MCP.
- The three tools keep their names, inputs and payloads.

## Upgrading to v2.4.0 from v2.3.x

v2.4.0 closes a security audit. Several defaults now fail closed. Go through the list below: the first five concern most apps. Then republish the config, the language files and the assets:

```bash
php artisan vendor:publish --tag=martis-config --force   # or add the new keys by hand
php artisan martis:publish-assets
php artisan optimize:clear
```

### Define the `viewMartis` gate

When the app defines no `viewMartis` gate, the panel now answers `403` outside the `local` and `testing` environments, as Nova's `viewNova` does. Define it in `app/Providers/MartisServiceProvider.php`; the stub `martis:install` publishes ships it active:

```php
Gate::define('viewMartis', fn ($user) => app()->environment(['local', 'testing']) || in_array($user->email, [
    // 'admin@example.com',
], true));
```

To keep a non-local environment open without a gate, list it in `MARTIS_PANEL_OPEN_ENVIRONMENTS` (default `local,testing`). A resource without a policy stays open to every panel user, as in Nova, and now logs a warning outside those environments. See [Authorization → Panel access](authorization.md#panel-access-viewmartis).

### Set `APP_URL`

Every emailed link (magic link, password reset, invitation, email verification, email change) is built from `APP_URL`, never from the request's host. Set it to the URL the panel is served on, as an absolute `http(s)` URL; Martis throws a clear error otherwise. Signed links are signed over that host, so serve them there.

### File uploads refuse active content

A `File` field answers `422` for HTML, SVG, XML, script and PHP files (`html`, `htm`, `xhtml`, `shtml`, `svg`, `svgz`, `xml`, `xsl`, `js`, `mjs`, `php`, `phtml`, `phar`, `php3`-`php8`, `pht` and similar). A field that must accept one lists it in `acceptedTypes()` or calls `allowActiveContent()`; serve such files from another origin or as downloads. `Image` is unchanged.

### Lenses start from the resource's index scope

A lens query now applies the resource's `scopes()` and `indexQuery()`, as the index does, for its rows, actions, filters, counts and cards. A lens that deliberately shows what the index hides (an all-tenants view for staff) opts out with `public static bool $withoutIndexScope = true;` (see [Lenses](lenses.md)).

### A BelongsTo without `relatedResource()`

It now resolves the resource of the related model and runs the relatable checks, `viewAny` and the policies on writes. A related model with no registered resource is refused (`422`); a `relatedResource()` key that is not registered throws, naming the field and the key. Declare `relatedResource()` or register the resource.

A `BelongsTo` or `MorphTo` value whose id is a JSON boolean, a non-integer number, a list or a nested map answers `422` and is never written (a boolean `true` used to reach the foreign key as `1`, past the relatable checks).

### Magic links

The emailed link opens a confirmation page, and the sign-in is `POST /api/auth/magic-link/consume`. Links mailed before the upgrade still work: a `GET` of the old URL is redirected to the confirmation page. A custom client posts `email` and `token` (and `replace_session: true` to replace another user's session).

### Profile email change

`PATCH /api/profile` needs `current_password` when the email changes and answers `pending_email`: the address changes when the user follows the link sent to it (`MARTIS_PROFILE_EMAIL_CHANGE_TTL`, 60 minutes by default). A custom profile client sends the password and shows the pending state. The mailed link opens a confirmation page and changes nothing; the change is a CSRF-protected `POST` to the same signed URL (a client that followed the link with a `GET` must now post). Asking for the mail is limited to 5 per hour per user and per address (`MARTIS_PROFILE_EMAIL_CHANGE_ATTEMPTS`, `0` turns it off), answering `429` past it.

### SSO

- `identity_match_attribute => 'email'` adopts a local account only when its email is verified (`email_verified_at`) or `martis.auth.registration.enabled` is `false`. With registration open, an existing row with an unverified address now refuses the SSO sign-in (`sso_account_unverified`) instead of being adopted: verify it, link it by `external_id`, or remove it. A model without an `email_verified_at` column cannot prove a verified address, so with registration open it is refused too: add the column (and verify the rows), or use `identity_match_attribute => 'external_id'`. With registration disabled nothing changes.
- Sign-in no longer sets a remember-me cookie. Set `'remember' => true` on a provider to restore it; access then outlives the IdP session. Remember-me cookies issued while SSO always remembered (v2.3 and earlier) are **not revoked** by the upgrade: they stay valid until they expire (`auth.guards.{guard}.remember`, 576000 minutes by default) or the user's `remember_token` rotates, so access removed at the IdP does not end those sessions. To end them at once, rotate the `remember_token` column of the SSO users (`UPDATE users SET remember_token = NULL`) or sign them out.
- The SSO origin cookie written by v2.3 (no server-held nonce) no longer counts: a user who comes back through a remember-me cookie loses the SSO origin until the next SSO sign-in, so the forced password change gate may meet them as a password user, and the federated logout is skipped.
- Azure: set `tenant` (`AZURE_TENANT_ID`) so identities of other tenants are rejected; `martis:sso azure` scaffolds it. A multi-tenant registration should match accounts by `external_id`.

### Two-factor authentication and sign-in limits

- Users with 2FA meet the challenge once after the upgrade (the old session flag is not trusted).
- Regenerating recovery codes needs `current_password`; the user is emailed.
- New limits, all configurable: `MARTIS_LOGIN_THROTTLE_EMAIL_ATTEMPTS` / `_MINUTES` (100 wrong passwords in 15 minutes per account, whatever the IP, counted until a right one clears them; the magic-link request has a bucket of its own; 0 turns it off), `MARTIS_2FA_THROTTLE_ATTEMPTS` (5), `MARTIS_2FA_THROTTLE_IP_ATTEMPTS` (15), `MARTIS_2FA_THROTTLE_MINUTES` (1), `MARTIS_2FA_LOCKOUT_ATTEMPTS` (5, 0 turns it off) and `MARTIS_2FA_LOCKOUT_MINUTES` (15).
- Forgot password answers `200` for an unknown email too.
- The `id` of a row of the browser sessions API is now an opaque handle (an HMAC of the session id), sent back to revoke a session; the raw session id revokes nothing.

### Impersonation

The `martis-impersonate` gate receives the target as its second argument; a one-argument closure keeps working, and a two-argument one can refuse a target that outranks the operator. A closure that needs the target refuses an id that does not exist. `canImpersonate()` on the operator and `canBeImpersonated()` on the target are honoured. `Impersonation::start()` throws `ImpersonationRefusedException`, still a `RuntimeException`.

### Queued actions with a secret field

A queued action (`ShouldQueue`) whose `Password` or `sensitive()` field has a value is dispatched with an encrypted payload: workers need the app's `APP_KEY` to read it (they already do for sessions and any `ShouldBeEncrypted` job). Jobs already queued before the upgrade keep their plain payload.

### Audit log of a hard-deleted record

The values in the events of a record that no longer exists (hard-deleted) are masked for everybody. Before, they were judged on a model hydrated from the event (or, in earlier builds, skipped `view`), so a tenant fence kept in a global scope did not hold after the delete. To let some viewers read them, define the record-independent gate: `Gate::define('martis-view-deleted-audit', fn ($user, string $resourceClass, string $uriKey) => $user->is_auditor)`. The `viewAny` of the resource and the field visibility still apply when it allows. Soft-deleted records are unchanged: they are still loaded and judged.

### Plan locks

`lockedFor()` and `requirePlan()` now answer `403` with `locked: true` and the `lock` on every data endpoint, the dashboard card compute route included (it answered `200 { locked: true }`). A route of your own that names a Martis resource, dashboard or tool adds the `martis.gate` middleware to be gated the same way.

### Relationships, actions and fields

- Relationship flags (`canCreate`, `canUpdate`, `canDelete`, `canAttach`, `canDetach`) are enforced by the server: a request through a relationship whose flag is off answers `403`.
- A Trix or Markdown field that accepts attachments declares `withFiles()` (with a disk when not the panel's storage disk). Schedule `martis:attachments:prune`. A custom caller of `POST /api/attachments/upload` sends the resource, field and record.
- A hook that wants its message shown to the user throws `Martis\Exceptions\UserFacingException`; any other exception gives a generic message outside `app.debug`.
- A custom BooleanFilter `apply()` receives only the keys its `options()` declares: read explicit keys, never use them as column names.
- A metric `?range=` outside `ranges()` falls back to the default; Sparkline writes above `maxPoints` or with non-numeric items fail validation.
- `ActionResponse::redirect()`, `download()` and `openInNewTab()` refuse `javascript:`, `data:` and other non-http(s) URLs.
- A custom picker that read another column from a relatable row names it as the field's title or subtitle attribute; the context-free relatable endpoint serialises only the related resource's declared `titleAttribute()` (when sent as `title_attribute`) and ignores `subtitle_attribute` and any other column.
- `api_docs.middleware` defaults to `null`, the Martis stack; a published `['web', 'auth']` is completed with the Martis guards.
- Field `help()`, HTML tooltips and gate messages (`messageHtml`) lose `style`, `id`, `name`, forms and form controls when rendered (scripts, handlers and unsafe URLs were already removed). An inline `style="color:red"` no longer applies: use a `class` (kept for this developer-written markup) or plain text.
- Markdown and Trix content keeps only the classes legitimate content carries (`language-*` on Markdown code, the Trix attachment classes) and loses `label`, `fieldset`, `legend`, `datalist`, `output` and every `<input>` but the disabled task-list checkbox. A document that relied on utility classes (`class="text-red-500"`) loses them.
- A notification `action_url` and an external menu link in the command palette may be `mailto:` / `tel:` and open in a new tab, as in the sidebar.

### Extensions

- An element that set `data-pr-tooltip-html="true"` by hand shows its text literally: use `{...htmlTooltip(text, position)}` or `trustHtmlTooltip(el)` from `@martis/runtime`.
- To run the panel under a Content-Security-Policy, set a nonce (`Vite::useCspNonce()`) and send `script-src` / `style-src` with it: see [Configuration → Content Security Policy](configuration.md#content-security-policy-v240).
- `vite.extensions.config.ts` is copied once: set `build.sourcemap` to `false` in yours and delete `public/vendor/martis-user/extensions.js.map`.
- Build API paths with `apiPath` / `pathSegment` from `@martis/runtime`, not `encodeURIComponent()`: Laravel decodes `%2F` before routing, so an encoded slash in a record key could reach another route. Refresh the shim with `php artisan vendor:publish --tag=martis-extension-shims --force` to import the named exports (the default export `runtime.apiPath` works without it). A path value holding a literal `%2F` now throws `ApiError` (400) and sends nothing.

### Scaffolds

Generated code is yours and keeps its old behaviour. Review policies generated by `martis:policy` before v2.4.0 (they allowed everything). Regenerate the invitation and role actions (`martis:invitations --force`, `martis:roles --force`) or apply their role check by hand; republish customised stubs (`martis:stubs`). `martis:user --password` warns: use `--password-stdin`.

## Upgrading to v2.3.0 from v2.2.x

Nothing is required unless one of the changes below concerns your app.

### Password rules follow `Password::defaults()`

Every Martis surface that sets a password validates with your app's `Password::defaults()`: Profile, registration, password reset, invitation accept and `martis:user` (see [Authentication → Password rules](authentication.md#password-rules)). Without app defaults:
- **Profile** accepts any password of 8 or more characters. It required mixed case and numbers; declare them to keep that: `Password::defaults(fn () => Password::min(8)->mixedCase()->numbers())`.
- **`martis:user`** refuses a password shorter than 8 characters. A boot script that sets a shorter one exits 1.

**A `Password::defaults()` that Laravel cannot use now fails loudly.** `Password::default()` replaces anything that is not an `Illuminate\Contracts\Validation\Rule` with `Password::min(8)`, silently: a closure that returns an array of rules, a string, or a `ValidationRule` (what `php artisan make:rule` generates) was enforced nowhere. Martis now throws an `InvalidArgumentException` naming what the closure gave. Return a `Password` rule (`Password::min(12)->mixedCase()`) or another `Rule`. A closure that returns `null` still means "unset".

The `require*` methods of the `Password` field check Unicode classes, as Laravel's `Password` rule does: an accented capital counts as uppercase, and an accented letter no longer counts as a symbol.

### `martis:user` reads the password from standard input

`--password=...` stays, but `ps` shows it. Pipe it instead:

```bash
printf '%s\n' "$MARTIS_ADMIN_PASSWORD" | php artisan martis:user --no-interaction --email="$MARTIS_ADMIN_EMAIL" --password-stdin --if-missing
```

### Action responses

- **`ActionResponse::visit($path)`** navigates inside the SPA, to `$path` below the Martis base path, with its `$params` as the query string. It used to load the page in full and drop the params. Drop the base path from your calls: `visit('/martis/resources/users')` becomes `visit('/resources/users')`.
- **`modal()`** shows its component and **`emit()`** emits on `martisEventBus`; both used to show the generic success toast. Register the component of a `modal()` answer (see [Actions → Custom modal responses](actions.md#custom-modal-responses)).
- **`download()`** names the file after `$filename`.
- **The built-in bus events fire.** `martis:record-created/updated/deleted/restored` and `martis:action-executed` were documented and never emitted. A listener that never ran will now run. Their `id` (and the `ids` of `martis:action-executed`) is always a string, whatever emitted it: compare with `String(record.id)`, not a number.

### Action events honour `$visible`

A model that declares `$visible` logs only those attributes in its action events (`$hidden` ones stay masked).

### Forced password change (opt-in)

New, off by default: see [Authentication → Forced password change](authentication.md#forced-password-change). An app with a published `config/martis.php` must copy the new `password_change` block into `auth` first: without it `MARTIS_AUTH_PASSWORD_CHANGE_ENABLED` is never read. A Tool whose routes pass their own middleware list gets a log warning when the list leaves out `martis.password.changed` while the gate is on; leave the list out, or add the alias.

`password` is now a reserved first segment for [registered routes](custom-pages.md#path-rules): the change page lives at `/{martis-path}/password/change`. A page you registered under `password/...` is refused with a console error; move it under another first segment.

### Refresh the extension scaffold

v2.3.0 adds `PasswordChangeRequiredError` and the type `ActionResponseModalProps` to `@martis/runtime`, and `.shims/i18next.d.mts`, the i18next types the shims share. Refresh the shims:

```bash
php artisan vendor:publish --tag=martis-extension-shims --force
```

Then add two entries to the `paths` of your `tsconfig.extensions.json` (or republish it with `php artisan martis:install --force`, which rewrites the whole scaffold):

```json
"i18next": ["./resources/js/martis-extensions/.shims/i18next.d.mts"],
"@martis/testing": ["./vendor/martis/martis/dist/testing/testing.d.mts"]
```

Your own `resources/js/martis-extensions/index.ts` is not refreshed either. To override the new [forced password change](authentication.md#forced-password-change) page, add its key to `OVERRIDE_KEYS`, next to `EmailVerifyNoticePage`:

```ts
PasswordChangePage: 'auth:password-change',
```

Without it the override builds but never renders: `php artisan martis:component --type=password-change-page` writes the component, then fails naming this line.

### Test your extensions

New: [Testing extensions](testing-extensions.md). The kit needs your app's React on the panel's major. `martis:install` writes `^18 || ^19`, which gives a fresh app React 19, so run `npm install react@^18 react-dom@^18` first: a test run on another major fails before any test, naming that command. A test that creates its own i18next instance also needs `npm install --save-dev i18next@26.0.4`.

## Upgrading to v2.2.0 from v2.1.x

Nothing is required.

### New names on `@martis/runtime`

v2.2.0 adds `routeRegistry`, `useDynamicCrumb`, `ForbiddenPage` and `NotFoundPage` (see [Custom pages](custom-pages.md)). `martis:install` publishes the runtime shim once, so an existing extension imports them by name after refreshing it:

```bash
php artisan vendor:publish --tag=martis-extension-shims --force
```

Until then, read them from the default export: `import runtime from '@martis/runtime'`, then `runtime.routeRegistry`.

### Auth page overrides now render

An override registered under `auth:login`, `auth:register`, `auth:forgot-password`, `auth:reset-password`, `auth:email-verify-notice` or `auth:invitation-accept` never rendered: the router read those keys before the extension bundle loaded. From v2.2.0 it renders. If your extension still registers one you no longer want (for example `resources/js/martis-extensions/overrides/LoginPage.tsx`, which `martis:component --type=login-page` writes), delete it and run `npm run build:extensions`.

## Upgrading to v2.1.1 from v2.1.0

Nothing is required. The optional checks below concern apps set up with an earlier version.

### Migrations that add a column to Spatie's tables

`martis:roles --with-categories` and `martis:sso --with-migration` could date their migration at or before Spatie's `create_permission_tables`, which Spatie dates one second ahead. On a database built from scratch (CI, a new machine, a first deploy), the Martis migration then ran first, found no table and skipped its column. Check the order:

```bash
ls database/migrations | grep -E 'create_permission_tables|add_category_column_to_permissions_table|_group_name_to_roles_table'
```

If a Martis file is listed before `create_permission_tables`, rename it with a later timestamp, for example the current date and time (`2026_10_01_120000_add_category_column_to_permissions_table.php`). Laravel runs it once more under the new name: where the column exists, its `hasColumn()` check makes that run a no-op; where it is missing, the column is added. The old name stays recorded in the `migrations` table and the renamed file is recorded in a new batch, so do not roll that batch back with `migrate:rollback`: its `down()` would drop the column. On a database that already has the column, you can instead rename the row in the `migrations` table to the new file name.

### The extensions Vite config

`vite.extensions.config.ts`, which `martis:install` publishes, resolved its paths from `__dirname`, so with Vite 8 every `npm run build:extensions` warned that Vite's native config loader (announced as the default of a future major) does not support it. New installs resolve the paths from `import.meta.url`, which works with Vite 4 to 9, both config loaders and any Node version. To update an existing app, edit the file:

```diff
 import path from 'node:path'
+import {fileURLToPath} from 'node:url'
+
+// The directory of this file. `__dirname` only exists while Vite bundles
+// the config; Vite's native config loader does not define it.
+const rootDir = fileURLToPath(new URL('.', import.meta.url))
 
-const shimsDir = path.resolve(__dirname, 'resources/js/martis-extensions/.shims')
+const shimsDir = path.resolve(rootDir, 'resources/js/martis-extensions/.shims')
```

and, in `build.lib`:

```diff
-      entry: path.resolve(__dirname, 'resources/js/martis-extensions/index.ts'),
+      entry: path.resolve(rootDir, 'resources/js/martis-extensions/index.ts'),
```

Nothing breaks until Vite switches loaders, so the edit can wait. `php artisan martis:install --force` also writes the new file, but it rewrites every other scaffold file too.

## Upgrading to v2.1.0 from v2.0.x

v2.1.0 adds optional features and fixes. Nothing needs changing unless one of the two cases at the end of this section applies. There are three visible changes:

- **Dates and numbers follow the user's Martis language.** The following format in the language the user picked in Martis, not the browser's: Date, DateTime and Currency values, metric values, menu count badges and notification dates. With `pt_PT` a date reads `27/09/2026` and a number `1234,5`. The bundled `en` locale formats with US rules (`9/27/2026`, `1,234.5`) on every browser, where it used to follow the browser (`27/09/2026` on an `en-GB` browser). The Currency and Number inputs take the same decimal separator, and the metric charts their axes and tooltips. A Currency field with `locale()` keeps that locale. See [Internationalisation → Number and date formatting](i18n.md#number-and-date-formatting).
- **The `aggregateVia()` tile shows a number, not EUR.** A column named like `amount`, `price` or `total` was shown as euros whatever the app's currency. It is now a plain number with up to two decimals.
- **A fresh install stops logging a 404 for the extensions bundle.** The shell leaves `/vendor/martis-user/extensions.js` out while the file does not exist, and `npm run build:extensions` brings it back with no other step. Any other `MARTIS_EXTENSIONS` URL is still loaded as configured.

New and optional:

- **Restrict the panel** with the `viewMartis` gate ([Authorization → Panel access](authorization.md#panel-access-viewmartis)). Without it the panel is open only in the `local` and `testing` environments (v2.4.0; before, every signed-in user got in).
- **Install without migrating:** `php artisan martis:install --no-migrate`, then `php artisan migrate`.
- **Add palette commands** with `Martis::commandPalette()` ([Components → App commands](components.md#app-commands-v210)).
- **Scope the notification centre** with `Martis::scopeNotificationsUsing()` ([Notifications → Scoping the notification centre](notifications.md#scoping-the-notification-centre)).

When to act:

- **Your users expect another English format.** Add a regional code such as `en_GB` to `martis.preferences.locales` (and a label to `locale_labels`), and let those users pick it: it translates through `en` and formats with its region. See [Internationalisation → Number and date formatting](i18n.md#number-and-date-formatting).
- **You published the shell template** (`vendor:publish --tag=martis-views`, `resources/views/vendor/martis/app.blade.php`). Your copy wins over the package's, so it keeps loading the unbuilt extensions bundle (the 404), and a user the `viewMartis` gate refuses gets the full SPA instead of the standalone screen. Republish it, or port three changes of the package's `resources/views/app.blade.php` into it: `panelForbidden` and `panelForbiddenImpersonating` in `window.MartisConfig`, `extensions: {!! json_encode(\Martis\Support\ExtensionBundles::urls()) !!}`, and the `scoped` key of `notifications`.

## Upgrading to v2.0.1 from v2.0.0

The action log, the throttle buckets, the Gate cache's `lookup()` the Tool route warning, the grouped user hooks, the relatable checks on writes, the relationship panels, the Action Events panel, the action log's columns and React Router 7 apply to every app; the other changes concern an app with a custom `MARTIS_GUARD`.

### Relationship writes follow the pickers

A create, update, inline create, attach, pivot update or Action run now answers **422** when a `BelongsTo`, `MorphTo`, `Tag` or attached record names a record its picker would not list, as Nova's `Relatable` rule does. v2.0 applied `relatableQuery()`, `relatable{PluralModelName}()` and `relatableQueryUsing()` to the pickers only and saved any id the request sent. See [Relationships → Writes follow the pickers](relationships.md#writes-follow-the-pickers).

**Who is affected:** an app whose relatable hooks are narrower than what it saves: a `relatableQuery()` that hides records a form or an API client still writes (an inactive owner, another tenant's record an admin assigns), a `relatableQueryUsing()` written only to sort or shorten the list, or a client that writes soft-deleted related records. The same writes now also need `viewAny` on the related resource, the related record's `add{Model}` policy ability for a `BelongsTo` / `MorphTo` (as the `HasMany` panel on its page already needs), `attachAny{Model}` / `attach{Model}` for the records a `Tag` adds and `detach{Model}` for the ones it removes, and a `BelongsTo` / `MorphTo` fails when the picked record's inverse `HasOne` / `MorphOne` already holds another record (Nova's `relationshipIsFull()`). As in Nova, the value is checked on every save: a record that already points at a target outside the query answers 422 on its next update until the target changes. The `BelongsTo`, `MorphTo` and `Tag` fields of `Repeater` rows are checked too.

**What to change:** widen the hook to what the app writes (branch on `$request->route('resource')` or on the field passed to `relatable{PluralModelName}()` when only one picker should be narrow), send `{attribute}_trashed=true` with a soft-deleted target (the edit form sends the target's `trashed: true` back by itself), grant the policy abilities above, and look for records whose stored target the hooks now exclude, which cannot be saved unchanged. An API client that attaches with a 3-argument `relatableQueryUsing()` closure sends the same `?form[attribute]=value` draft on the attach as on the attachable list.

### The action log is closed by default

The Action Events resource (the `martis_action_events` audit log) was readable by every panel user, `original` and `changes` included, whatever fields those users could see on the records. From v2.0.1 it is closed until you open it, and it masks the values the viewer could not read on the record:

- **Access.** A deny-by-default gate, `view-martis-action-events`, decides who reads the log, unless a policy for `Martis\Models\ActionEvent` defines `viewAny` / `view` (then the policy decides, as before). Without access the index and the detail answer `403`, the sidebar entry and the command palette's *Recent activity* disappear, and a relationship panel that lists the log leaves the detail page (see the next section).
- **Hidden values.** A value in `original` / `changes` reads `******` unless the viewer may see that attribute on the record's own detail page (a visible field, through a resource that lets the viewer view the record). Attributes no field shows, such as `password`, are masked too, and so are the pivot columns of a pivot action whose pivot field the viewer may not see. Rows already stored are unchanged.
- **`$hidden` attributes are stored masked.** From v2.0.1 an event stores `******` for each `$hidden` attribute of the model (or of the pivot model) an action changed, as Nova does. Code that read those values from `martis_action_events` gets the mask for new rows.

**What to change:** grant the gate to the users who should read the log, in `app/Providers/MartisServiceProvider.php` (or any service provider):

```php
use Illuminate\Support\Facades\Gate;

Gate::define('view-martis-action-events', fn ($user) => $user->is_admin);
```

An app with an `ActionEventPolicy` that defines `viewAny` and `view` needs no change. A custom resource for the `ActionEvent` model keeps its own authorization; apply `ActionEventRedactor::redact()` to its `original` / `changes` fields to mask the same values. See [Actions → Who can read the audit log](actions.md#who-can-read-the-audit-log-v201).

### Password reset picks the Martis guard's broker

Password reset now runs on the password broker whose provider is the Martis guard's (`GuardCatalog::martisPasswordBroker()`): `MARTIS_AUTH_PASSWORD_BROKER` when set, else the app's default broker when it reads the Martis guard's users, else the first broker that does. v2.0.0 used `MARTIS_AUTH_PASSWORD_BROKER`, `users` by default, whatever the guard, so beside an `admins` guard the forgot-password form reset the site user with the admin's email. A broker of another provider, an unknown broker, or none that fits now throws a `Martis\Auth\PasswordBrokerConfigurationException` naming `martis.auth.passwordReset.broker` (the endpoint answers 500 and reports it).

**What to change**, with an own guard and password reset on: declare a broker for the guard's provider in `config/auth.php` (`passwords`). A `config/martis.php` published before v2.0.1 holds `env('MARTIS_AUTH_PASSWORD_BROKER', 'users')`, which now throws with an own guard: change the default to `env('MARTIS_AUTH_PASSWORD_BROKER')`, or set the variable to the guard's broker. See [Authentication → Which password broker resets a password](authentication.md#which-password-broker-resets-a-password).

### The Martis throttles have their own buckets

The API throttle's middleware is `throttle:{max},{decay},martis-api:{guard}:` (`RouteMiddleware::throttlePrefix('api')`), the 2FA challenge's and the verification resend's carry `martis-2fa:{guard}:` and `martis-verification:{guard}:`. Laravel keys a user's bucket on `sha1()` of the identifier alone, so the Martis guard's user 5 shared a bucket with a site route throttled per user for the site user 5, and the resend and the challenge counted in the API's bucket. The counters in flight start over once, on deploy. A test that asserts the route's middleware list reads the new string.

### The per-request Gate cache takes the user

`RequestScopedAbilityCache::lookup()` takes the user instead of its id, and keys it by morph class and id: `lookup($user->id, ...)` throws a `TypeError`. The package does not call it.

### Routes a tool registers in `boot()` log a warning

A route under a tool's path (`ToolRoutes::prefix($tool)`, or the v1.x `martis/api/tools/{uriKey}`) registered with `['web', 'martis.auth']`, or with a list that leaves out the 2FA challenge or email verification while it is on, now logs a warning naming the tool and the route, once per tool and PHP process, as a `loadRoutes()` list already did in v2.0.0. The route keeps its middleware. Move it to `ToolRoutes::middleware($this)` or to `loadRoutes()` ([Tools → Routes a tool registers in `boot()`](tools.md#routes-a-tool-registers-in-boot)); a route meant to skip the challenge belongs outside the tool's path.

### Shared `sessions` and `notifications` tables

The Martis migrations of these tables now shape their user columns on the users of every guard that writes them, with a string column when the keys differ. They skip a table that exists, which Laravel 11+ creates with a `bigint` `user_id`: with a Martis guard keyed by UUID or ULID beside the site's bigint users, widen the columns once, as [Installation → The shared `sessions` and `notifications` tables](installation-guide.md#the-shared-sessions-and-notifications-tables) shows.

### User hooks run grouped

No code change is needed. A user hook written with a top-level `orWhere()` now runs grouped everywhere Martis composes it with something else, as Eloquent runs a local scope: the resource's `scopes()` and `indexQuery()` on the index page and its count badge, each index filter's `apply()`, the resource's `searchQuery()`, each filter a lens's `withFilters()` applies, and the pickers' `relatableQuery()`, `relatable{PluralModelName}()` and `relatableQueryUsing()`. v2.0.0 appended the filters, the search term and the lower picker layers to the hook's last `or` clause only, so `where('tenant_id', 1)->orWhere('shared', true)` listed every record of the tenant whatever the filters and the search said, and a filter or a picker closure written with `orWhere()` could list another tenant's records. A list that relied on that precedence now shows fewer records; wrap the hook's clauses in `where(fn ($q) => ...)` yourself only if you meant the looser reading. Hooks that add only `and` clauses produce the same SQL.

### The panel runs on React Router 7

The SPA moved from React Router 6 to React Router 7 (library mode, `createBrowserRouter` as before), which closes GHSA-wrjc-x8rr-h8h6 and GHSA-337j-9hxr-rhxg. It needs Node 20 or later to build (only for building the package itself: an app installs the prebuilt assets). URLs and pages are unchanged.

**Extensions** (custom tools, fields, cards and overrides built with `npm run build:extensions`) keep working without a rebuild. They never bundle React Router: their Vite config sends `react-router-dom` to a shim that reads the host's copy off `window.Martis.runtime.reactRouterDom`, and every name that shim exports (`Link`, `NavLink`, `Outlet`, `Navigate`, `Route`, `Routes`, the routers, `useNavigate`, `useParams`, `useSearchParams`, `useLocation`, `useMatch`, `useResolvedPath`, `useNavigationType`, `generatePath`, `matchPath`, `matchRoutes`) exists in React Router 7. What changes for extension code:

- **`window.Martis.runtime.reactRouterDom` is React Router 7's `react-router-dom` module**: every export of `react-router`, with the DOM `RouterProvider`. Names React Router 7 removed are gone from it (`json`, `defer`, `AbortedDeferredError`, the `UNSAFE_` internals of v6), so code that read one off the shim's default export gets `undefined`.
- **Behaviour of the v7 future flags** now applies to the host's router: navigations run in `React.startTransition`, and a relative link inside a splat route resolves from the splat's own path. An extension that navigates with absolute paths (`navigate('/resources/users')`, `<Link to="/tools/deployments">`) sees no difference. `navigate()` may return a promise; there is nothing to await for a plain navigation.
- **Type declarations.** After you republish the shims (`php artisan vendor:publish --tag=martis-extension-shims --force`), `react-router-dom.d.mts` carries the React Router 7 types. Six type names React Router 7 no longer exports are gone: `FutureConfig`, `Hash`, `JsonFunction`, `Pathname`, `Search` and `V7_FormMethod` (use `string` or `Path['pathname']` for the path parts).
- **`import ... from 'react-router'`** also works in a scaffold published from v2.0.1 (`martis:install --force`): its Vite config and `tsconfig.extensions.json` send `react-router` to the same shim. An older scaffold keeps importing from `react-router-dom`, or adds the two lines by hand (see [Installation → Extensions and React Router 7](installation-guide.md#extensions-and-react-router-7-v201)).

### Relationship panels follow the related resource's `viewAny`

A relationship panel (`HasMany`, `HasOne`, `MorphMany`, `MorphOne`, `BelongsToMany`, `MorphToMany`, and `HasManyThrough`, `HasOneThrough`, `HasOneOfMany`, `MorphOneOfMany`) was shown, and its records listed, to any user who could view the parent record. From v2.0.1 it follows Nova: a user the related resource does not let `viewAny` does not see the panel on the detail page, and its routes (the list, the card, the attachable list, attach, detach, the pivot update and the pivot actions) answer `403`. v2.0 already refused the writes.

**What to change:** grant `viewAny` on the related resource to the users who should keep seeing the panel, and confine the rows they see with `indexQuery()`. See [Relationships → Panels follow the related resource's `viewAny`](relationships.md#panels-follow-the-related-resources-viewany-v201).

### An Action Events panel on `Actionable` models

As in Nova, the detail page of a model that uses `Martis\Concerns\Actionable` now ends with a collapsable **Action Events** panel listing its action log, for the users who may read the log (the `view-martis-action-events` gate or an `ActionEvent` policy). A resource that already declares a `MorphMany` to the action event resource keeps its own and gets no second one.

**What to change:** nothing to get the panel. To leave it out of a resource, override `shouldAddActionsField()` to return `false`. A resource that overrides `fieldsForDetail()` keeps working: the panel is added after it. See [Actions → The Action Events panel](actions.md#the-action-events-panel-v201).

### The action log shows Nova's columns

The built-in `ActionEventResource` now lists Nova's columns: **ID, Name, Initiated By** (the user's name instead of `User ID`), **Target** (`Project: Apollo`, linked when the viewer may view the record, instead of the model class), **Status** (Waiting, Running, Finished, Failed, Denied instead of the stored `queued` / `completed` / ...) and **Happened At** (was Executed At). The detail page drops the batch id and the `actionable_*` columns and shows `original` / `changes` as key/value tables, only when the event holds a diff. Labels are translated (`martis::action_events`). The stored rows do not change.

**What to change:** nothing, unless a subclass of `ActionEventResource` called `parent::fieldsForIndex()` (the override is gone: the index now comes from `fields()`) or reads the field labels. See [Actions → Columns and detail fields](actions.md#columns-and-detail-fields-v201).

## Upgrading to v2.0 from v1.x

Require the new major; a `^1.x` constraint never installs it:

```bash
composer require martis/martis:^2.0
```

Then change the app as the sections below say, and deploy it with the steps in [Deploying the upgrade](#deploying-the-upgrade).

### Choice-field options follow Nova's order (high impact)

`Select::options()` and `MultiSelect::options()` read `[value => label]`, the order Nova uses for `Select`, `MultiSelect` and `BooleanGroup`: the array key is what the field stores, the array value is what the user sees. v1.x read the array the other way round, `[label => value]`.

| Call | v1.x stored | v2.0 stores |
|---|---|---|
| `options(['Draft' => 'draft'])` | `draft` | `Draft` |
| `options(['draft' => 'Draft'])` | `Draft` | `draft` |
| `options(fn () => User::pluck('name', 'id')->all())` | the name | the id |
| `options(['draft', 'published'])` | `draft`, `published` | `0`, `1` |

Nothing fails at runtime when an array keeps the v1 order: the field stores the label instead of the value. Review every call.

**What to change:**

1. **Flip every associative array written label first.** `['Draft' => 'draft']` becomes `['draft' => 'Draft']`, and `pluck('id', 'name')` becomes `pluck('name', 'id')`.
2. **Turn lists into maps.** A list is read as Nova reads it, keyed 0, 1, 2…, so `['draft', 'published']` now stores `0` and `1`. When each value is its own label, pass `array_combine($values, $values)`; for an enum, pass the class (`options(Status::class)`) instead of a list of its values. A list of numbers shifts by one value with no error: `options([1, 2, 3])`, `range(1, 12)` or `array_column(Rating::cases(), 'value')` stored the numbers themselves on v1.x and now store 0, 1, 2, so a stored 1 shows as "2" and picking "3" stores 2.
3. **Rewrite MultiSelect groups in Nova's format.** `['Backend' => ['PHP' => 'php']]` becomes `['php' => ['label' => 'PHP', 'group' => 'Backend']]`. The old format now throws an `InvalidArgumentException` that names the field and the option.
4. **Replace `optionsFromMap()` with `options()`.** It was removed: `options()` reads the same `[value => label]` map and also takes a closure. A call left behind fails with an undefined-method error.
5. **Check `searchOptionsUsing()` closures.** They return the same shapes as `options()`, in the same order, so a list is keyed 0, 1, 2 and stores a position in that search's results, which changes with the term. Return `[value => label]`, for example `->pluck('name', 'id')`, or `array_combine($values, $values)` when each value is its own label. A closure that returns a non-empty list logs a warning, at most once per field per request.
6. **Review what else reads a flipped array.** On v1.x one array could serve both a field and a `SelectFilter` (for example a `Post::STATUSES` constant). Filters keep `[label => value]` (see below), so once the array is flipped for the field, the filter shows `draft` as the label and applies `where status = 'Draft'`: zero results, no error. Give the filter `array_flip(Post::STATUSES)`, or its own array. Review the other readers of a flipped array the same way: a `Rule::in(array_values(...))` rule now lists the labels (use `array_keys()`), and a Badge map or a `displayUsing()` lookup built from it is now keyed by value.
7. **Update generated code.** The `BulkAssignRole` action that `martis:roles` generates builds its role picker with `pluck('id', 'name')`; with v2.0 it would receive the role name: `Role::find('Admin')` answers "That role no longer exists" on MySQL and SQLite, and throws a `QueryException` on PostgreSQL, whose integer id column rejects the text. Change it to `pluck('name', 'id')` (new scaffolds already do). If you published the stubs with `martis:stubs`, update `stubs/martis/roles-bulk-assign-role-action.stub` the same way. The `AGENTS.md` / `CLAUDE.md` primers that `martis:agents` wrote under v1.x recommend `options($enum::values())`, which v2.0 reads as a list: regenerate them with `php artisan martis:agents --force` (update `stubs/martis/agents/AGENTS.md.stub` first if you published it).
8. **Subclasses.** A subclass of `Select` or `MultiSelect` that overrides `options()` must accept `iterable|Arrayable|string|\Closure`: `options()` now takes a Collection or any other iterable, as in Nova, and `MultiSelect::options()` takes an enum class too.

**Finding the calls:**

```bash
grep -rnF -e '->options(' -e 'optionsFromMap(' -e 'searchOptionsUsing(' app/ Modules/ packages/
```

Add every other folder that holds PHP source (a `src/` or `domain/` folder, local packages, a modular layout); `grep` warns about a folder that does not exist and searches the others. Every `Select` and `MultiSelect` hit needs a look. A filter's `public function options(Request $request)` keeps its order, but check the array it returns when a field shares it (step 6). A multi-line chain shows only its `options(` line, so read each hit in its file.

**Records saved with the wrong order.** A field whose array was already written value first under v1.x (for example `['admin' => 'Administrator']`) stored the label. v2.0 reads that array correctly, so those records hold a label where a value is expected: fix the stored values with a data migration.

**The stored-label warning.** Reading a record whose stored value matches the label of a static option and the value of none logs a warning, in production too, at most once per request for each model class and field, naming the model, the record, the field and the value. It fires in both cases above: an array still written label first, and a record saved while it was. For options given as a list, it also fires for a stored value that is the label of another option (the list of numbers of step 2). It reads the stored value, before `resolveUsing()`, and skips computed fields and a `Select` with `allowCustomValues()`. It names the first matching record only and never checks options that come from a closure (the `pluck()` case), so to find every affected record, query the column for values that are labels.

Both warnings can log a false positive: a field can store a valid 0-based position that also happens to read as another option's label, for example a Nova rating that stores `0..4` on purpose and shows `"1".."5"` (`[0 => '1', 1 => '2', ...]`, `array_combine(range(0, 4), range(1, 5))`, or `options([1, 2, 3, 4, 5])` ported straight from Nova). Reading a stored `1` there is correct, not stale data, but would otherwise warn on every request. Call `withoutOptionOrderWarnings()` on that field once the array is confirmed right; it silences the plain stored-label warning and the list-shift warning for that field, and the `searchOptionsUsing()` list warning, which a resolver that filters a fixed list and keeps its keys can trigger when the search term is empty.

**What does not change:**

- **Filters** keep Nova's filter order, `[label => value]`: a filter's `options(Request $request)` is unchanged, exactly as in Nova. An array a filter shares with a field is the exception: see step 6.
- **`BooleanGroup`** already read `[flag key => label]`.
- **Enum classes** (`options(Status::class)`) store the case value, as before; `MultiSelect` now accepts them too.
- **The field payload** keeps its shape (`options: [{label, value, group?}]`), so custom frontend components keep working. `group` is new on `Select`, whose dropdown now shows grouped options under their headings.

### Relationship writes need the related resource's `viewAny`

Creating, editing or deleting a record through a relationship panel (`HasMany`, `HasOne`, `MorphMany`, `MorphOne`, and their endpoints) now needs the related resource's `viewAny`, as its own per-id endpoints do since v1.34.0 and as Nova does. v1.x checked only the parent's `viewAny` / `view` and the related record's `create` / `update` / `delete`.

A user whose policy denies `viewAny` on the related resource gets a 403 on those writes, and the panel no longer offers Create, Edit, Delete, Restore or Force delete. In v2.0.0 it still listed the records, as in v1.x; from v2.0.1 the panel is hidden too (see [Relationship panels follow the related resource's `viewAny`](#relationship-panels-follow-the-related-resources-viewany)).

**What to change:** if that user should keep writing through the panel, grant `viewAny` on the related resource and confine what they see with `indexQuery()`. A resource that is not `routable()` keeps working as a relation target. See [Authorization → `viewAny` is the entry gate](authorization.md#viewany-is-the-entry-gate).

### Through relationships offer no Create

`HasOneThrough` and `HasManyThrough` never offer Create, as in Nova, and a create through a Through relationship answers 403. Edit and Delete now show by default, under the related resource's policies. `canCreate()` has no effect; `canCreate(true)` logs a warning naming the field. See [Relationships → Upgrading the Through fields from 1.x](relationships.md#upgrading-the-through-fields-from-1x).

### Relationship panels list what the related index lists

A `HasMany`, `HasManyThrough`, `MorphMany`, `BelongsToMany` or `MorphToMany` panel now applies the related resource's `scopes()` and `indexQuery()`, as its index does and as Nova's relationship index does. v1.x listed every record of the relationship, including the ones those hooks hide (another tenant's, archived ones).

**What to change:**

1. **Check hooks that should not apply to panels.** An `indexQuery()` meant for the index page only (an order, a default filter) now also shapes the panels; test `$request->route('relationship')` to tell a panel apart (it is not set on the counts a parent's index computes).
2. **Know what reaches a panel.** On a plain `HasMany` / `MorphMany` panel the hooks run grouped on the panel's query: an `orWhere()` stays inside the group, their order comes before the panel's `?sort=` (as on the index), and a hook that joins must select its table's columns, as the index needs (a search there, as on the index, fails when the joined table has a column of the same name). On a Through or pivot panel, and for every count, they run on a fresh query and the panel keeps the keys they return: their order and aliases do not reach the rows (a panel cannot sort by an alias only the hook adds). The key subquery costs little: a 100-row page of a parent index that counts three relationships runs 2 queries instead of the 302 of the per-row counts before v2.0, about an order of magnitude faster in one machine's run with 100,000 and with 500,000 related rows. Columns need no qualifying there.

The `BelongsToMany` and `MorphToMany` panels also apply their soft-delete filter now; before, *Only trashed* listed the active records.

The same hooks now shape what counts the related records: the relationship count a `HasMany`, `MorphMany`, `BelongsToMany` or `MorphToMany` field shows on the index (`showOnIndex()`), now computed with the page instead of per row, and the one-of-many "1 of N" count and `aggregateVia()` tile. The hooks narrow the relationship's own definition and nothing else: a global scope the relationship removes (`->withoutGlobalScope(...)`) stays removed, as in 1.x.

The page's count resolves the relationship as Laravel's `withCount()` does, on a model that holds no record, where v1.x counted on each loaded record. A relationship method that reads the parent's attributes gets `null` there: one that throws without its record is still counted per row, as in v1.x, but one that reads `null` counts the wrong rows. **Check** that the relationships you count on the index (`showOnIndex()`) do not depend on the parent's attributes.

A one-record card (`HasOne`, `HasOneOfMany`, `HasOneThrough`, `MorphOne`, `MorphOneOfMany`) now shows its record only when the user may `view` it, as Nova hides that panel; otherwise the card is not rendered at all (its endpoint answers `meta.hidden: true`) and its Edit and Delete answer 404. Grant `view` where the card should show the record. A custom card component that reads the endpoint should treat `meta.hidden` as "render nothing". Creating a second record on a `HasOne` / `MorphOne` card answers `422` with the reason as its `message` (it answered `500`); a one-of-many card now takes more records, as its many relationship does.

### A one-record card write names its record

`PUT` and `DELETE` on `…/has-one/{relationship}` and `…/morph-one/{relationship}` need `?relatedId=` with the id of the record the client read from the card's `GET`: `422` without it, `409` when the relationship holds another record by then (nothing is written). A stale id answers `409` before the policy is checked; a missing id answers `422` after it, so a denied user gets `403`. The Martis card sends it.

**What to change:** an API client that calls those endpoints adds the id it read. A custom card component (one registered in place of the `HasOne` / `MorphOne` card) that edits or deletes through them sends `?relatedId=` with the id it showed, keeps that id from the click to the confirm (a refetch may swap the record meanwhile), and on a `409` reloads the card and shows the response's `message`.

### Creating a record needs `viewAny`

`POST /api/resources/{resource}` and the inline create (its form and its store) answer `403` when the user may not list the resource, as its show, update and destroy endpoints do since v1.34.0. v1.x checked `create` only.

The create form's own endpoints (`sync-field` and `fields/{field}/options` in the create context) need it too, and the inline create buttons of `BelongsTo`, `MorphTo`, `Tag`, `BelongsToMany` and `MorphToMany` are offered only when the related resource allows both `create` and `viewAny`.

**What to change:** grant `viewAny` to a user who should create records, and confine what they see with `indexQuery()` if needed.

### Actions: who may run them, and on which records

An action run on records changed in v2.0:

- **`canRun()` and the policy must both allow it.** The per-row map the index and the panels send (`_actionAuthorization`) now includes the resource's `runAction` / `runDestructiveAction` policy (falling back to `update` / `delete`), as the run always did: an action the policy refuses shows disabled instead of failing when run. A `canRun()` does not replace the policy, unlike Nova 5.
- **Trashed records run.** A selected record the resource soft-deletes is looked up with the trashed ones, since the index and the panels list them.
- **A partial selection runs on what resolves.** Ids outside the resource's `scopes()` / `indexQuery()`, or that no longer exist, are left out and the action runs on the others, as in Nova.
- **404 when nothing resolves.** A run whose selected records all fail to resolve answers `404` (`One or more selected resources could not be found.`) instead of running `handle()` on nothing and answering "Done".
- **422 with an empty selection.** An action that is not `standalone()` and names no record answers `422` (`resources`), as a pivot action already did. A `standalone()` action runs on no record, whatever the request names.
- **`scopes()` apply.** A run looks records up through the resource's declarative `scopes()`, then `indexQuery()`, as the index lists them.
- **A panel's run finds what the panel lists.** A run from a `HasMany`, `HasManyThrough` or `MorphMany` panel (`viaResource`, `viaResourceId`, `viaRelationship`) and a pivot action on a `BelongsToMany` / `MorphToMany` panel resolve the records the panel lists: the relationship's rows (a global scope it removes stays removed), the related resource's `scopes()` and `indexQuery()`, and the trashed ones only when the panel offers its trashed filter (`canViewTrashed()`). A pivot action no longer runs on a row those hooks hide (it answers 404), and a `standalone()` action run with a relationship the parent does not declare answers 404.
- **A queued action handles what the run resolved.** Its job (`ExecuteAction`, `ExecutePivotAction` for a pivot action) reloads the records by key without global scopes, as Laravel restores a job's models, so it handles every record the run resolved: a trashed one, one only the panel's relationship reaches. v1.x reloaded them through the model's global scopes, which in a queue worker (no user, no tenant) could leave records out.

**What to change:** a client that posts to `/actions/{action}` without `resources` for an action that is not standalone must send the ids, or declare the action `standalone()`.

### The global search and the pivot routes apply `scopes()`

The global search (`/api/search`, the Cmd+K palette) and the parent lookup of a `BelongsToMany` panel and of the pivot routes (pivot actions, their fields and pickers, the pickers of the pivot fields, on `belongs-to-many` and `morph-to-many`) now run the resource's declarative `scopes()` before `indexQuery()`, as the index does. v1.x ran `indexQuery()` alone there, so a resource that confined its tenants with `scopes()` showed another tenant's records in the palette.

- A record the scopes hide no longer shows in the palette, and the `total` of its group no longer counts it.
- A `BelongsToMany` panel, a pivot action or a pivot field picker whose parent record the scopes hide answers `404`, as it already did for a parent `indexQuery()` hides.
- On those surfaces and on an action run, an `orWhere()` in `scopes()` or `indexQuery()` is grouped before the term, the key or the selected ids are added. v1.x appended them to its last clause only, so a hook such as `where('tenant_id', 1)->orWhere('shared', true)` made the palette list the tenant's records whatever the term, a panel resolve the first record of the tenant instead of the one it names, and an action on one selected record run on every record of the tenant.

**What to change:** nothing when `scopes()` holds tenancy or visibility rules: they now apply where the docs said they would. A scope meant to trim the index page only (an `archived = false` default that users should still reach from the palette) belongs in a [filter](filters.md) instead. See [Authorization → Declarative query scopes](authorization.md#declarative-query-scopes).

### Tool routes run the Martis API middleware

`Tool::loadRoutes()` called without a middleware list gives a tool's routes `ToolRoutes::middleware($tool)` (`Martis\Tools\ToolRoutes`): the middleware of the package's protected API routes (your `martis.middleware` and `martis.auth_middleware`, the impersonation expiry, the 2FA challenge, the user's locale, email verification when it is enabled, the API throttle), then `martis.tool:{uriKey}`. v1.x defaulted to `['web', 'martis.auth']`.

- A user who signed in with a password but has not passed the 2FA challenge gets `423` from a tool route (a redirect to the challenge for a page request), and a user who has not verified an email the app requires gets `409` (a redirect to the notice), as from the rest of the API. v1.x let both through.
- A user the tool is hidden from (`canSee()`, its policy) gets `404` from its routes, as from the tool's page.
- A tool's routes count toward the API throttle, `MARTIS_THROTTLE_MAX` (120) requests per `MARTIS_THROTTLE_DECAY` (1) minute per user, shared with the rest of the API. A tool that polls often answers `429` past it.
- They run in the user's locale, and an impersonation past its limit stops before they run.
- **With a `MARTIS_PATH` other than `martis`, they move** from `/martis/api/tools/{uriKey}/...` to `/{MARTIS_PATH}/api/tools/{uriKey}/...` (`ToolRoutes::prefix()`), where the SPA's `api` client calls them: on v1.x they stayed under `/martis`, so the client answered 404. Nothing moves with the default path.

The signature keeps its v1.x type, `array $middleware`, so a tool that overrides `loadRoutes()` with the v1.x signature still loads, and a list passed explicitly is used as given, as in v1.x. A list that leaves out the 2FA challenge while `MARTIS_2FA_ENABLED` is on (the default), or email verification while it is on, and the v1.x list `['web', 'martis.auth']` itself, log a warning that names the tool, once per tool and PHP process.

**What to change:**

1. **Drop the middleware argument** of every `loadRoutes()` call that passes `['web', 'martis.auth']` (or forwards it from an override): that list keeps the v1.x stack, without the 2FA challenge, and logs the warning. Pass a list only for another stack: `[...ToolRoutes::middleware($this), 'can:imports.run']` adds an ability, and a route that must answer before the 2FA challenge or to users the tool is hidden from keeps its own list, which is used exactly as given.
2. **Routes a tool registers in `boot()` with `Route::middleware(['web', 'martis.auth'])->prefix('martis/api/tools/...')`**, the pattern these docs showed, keep that weaker stack and that path (from v2.0.1 they log a warning naming the tool and the route): switch them to `Route::middleware(ToolRoutes::middleware($this))->prefix(ToolRoutes::prefix($this))`, or move them to a routes file loaded by `$this->loadRoutes()`, as [Tools → Routes a tool registers in `boot()`](tools.md#routes-a-tool-registers-in-boot) shows. A route of your own that `martis.auth` alone guards skips the 2FA challenge the same way: use the `martis.api` middleware group.
3. **A client that calls a tool route by a hard-coded `/martis/api/tools/...` URL** with a custom `MARTIS_PATH` follows the new path, or goes through the SPA's `api` client (`api.get('/api/tools/...')`). To keep the old URL, pass `prefix: 'martis/api/tools/{uriKey}'`.
4. **A tool that polls** raises `MARTIS_THROTTLE_MAX`, or passes a list without the throttle.

See [Tools → Tool routes and their middleware](tools.md#tool-routes-and-their-middleware).

### The schema cache expires after a day

The `schema` cache layer now expires after a day by default (`MARTIS_CACHE_SCHEMA_TTL=1440`); v1.x kept it with no expiration. Every cache key also carries the installed `martis/martis` version, so an upgrade of the package rebuilds every layer on its own and leaves the previous version's entries behind.

**What to change:**

1. **Check the TTL.** An empty `MARTIS_CACHE_SCHEMA_TTL=` (the `.env` block the v1.x docs showed) and a `config/martis.php` published before v2.0 keep "no expiration". Set `MARTIS_CACHE_SCHEMA_TTL=1440`, or republish the config and merge your changes.
2. **Prune on the database and file stores.** Those stores delete an expired entry only when its key is read again, which an old version's key never is. Run `php artisan martis:cache:prune` after each upgrade, or schedule it. Redis and memcached need nothing.

See [Cache → Invalidation](cache.md#invalidation).

### Custom themes

A theme generated with `martis:theme` before v2.0 may need three edits:

1. **`--martis-dur-sm` and `--martis-brand-500` do nothing any more.** No stylesheet defined them; the package now reads `--martis-dur-fast` and `--martis-accent` where it read them, and `martis:theme:diff` lists them as *Unknown to package* (exit 2). Set `--martis-dur-fast` and `--martis-accent` instead.
2. **Delete the two logo heights**, `--martis-brand-logo-height-auth` and `--martis-brand-logo-height-menu`, unless you mean to override `MARTIS_BRAND_LOGO_HEIGHT_AUTH` / `_MENU`: the old stub declared them on `:root` (the menu one at 28px), which silenced those `.env` knobs.
3. **Set the short typography names.** The package CSS reads `--martis-text-*`, `--martis-weight-*` and `--martis-leading-*`; a theme that sets only `--martis-font-size-*`, `--martis-font-weight-*` or `--martis-line-height-*` changes almost nothing.

See [Theming](theming.md#variable-reference).

### `martis:install` asks only on a terminal

`martis:install`, and every other Martis command that asks (the generators' "Overwrite?", `martis:user`, `martis:agents`, `martis:sso`'s role mapping, the "Run pending migrations now?" of `martis:invitations`, `martis:roles` and `martis:sso`), asks a question only when the input is interactive **and** stdin is a real TTY. A generator run through a pipe leaves an existing file alone unless you pass `--force`, printing "already exists" and exiting 0. `martis:user` without a terminal needs `--email` and `--password` (it exits 1 naming the missing one, and creates no user; `--name` defaults to `Martis Admin`). The three scaffold commands now run their migrations through a pipe: `yes n | php artisan martis:invitations` used to answer "no" and skip them, so pass `--no-migrate` to skip them. Through a pipe or `docker compose exec -T` every question takes its default: optional features you pass no flag for stay off, the avatar column is `profile_picture`, and `--existing-avatar-column` needs `--avatar-column`. Before v2.0 the avatar column questions read the pipe, and `--force` rewrote any `*_create_notifications_table.php` / `*_create_sessions_table.php`, the application's own included.

**What to check:**

1. **A `users.y` column.** `yes | php artisan martis:install --with-profile` answered `y` to the avatar column question: look for a migration adding `y` to `users` in `database/migrations` and `MARTIS_AVATAR_COLUMN=y` in `.env`. Roll the migration back (or drop the column), delete it, set `MARTIS_AVATAR_COLUMN` to the column you want and run the installer again with `--with-profile --avatar-column=<column>` (and `--with-2fa` when you use two-factor authentication): without a terminal, a feature you pass no flag for is turned off.
2. **Your own notifications or sessions migration.** If you ran `martis:install --force` over a migration you created with `make:notifications-table` or `make:session-table`, it now holds the Martis stub: compare it with your version control and restore it. `--force` leaves it alone from v2.0.
3. **Scripts that relied on a prompt.** Pass the flags (`--with-profile`, `--with-2fa`, `--avatar-column=…`, `martis:user --email=… --password=…`, `--no-migrate`) instead.
4. **A `martis:user` run through a pipe.** `yes | php artisan martis:user` created an admin with the email, name and password `y`, its email already verified: look for a user with the email `y` and delete it.

### `martis.auth` runs where Laravel's `auth` runs

`MartisAuthenticate`, the `martis.auth` middleware, implements Laravel's `AuthenticatesRequests`, as Laravel's and Nova's `Authenticate` do, so the router's middleware priority now runs it right after the session starts: before the throttle, the route bindings (`SubstituteBindings`) and any middleware that is not in the priority list, including one appended to the `web` group or listed in `martis.middleware`. v1.x ran it after them. The protected routes' throttle therefore counts per signed-in user (with a custom `MARTIS_GUARD` it counted per IP, see [A custom Martis guard](#a-custom-martis-guard)), and an unauthenticated request to a protected route is answered before the throttle counts it, as with Laravel's `auth` and `throttle`.

**What to change:** a middleware that must run before authentication, such as one that selects a tenant's database connection, must be in the app's middleware priority list, as it must for Laravel's `auth` (stancl/tenancy registers its own there):

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->prependToPriorityList(
        \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
        \App\Http\Middleware\InitializeTenant::class,
    );
})
```

### Deploying the upgrade

Run these in each environment, after the code with the flipped arrays is deployed there, in this order:

```bash
php artisan martis:publish-assets
php artisan martis:cache:clear schema
php artisan martis:cache:prune
```

1. **Republish the assets.** The grouped `Select` dropdown and the fix for a `''` option ship in the frontend bundle; without the republish the browser keeps the v1 bundle.
2. **Clear the schema cache, after the deploy.** It holds the options as they were read, for up to a day by default, and with no expiration when `MARTIS_CACHE_SCHEMA_TTL` is empty or `config/martis.php` was published before v2.0 (see [The schema cache expires after a day](#the-schema-cache-expires-after-a-day)); a new package version does not help here, because the arrays you flipped are your app's code. Cleared before the new code is deployed, the next page opened caches the old options again, so clear it after the deploy, in every environment.
3. **Prune the old cache entries**, on the database and file stores (a no-op on the others).

## A custom Martis guard

With a custom `MARTIS_GUARD`, the panel's requests now authenticate as that guard's user everywhere (`MartisAuthenticate` calls `auth()->shouldUse()`, as Laravel's `auth` middleware and Nova's do). Policies, `Gate::before()` / `Gate::after()` callbacks, gates, `canSee()` closures, observers and anything else that reads `auth()->user()` or `auth()->id()` during a panel request receive an instance of that guard's provider model: before, they got the app's default guard's user, which was null, or the site user when both guards were signed in in the same browser (the panel's policies then evaluated the site account). A closure type-hinted on the default model (`fn (User $user)`) now throws a `TypeError`: widen it (`Authenticatable`) or check the instance. The notification bell needs that model to use `Illuminate\Notifications\Notifiable` (without it the bell reads as off and the log says why; or set `MARTIS_NOTIFICATIONS_ENABLED=false`). The action log's and the preferences' user relation resolve that model, and impersonation runs on that guard unless `MARTIS_IMPERSONATION_GUARD` names another. The protected routes' rate limit counts per Martis user rather than per IP (the throttle ran before the guard switch in v1.x, see [`martis.auth` runs where Laravel's `auth` runs](#martisauth-runs-where-laravels-auth-runs)).

The flows that find or create the Martis guard's users use that guard's provider: the magic link (v1.x looked the email up in `users` and signed that user into the Martis guard, which then loaded the admin with the same id), the registration and invitation accept (their email must be unique among that guard's users, not in `users`), the email verification link, and `php artisan martis:user`, which creates that guard's user, as Nova's `nova:user` does. With `MARTIS_GUARD` unset, all of them follow the app's default guard, as the panel does.

When the guard's model has its own table (an `admins` guard beside the site's `users`), two more things differ:

- **The Martis tables reference that table.** A fresh install creates `martis_user_preferences.user_id` and `invitations.invited_by` / `accepted_user_id` with foreign keys to it, and adds the two-factor and avatar columns to it. An install migrated before v2.0.0 has them on `users`: saving an admin's preferences fails on the foreign key, or ties the row to the site user with that id. Run the migration below.
- **Browser sessions read as unsupported.** Laravel's `sessions.user_id` holds the id of the guard that wrote the row, with no table: the same id is an admin in one row and a site user in another. When the session guards of `config/auth.php` (with the Martis and the default guard) sign in users of more than one table, the profile's Browser sessions section says so instead of listing or revoking another person's sessions, and `revoke_sessions_on_demote` is skipped with a warning in the log. Guards that share one table, through any provider or model, keep both.

Checklist for an app with `MARTIS_GUARD` set to its own guard:

- Widen closures and policy methods type-hinted on the default user model, or check the instance.
- Add `Illuminate\Notifications\Notifiable` to the Martis guard's model, or set `MARTIS_NOTIFICATIONS_ENABLED=false`.
- Leave `MARTIS_IMPERSONATION_GUARD` unset (it follows `MARTIS_GUARD`), or set it to the same guard; a config file published before v2.0.0 has `'web'` as its default, so republish it or set the variable. An operator signed in by a guard of other users is recorded in the audit row's `fields.operator_type` / `operator_id`, with no `user_id`.
- Rows the action log wrote before the upgrade with the default guard's ids now resolve through the Martis guard's model. From v2.0.0 an event caused by another guard's user records no actor: a role change or an invitation event outside the panel (in a site request, even from a browser that also holds a panel session, in a job, in a command) records none (or the inviter), and an authorization denial is recorded only while the Martis guard is the request's guard.
- Impersonation now acts on the Martis guard's users: the operator and the target are both, for instance, admins. To impersonate the site's users, set `MARTIS_IMPERSONATION_GUARD` to the site's guard, on which the operator must then be signed in too.
- With password reset enabled, declare a password broker (`config/auth.php` → `passwords`) whose provider is the Martis guard's. From v2.0.1 Martis picks it when `MARTIS_AUTH_PASSWORD_BROKER` is unset, and throws when that variable, or the `'users'` default of a `config/martis.php` published before v2.0.1, names a broker of another provider (see [Password reset picks the Martis guard's broker](#password-reset-picks-the-martis-guards-broker)).
- If a boot script runs `php artisan martis:user --if-missing`, it now checks and creates the Martis guard's user.
- When the guard's model has its own table, run this migration once (with your table in place of `admins`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $admins = 'admins'; // the table of the Martis guard's model

        if (Schema::hasTable('martis_user_preferences')) {
            // v1.x saved the preferences of the default guard's user (the
            // site user signed in the same browser, if any): theme, accent,
            // density and locale only, so the table starts over.
            Schema::drop('martis_user_preferences');
            Schema::create('martis_user_preferences', function (Blueprint $table) use ($admins) {
                $table->id();
                // foreignUuid() / foreignUlid() for a UUID / ULID key.
                $table->foreignId('user_id')->unique()->constrained($admins)->cascadeOnDelete();
                $table->string('theme', 16)->default('dark');
                $table->string('accent', 16)->default('martis');
                $table->string('brand_color', 9)->nullable();
                $table->string('density', 16)->default('comfortable');
                $table->string('locale', 10)->default('en');
                $table->boolean('reduced_motion')->default(false);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('invitations')) {
            // The inviters and the accepted accounts are already the Martis
            // guard's users; an id that names none of them is cleared first.
            foreach (['invited_by', 'accepted_user_id'] as $column) {
                DB::table('invitations')
                    ->whereNotIn($column, DB::table($admins)->select('id'))
                    ->update([$column => null]);
            }

            Schema::table('invitations', function (Blueprint $table) use ($admins) {
                $table->dropForeign(['invited_by']);
                $table->dropForeign(['accepted_user_id']);
                $table->foreign('invited_by')->references('id')->on($admins)->nullOnDelete();
                $table->foreign('accepted_user_id')->references('id')->on($admins)->nullOnDelete();
            });
        }
    }
};
```

When the model's key type differs from `users.id` (a UUID model beside bigint users), change the two invitation columns' type (`$table->uuid('invited_by')->nullable()->change()`) before adding their foreign keys. If you use two-factor authentication or the avatar, add their columns to the Martis guard's table too: copy `vendor/martis/martis/stubs/add_two_factor_columns.php.stub` (and `add_profile_picture_column.php.stub`, with your avatar column in place of `profile_picture`) to a new file in `database/migrations/` and run `php artisan migrate`; both add only the columns the table lacks, to the Martis guard's table.
