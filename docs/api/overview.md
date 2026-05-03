# Martis API — Overview

Martis exposes a REST API for every CRUD operation, resource metadata, navigation, search, preferences, sessions, impersonation, and admin surfaces. The schema is generated at runtime by [Scramble](https://scramble.dedoc.co/) and served as both interactive UI and raw OpenAPI 3.1.

> **The authoritative endpoint reference is the auto-generated OpenAPI 3.1 document at `/{martis-path}/api-docs.json`** (when `MARTIS_API_DOCS_ENABLED=true`). This page is a curated index of the most-used surfaces — when in doubt, fetch the JSON, it always reflects the live route table.

## Access

| Item | Value |
|------|-------|
| Swagger UI | `/{martis-path}/api-docs` (off by default — see toggle below) |
| Raw OpenAPI 3.1 | `/{martis-path}/api-docs.json` (off by default) |
| Base URL | `/{martis-path}/api` |
| Format | JSON |
| Auth | Session cookie (Laravel guard, configurable via `MARTIS_GUARD`). Same-origin SPA — no Bearer token, no JWT, no Sanctum. |

## Enabling the OpenAPI surface

Off by default so `composer require martis/martis` does not expose the schema publicly. Flip the env to register the routes:

```dotenv
MARTIS_API_DOCS_ENABLED=true
```

After flipping the env value, run `php artisan optimize:clear` AND restart the running PHP workers (`docker compose restart app`, `systemctl reload php-fpm`, or `php artisan octane:reload` depending on your stack). The artisan command alone clears Laravel's config cache, but PHP-FPM workers keep the parsed `.env` in process memory until they're recycled — so a "supposedly off" state can keep serving the schema until the worker pool is replaced.

That registers two routes:

- `GET /{martis-path}/api-docs` — Stoplight Elements UI.
- `GET /{martis-path}/api-docs.json` — raw OpenAPI 3.1 document.

Both go through `martis.api_docs.middleware` (default `['web', 'auth']`). When the env is `false`, **the routes are not registered at all** — `php artisan route:list --path=api-docs` returns nothing, and a request hits the SPA catch-all (302 to `/martis`) rather than Scramble. That is the intended security envelope.

To tighten further (e.g. admin-only) override the middleware in your published `config/martis.php`:

```php
'api_docs' => [
    'enabled' => env('MARTIS_API_DOCS_ENABLED', false),
    'path'    => env('MARTIS_API_DOCS_PATH', 'api-docs'),
    'middleware' => ['web', 'auth', 'can:manage-martis-cache'],
],
```

Recommended: leave **off in production** unless you have a reason to expose the schema. Even when on, keep the middleware tight — the document includes every Martis API path and shape.

## Authentication

Martis is **session-cookie based**. The Laravel guard sets the cookie on `attempt()` + `session()->regenerate()`. There is no JWT; no `Authorization: Bearer …` header is needed (or accepted) by any Martis endpoint. Same-origin SPA calls work out of the box. See [Authentication](../authentication.md) for the full surface.

### Login

```http
POST /martis/api/auth/login
Content-Type: application/json

{
  "email": "admin@example.com",
  "password": "<your-password>"
}
```

**Responses:**

| Status | Body | Description |
|---|---|---|
| `200` | `{ "id": 1, "name": "...", "email": "...", "avatar_url": "...", ... }` | Successful login. The user object is returned flat (no `user` wrapper). |
| `200` | `{ "two_factor_required": true, "message": "..." }` | 2FA enabled — the frontend redirects to `/2fa/challenge`. |
| `422` | `{ "message": "...", "errors": {...} }` | Validation error or wrong credentials. |
| `429` | `{ "message": "Too many attempts" }` | Rate limited (per-IP + per-email composition). |

### Other auth endpoints

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/martis/api/auth/logout` | Invalidate the server-side session. |
| `GET` | `/martis/api/auth/user` | **Public.** Returns the user object when a session cookie is present, `null` for guests. Special envelope `{two_factor_pending: true, message}` when 2FA is mid-challenge. |
| `POST` | `/martis/api/auth/register` | Self-service registration when `auth.registration.enabled=true`. |
| `POST` | `/martis/api/auth/password/email` | Send password-reset link. Gated on `auth.passwordReset.enabled`. |
| `POST` | `/martis/api/auth/password/reset` | Submit new password with token. |
| `POST` | `/martis/api/auth/email/verification-notification` | Resend the verification email (throttled `6,1`). |
| `GET` | `/martis/email/verify` | Themed notice page (must be authenticated). |
| `GET` | `/martis/email/verify/{id}/{hash}` | Signed verify link target — marks `email_verified_at`. |
| `POST` | `/martis/api/auth/magic-link/request` | **v1.8.8.** Issue a passwordless sign-in token + email it. Returns `200 {ok: true}` whether or not the email exists (account-enumeration safe). |
| `GET` | `/martis/api/auth/magic-link/consume?email=…&token=…` | **v1.8.8.** Verify the token, sign the user in, redirect to `/{martis-path}`. |
| `POST` | `/martis/api/2fa/challenge` | Submit the 6-digit TOTP (or recovery) code during the 2FA challenge. |
| `GET` | `/martis/sso/{provider}/redirect` | Kick off the OAuth flow. Routes only registered when `auth.sso.enabled`. |
| `GET` | `/martis/sso/{provider}/callback` | Handle the IdP callback. |

## Resource Endpoints

The list of registered resources lives in the [Navigation endpoint](#navigation-endpoint) (`/api/navigation`). Each entry carries the `uriKey` you use below.

### Index (List Records)

```http
GET /martis/api/resources/{resource}
```

**Query Parameters:**

| Parameter | Example | Description |
|---|---|---|
| `search` | `?search=john` | Full-text search across `searchable()` fields |
| `sort` | `?sort=name` | Sort column |
| `direction` | `?direction=desc` | `asc` (default) or `desc` |
| `per_page` | `?per_page=25` | Records per page |
| `page` | `?page=2` | Page number |
| `trashed` | `?trashed=only` | `only` or `with` for soft-deleted records |

**Response:**

```json
{
  "data": [
    {
      "id": 1,
      "name": "Admin User",
      "email": "admin@example.com",
      "_title": "Admin User",
      "_resource": { "uriKey": "users", "label": "Users" },
      "_authorization": { "authorizedToView": true, "authorizedToUpdate": true, "authorizedToDelete": false },
      "_actionAuthorization": { "publish": true, "archive": false }
    }
  ],
  "meta": {
    "current_page": 1, "last_page": 3, "per_page": 15, "total": 42, "from": 1, "to": 15
  },
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." }
}
```

### Single record (CRUD)

| Method | Path | Notes |
|---|---|---|
| `GET` | `/martis/api/resources/{resource}/{id}` | Detail. |
| `POST` | `/martis/api/resources/{resource}` | Create. Use `multipart/form-data` for file uploads. |
| `PUT` | `/martis/api/resources/{resource}/{id}` | Update. |
| `DELETE` | `/martis/api/resources/{resource}/{id}` | Delete (archives soft-delete models). |
| `PUT` | `/martis/api/resources/{resource}/{id}/restore` | Restore soft-deleted record. |
| `DELETE` | `/martis/api/resources/{resource}/{id}/force` | Force-delete (only when `forceDelete` policy ability passes). |
| `POST` | `/martis/api/resources/{resource}/{id}/replicate` | Replicate the row (when `replicate` policy ability passes). |
| `POST` | `/martis/api/resources/{resource}/{id}/peek` | Lightweight detail snapshot for the BelongsTo / MorphTo peek popover. |

### Schema

```http
GET /martis/api/resources/{resource}/schema
```

Returns the field structure and metadata for the resource — `fields`, `fieldsForIndex`, `fieldsForDetail`, `fieldsForCreate`, `fieldsForUpdate`, `accentColor`, `loaderConfig`, `tableStriped`, `perPageOptions`, `overrides`, etc. The React shell hits this endpoint on every navigation to a resource page.

### Inline create

```
POST /martis/api/resources/{resource}/inline-create-schema
POST /martis/api/resources/{resource}/inline-create
```

Drives the lightweight "Create related" form embedded in HasMany / BelongsToMany pickers without leaving the parent page.

### Slug live collision check

```http
GET /martis/api/resources/{resource}/slug-check/{field}?value=...&exclude_id=...
```

Used by `Slug::make()` for live "this slug is taken" hints in the create / update form.

### Lenses

```http
GET /martis/api/resources/{resource}/lenses/{lens}
```

Index endpoint for the named lens. Same query params as the resource index. See [Lenses](../lenses.md).

### Reactive (`dependsOn`) field sync

```http
POST /martis/api/resources/{resource}/sync-field
Body: { field: "<attribute>", payload: { ...current form values... } }
```

Server-side resolution of reactive `dependsOn()` fields. Frontend debounces (200 ms) + uses `AbortController` so the latest value always wins. Rejects unknown attributes (404), non-reactive attributes (422), empty attribute names (422). See [Fields § Reactive fields](../fields.md#reactive-fields--dependsonfield-closure).

## Relationship Endpoints

### BelongsTo options (relatable)

```http
GET /martis/api/resources/{resource}/{id}/relatable/{field}
GET /martis/api/resources/{resource}/{id}/relatable/{field}?search=term
```

Returns the option list for a BelongsTo / MorphTo dropdown, filtered by the resource's `relatableQuery()` if defined.

### HasMany / HasOne / BelongsToMany / MorphMany / MorphOne / MorphToMany

Each relation type has a full sub-tree under the parent's URL. The shape mirrors the parent's CRUD verbs:

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/{r}/{id}/has-many/{rel}` | List related records (paginated, searchable, sortable). |
| `POST` | `/{r}/{id}/has-many/{rel}` | Create a child. |
| `PUT` | `/{r}/{id}/has-many/{rel}/{relatedId}` | Update a child. |
| `DELETE` | `/{r}/{id}/has-many/{rel}/{relatedId}` | Delete a child. |
| `GET` | `/{r}/{id}/has-one/{rel}` | Show. |
| `POST` | `/{r}/{id}/has-one/{rel}` | Create. |
| `PUT` | `/{r}/{id}/has-one/{rel}` | Update. |
| `DELETE` | `/{r}/{id}/has-one/{rel}` | Delete. |
| `GET` | `/{r}/{id}/belongs-to-many/{rel}` | List with pivot data. |
| `GET` | `/{r}/{id}/belongs-to-many/{rel}/attachable` | Options available to attach. |
| `POST` | `/{r}/{id}/belongs-to-many/{rel}/attach` | Attach with optional pivot fields. |
| `DELETE` | `/{r}/{id}/belongs-to-many/{rel}/{relatedId}/detach` | Detach. |
| `PUT` | `/{r}/{id}/belongs-to-many/{rel}/{relatedId}/pivot` | Update pivot row. |

(`/{r}` is shorthand for `/martis/api/resources/{resource}`.) MorphMany / MorphOne / MorphToMany follow the same shape under `/morph-many/`, `/morph-one/`, `/morph-to-many/`.

## Actions

Per-resource and per-row action execution.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/martis/api/resources/{resource}/actions` | List actions visible on the index. |
| `GET` | `/martis/api/resources/{resource}/actions/{action}/fields` | Confirmation-modal field schema for an action. |
| `POST` | `/martis/api/resources/{resource}/actions/{action}` | Run a bulk / standalone action. |
| `POST` | `/martis/api/resources/{resource}/{id}/actions/{action}` | Run an inline (per-row) action. |
| `GET` | `/martis/api/resources/{resource}/{id}/belongs-to-many/{rel}/actions` | Pivot-row actions list. |
| `POST` | `/martis/api/resources/{resource}/{id}/belongs-to-many/{rel}/actions/{action}` | Run a pivot-row action. |

## Translation Endpoint

```http
GET /martis/api/translations/{locale}
```

**Public** (no auth required). Bundled locales: `en`, `pt_BR`, `pt_PT` (underscore form is canonical; the controller normalises hyphenated input). See [i18n](../i18n.md) for the merge order, `app_namespaces`, and fallback chain.

## Navigation Endpoints

```http
GET /martis/api/navigation
GET /martis/api/navigation/badges    # v1.8.8
```

`/navigation` returns the canonical sidebar tree the React shell renders — sections with label, icon, and items (resources, links, dashboards, tools). It is fetched once per session + on route mutations and is **not auto-polled**.

`/navigation/badges` returns a flat `{ uriKey: count }` map keyed by resource `uriKey`. The SPA polls this endpoint at the cadence configured in `martis.navigation.badges_poll_interval` (default 300 000 ms = 5 min) and merges the values into the cached tree. 5–10× cheaper server-side than the full tree; resources that opt out of `showMenuCount()` are excluded.

## Global Search

```http
GET /martis/api/search?q=...
```

Cross-resource record search. Powers the topbar search input. See [Global Search](../global-search.md).

## Command Palette

```http
GET /martis/api/command-palette?q=...
```

Aggregates the four ⌘K palette sections (Resources / Actions / Recent / Records) into a single payload. Short client-side cache (30 s) on top.

## Dashboards

```
GET  /martis/api/dashboards                              List visible dashboards.
GET  /martis/api/dashboards/{uriKey}                     Single dashboard descriptor + cards.
GET  /martis/api/dashboards/{uriKey}/cards/{card}        Compute a single metric card.
```

The single dashboard endpoint returns the layout type (`cards` or `default`), the list of metric cards, dashboard-level filters, and any `withMeta()` data set on the PHP class.

## Tools

Surface for the [Custom Tools](../tools.md) primitive.

```
GET  /martis/api/tools                  List every authorised tool.
GET  /martis/api/tools/{uriKey}         Single tool metadata, or 404 (also when canSee denies).
```

The 404-when-denied behaviour is intentional — an unauthorised user cannot probe which tools the app ships.

## Preferences

Per-user theme / accent / density / locale / reduced-motion persistence.

```
GET     /martis/api/preferences         Current user's preferences payload + meta
PUT     /martis/api/preferences         Persist a partial update
DELETE  /martis/api/preferences         Reset to defaults
```

PUT body is partial — pass only the keys you want to change. Returns the full snapshot so the React shell can re-bootstrap. See [User Preferences](../preferences.md).

## Notifications

Drive the topbar bell dropdown over Laravel's standard `notifications` table.

```
GET     /martis/api/notifications                 Paginated list (capped at 50 server-side).
GET     /martis/api/notifications/unread-count    Just the unread count (used for badge polling).
POST    /martis/api/notifications/read-all        Mark every notification as read.
DELETE  /martis/api/notifications                 Clear every notification.
POST    /martis/api/notifications/{id}/read       Mark a single notification as read.
DELETE  /martis/api/notifications/{id}            Delete a single notification.
```

All endpoints scope to the authenticated user. Cross-user access returns 404. See [Notifications](../notifications.md).

## Cache Admin

Per-subsystem cache toggle + clear. Gated by the `manage-martis-cache` ability — define it in your `AuthServiceProvider`.

```
GET   /martis/api/cache                  Snapshot: per-type effective state, TTLs, versions.
POST  /martis/api/cache/clear            Clear one type (?type=metrics) or all.
POST  /martis/api/cache/disable          Toggle a runtime override OFF.
POST  /martis/api/cache/enable           Toggle a runtime override ON.
POST  /martis/api/cache/reset-override   Drop the runtime override; falls back to config.
```

See [Cache Control Surface](../cache.md).

## Profile

User profile + avatar + 2FA + browser sessions (when `martis.profile.enabled`).

```
GET     /martis/api/profile                   Current user payload (name, email, avatar, 2fa state)
PATCH   /martis/api/profile                   Update name / email
POST    /martis/api/profile/password          Change password (requires current_password)
POST    /martis/api/profile/avatar            Upload avatar (multipart)
DELETE  /martis/api/profile/avatar            Remove avatar
POST    /martis/api/profile/2fa/setup         Initialize 2FA (returns QR code SVG + secret)
POST    /martis/api/profile/2fa/confirm       Verify the OTP code and activate 2FA
DELETE  /martis/api/profile/2fa               Disable 2FA
POST    /martis/api/profile/2fa/recovery-codes  Regenerate the recovery-code set
GET     /martis/api/profile/sessions                v1.8.8 — list active sessions for the current user
DELETE  /martis/api/profile/sessions/others        v1.8.8 — revoke every session except the current one
DELETE  /martis/api/profile/sessions/{id}          v1.8.8 — revoke a single session (current id is a no-op)
```

See [Authentication § Browser sessions](../authentication.md#browser-sessions).

## Impersonation

Two-layer guard (master switch + `martis-impersonate` Gate). Default master switch is OFF — endpoints return 503 until enabled.

```
GET   /martis/api/impersonation/status                   Snapshot — { active, enabled, original, target, started_at }
POST  /martis/api/impersonation/start/{userId}           Start (200 / 503 / 403 / 404 / 422)
POST  /martis/api/impersonation/stop                     Stop, restore operator (idempotent)
```

Error matrix:

| HTTP | Cause |
|---|---|
| 503 | Master switch off. |
| 403 | `martis-impersonate` Gate returned false. |
| 404 | Target user id does not exist on the configured guard's user provider. |
| 422 | Self-impersonation OR impersonation already active OR target implements `Martis\Contracts\NotImpersonable` (v1.8.8). |
| 200 | Started — body is the active snapshot. |

See [Impersonation](../impersonation.md).

## Meta

Read-only descriptors for the React layer (auth guard names, etc.).

```
GET  /martis/api/_meta/guards   List of `array_keys(config('auth.guards'))` for the GuardSelect field
```

## Attachments

```
POST  /martis/api/attachments/upload   Upload an inline asset (used by the rich-text Trix field)
```

Returns the URL the editor inserts inline. See [Fields § Trix](../fields.md).

## Error Responses

### Validation Error (422)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "title": ["The title field is required."],
    "email": ["The email field must be a valid email address."]
  }
}
```

### Unauthorized (401)

```json
{ "message": "Unauthenticated." }
```

### Not Found (404)

```json
{ "message": "Resource not found." }
```

### Forbidden (403)

```json
{ "message": "This action is unauthorized.", "errors": [] }
```

The `errors` array is intentionally empty so the SPA can route the same envelope through its generic 422-style error renderer; consumer overrides can populate it for richer messaging.

### Service Unavailable (503)

```json
{ "message": "Impersonation is disabled. Set `martis.impersonation.enabled` to true." }
```

Returned by surfaces gated behind a master switch (impersonation, optionally magic-link / forgot-password / email verification).
