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

The list of registered resources lives in the [Navigation endpoint](#navigation-endpoints) (`/api/navigation`). Each entry carries the `uriKey` you use below.

### Index (List Records)

```http
GET /martis/api/resources/{resource}
```

**Query Parameters:**

| Parameter | Example | Description |
|---|---|---|
| `search` | `?search=john` | Full-text search across the `searchable()` fields the user can see (`canSee()`; v1.38.0: the hidden ones were searched too) |
| `sort` | `?sort=name` | Sort attribute: a `sortable()` field the user can see (`canSee()`). Any other value (an unknown attribute, a field that is not sortable, one the user cannot see) is ignored and the list keeps its default order (v1.38.0: a sortable field the user could not see ordered the list). |
| `direction` | `?direction=desc` | `asc` (default) or `desc`; any other value, a non-string one included, means `asc` (v1.38.0: `?direction[]=` answered 500) |
| `per_page` | `?per_page=25` | Records per page |
| `page` | `?page=2` | Page number |
| `trashed` | `?trashed=only` | `only` or `with` for soft-deleted records; any other value, a non-string one included, lists the records that are not trashed (v1.38.0: `?trashed[]=` answered 500) |

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

**Record envelope.** Every record the API sends carries `id`, its field values and the `_title`, `_resource` and `_authorization` keys. A record that hides a field (`canSeeForModel()`) leaves its value out and lists its attribute under `_hidden` (v1.38.0): `"_hidden": ["salary"]`. The key is absent when the record hides no field. It holds the attributes of the fields of the response's field list (the index fields on the index, the detail fields on the detail, the update fields with `?context=update`), on the resource endpoints, the rows of a lens and the rows and records of the relationship endpoints, so a client that renders the schema's field list leaves those fields out instead of showing them empty. The `_pivot` values of a record a `BelongsToMany` / `MorphToMany` list attaches carry their own `_hidden`: the pivot fields hidden for that pivot row. See [Fields → Field authorization](../fields.md#field-authorization-cansee-and-canseeformodel).

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

Every field list leaves out a field the user cannot see (`canSee()`): the contextual arrays, and since v1.38.0 `fields` too (it listed every field of `fields()`), as well as the row fields of a Repeater's row types and the pivot fields of a `BelongsToMany` / `MorphToMany`. `fieldsForCreate` and `fieldsForInlineCreate` also leave out a field `canSeeForModel()` hides for the new model a create fills (v1.38.0), as does `inline-create-schema`; the other lists describe the resource, and each record names the fields it hides (`_hidden`, above).

### Inline create

```
POST /martis/api/resources/{resource}/inline-create-schema
POST /martis/api/resources/{resource}/inline-create
```

Drives the lightweight "Create related" form embedded in HasMany / BelongsToMany pickers without leaving the parent page.

### Slug live collision check

```http
GET /martis/api/resources/{resource}/slug-check/{field}?value=...&id=...
```

Used by `Slug::make()` for live "this slug is taken" hints in the create / update form. `id` is the record being edited: when it names a record the user may update (`authorizedToUpdate()`), that record is left out of the uniqueness probe and the Slug is read from `fieldsForUpdate()`; otherwise (no `id`, no such record, or a record the user may not update) the Slug is read from `fieldsForCreate()`, then `fieldsForInlineCreate()`, and nothing is left out of the probe. `fields()` is searched last. A Slug the user cannot see (`canSee()`, or `canSeeForModel()` for the record the form edits or the new one a create fills) answers 404 like an undeclared one (v1.38.0).

### Select option search

```http
GET /martis/api/resources/{resource}/fields/{field}/options?search=term&context=create|update&id=<record>
```

Backs `Select::searchOptionsUsing()` (v1.37.0). Locates the select in the field set of the given context (default `create`: `fieldsForCreate()`, then `fieldsForInlineCreate()` since v1.38.0; `update`: `fieldsForUpdate()`), gated on the matching ability like `sync-field` (`create`, or `update` with the record named by `id` bound first: `id` is required in the update context, 404 when it does not exist), and returns `{ options: [{ label, value, group? }] }` (`group` for grouped options, v2.0.0). 422 for an unknown field, a non-select field or a select without a server-side resolver, and for a select the user cannot see, which answers exactly like an unknown field (`canSee()`, or `canSeeForModel()` for the record the form edits or the new one a create fills; v1.38.0). A select in a Repeater row adds `&repeater={attribute}&repeatable={type}` and is found in that row type's `fields()` (v1.38.0, see [Repeater → Relation pickers and remote selects in rows](../repeater.md#relation-pickers-and-remote-selects-in-rows)).

### Lenses

```http
GET /martis/api/resources/{resource}/lenses/{lens}
```

Index endpoint for the named lens. Same query params as the resource index. `sort` names a sortable field of the lens's own `fields()` the user can see; any other column is dropped before the lens runs, so its default ordering applies (v1.38.0: the lens was ordered by any column `sort` named, and a column that does not exist answered 500 on MySQL / PostgreSQL). See [Lenses → Which columns sort a lens](../lenses.md#which-columns-sort-a-lens).

### Reactive (`dependsOn`) field sync

```http
POST /martis/api/resources/{resource}/sync-field
Body: { field: "<attribute>", formData: { ...current form values... }, context: "create" | "update", id?: <record> }
```

Server-side resolution of reactive `dependsOn()` fields. Frontend debounces (200 ms) + uses `AbortController` so the latest value always wins. Gated on the create ability, or on the update ability with the record named by `id` bound first (required in the update context since v1.37.0, so a policy typed `update(User, Model)` receives the model; 404 when the record does not exist). Rejects unknown attributes (422), non-reactive attributes (422), empty attribute names (422) and a `field` that is not a string (422; v1.38.0: 500); a field the user cannot see answers exactly like an unknown attribute (v1.38.0). The field is looked up in the same field set as the select search above (`create` covers `fieldsForInlineCreate()` since v1.38.0). See [Fields § Reactive fields](../fields.md#reactive-fields--dependsonfield-closure).

## Relationship Endpoints

### BelongsTo options (relatable)

```http
GET /martis/api/resources/{resource}/{id}/relatable/{field}
GET /martis/api/resources/{resource}/{id}/relatable/{field}?search=term
```

Returns the option list for a BelongsTo / MorphTo / Tag picker, filtered by the resource's `relatableQuery()` if defined. The field is looked up on the form the picker renders in: `fieldsForUpdate()` (on the resource bound to the record) when `{id}` names a record the user may update (`authorizedToUpdate()`), otherwise `fieldsForCreate()` then `fieldsForInlineCreate()` (`{id}` = `_` on a create form; a record the user may not update is answered like a missing one); `fields()` comes last. A picker declared on one form only resolves (v1.38.0). A create form nested in another resource's page (the inline-create modal) sends `_`, the pickers of an action modal use the action's own endpoint (see [Actions](#actions)), and the pickers among a relationship's pivot fields the panel's (below). A picker in a Repeater row adds `&repeater={attribute}&repeatable={type}` to any of them and is read from that row type's `fields()` (v1.38.0). On every one of them a picker the user cannot see answers 404 exactly like an undeclared one (v1.38.0): its field's `canSee()`, or `canSeeForModel()` for the record the form edits (the new one a create fills, the pivot row for a pivot field), a row field's `canSee()` and the `canSee()` of the Repeater holding the row. See [Relationships → Relation fields declared on one form only](../relationships.md#relation-fields-declared-on-one-form-only).

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
| `PUT` | `/{r}/{id}/has-one/{rel}?relatedId={id}` | Update the record the card shows. `relatedId` (required, v2.0.0) is the id the client read from the `GET`: `422` without it, `409` when the relationship holds another record by then (see [Conflict (409)](#conflict-409)). The policy is checked first, so a user who may not update the record gets `403` whatever the id. |
| `DELETE` | `/{r}/{id}/has-one/{rel}?relatedId={id}` | Delete the record the card shows, under the same rules. |
| `GET` | `/{r}/{id}/belongs-to-many/{rel}` | List with pivot data. |
| `GET` | `/{r}/{id}/belongs-to-many/{rel}/attachable` | Options available to attach. `meta.hiddenPivotFields` lists the attributes of the pivot fields a new row hides (`canSeeForModel()` on the row the attach writes), which the attach modal leaves out (v1.38.0). |
| `POST` | `/{r}/{id}/belongs-to-many/{rel}/attach` | Attach with optional pivot fields. |
| `DELETE` | `/{r}/{id}/belongs-to-many/{rel}/{relatedId}/detach` | Detach. |
| `PUT` | `/{r}/{id}/belongs-to-many/{rel}/{relatedId}/pivot` | Update pivot row. |
| `GET` | `/{r}/{id}/belongs-to-many/{rel}/pivot-fields/relatable/{field}` | Options of a `BelongsTo` / `MorphTo` / `Tag` pivot field in the attach modal: read from the relationship's `fields()`, gated like the panel and on `attachAny{Model}`, with the parent resource as the source of the relatable hooks (v1.38.0). |
| `GET` | `/{r}/{id}/belongs-to-many/{rel}/pivot-fields/{relatedId}/relatable/{field}` | The same in the pivot edit modal of an attached record, gated on its `updatePivot{Model}` (v1.38.0). |

(`/{r}` is shorthand for `/martis/api/resources/{resource}`.) MorphMany / MorphOne / MorphToMany follow the same shape under `/morph-many/`, `/morph-one/`, `/morph-to-many/`. The `morph-one` `PUT` and `DELETE` take the same required `?relatedId=`.

The lists (`GET` on `has-many`, `morph-many`, `belongs-to-many` and `morph-to-many`) take the `search`, `sort`, `direction` and `per_page` parameters of the resource index, applied with the related resource's fields: `sort` names a `sortable()` field of the related resource the user can see, and anything else is ignored (v1.38.0).

A relationship field that `canSeeForModel()` hides for the parent record answers 404 on all of them, as an undeclared relationship (v1.38.0). Every record they send leaves out the fields hidden for it, and their writes neither validate nor write those fields: the new model decides on a create, the pivot row on an attach and a pivot update (v1.38.0). See [Fields → Field authorization](../fields.md#field-authorization-cansee-and-canseeformodel).

## Actions

Per-resource and per-row action execution.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/martis/api/resources/{resource}/actions` | List actions visible on the index. |
| `GET` | `/martis/api/resources/{resource}/actions/{action}/fields` | Confirmation-modal field schema for an action, without the fields the user cannot see (v1.38.0). |
| `GET` | `/martis/api/resources/{resource}/actions/{action}/relatable/{field}` | Options of a `BelongsTo` / `MorphTo` / `Tag` the action declares, read from the action's `fields()` (v1.38.0). Gated on `viewAny` of the resource, the action's `canSee()` and `viewAny` of the related resource. |
| `POST` | `/martis/api/resources/{resource}/actions/{action}` | Run a bulk / standalone action. |
| `POST` | `/martis/api/resources/{resource}/{id}/actions/{action}` | Run an inline (per-row) action. |
| `GET` | `/martis/api/resources/{resource}/{id}/{belongs-to-many\|morph-to-many}/{rel}/actions` | Pivot-row actions list. |
| `GET` | `/martis/api/resources/{resource}/{id}/{belongs-to-many\|morph-to-many}/{rel}/actions/{action}/fields` | Field schema of a pivot action, without the fields the user cannot see (v1.38.0). |
| `GET` | `/martis/api/resources/{resource}/{id}/{belongs-to-many\|morph-to-many}/{rel}/actions/{action}/relatable/{field}` | Options of a `BelongsTo` / `MorphTo` / `Tag` a pivot action declares, behind the panel's pivot action gates, with the parent resource as the source of the relatable hooks (v1.38.0). |
| `POST` | `/martis/api/resources/{resource}/{id}/{belongs-to-many\|morph-to-many}/{rel}/actions/{action}` | Run a pivot-row action: a dry run (`dryRun: true` with `withDryRun()`) answers `{ preview }`, a `ShouldQueue` action is queued, and the run is written to the action event log (v1.38.0). Gated on the parent's `runAction` / `runDestructiveAction` policy ability like a resource action. |

A run validates, and takes from `fields`, only the values of the fields the user may set: a field the user cannot see, a readonly one and a computed one are not validated, and `handle()` receives their `default()` or nothing (v1.38.0). See [Actions → Fields the request cannot set](../actions.md#fields-the-request-cannot-set). The pickers of an Action modal answer 404 for a field the user cannot see, like an undeclared one (v1.38.0).

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

`/navigation/badges` returns a flat `{ "resource:users": 1284, "tool:standards": 7 }` map keyed by `"{type}:{uriKey}"`. The SPA polls this endpoint at the cadence configured in `martis.navigation.badges_poll_interval` (default 300 000 ms = 5 min) and merges the values into the cached tree. 5–10× cheaper server-side than the full tree; resources that opt out of `showMenuCount()` are excluded, and counters whose `menuCount()` throws are skipped and reported (v1.32.4). With dev tools on, the failed counters are listed under a reserved `_failed` key. See [Menus → Badges-Only API](../menus.md#badges-only-api-v188).

## Global Search

```http
GET /martis/api/search?q=...
```

Cross-resource record search. Powers the topbar search input. Each resource is matched on the `searchable()` fields the user can see (v1.38.0). See [Global Search](../global-search.md).

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
GET  /martis/api/tools/{uriKey}/fields  Serialized field definitions of a Tool implementing ProvidesFields,
                                        without the fields the user cannot see (canSee(); v1.38.0).
GET  /martis/api/tools/{uriKey}/fields/{field}/options?search=
                                        Server-side option search for a Tool select (v1.37.0); 422 when the field has no resolver.
                                        A select in a Repeater row adds &repeater=&repeatable= (v1.38.0).
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

The resource, relationship, pivot and Action endpoints answer a failed
validation with the Martis envelope: `errors` is a list with one entry per
message, and `field` is the path of the value that failed.

```json
{
  "message": "The given data was invalid.",
  "errors": [
    { "field": "title", "message": "The Title field is required.", "code": "required" },
    { "field": "sections.1.fields.key", "message": "The Key field is required.", "code": "required" }
  ]
}
```

`field` is the field's attribute for the field's own error, and the dotted path
of the value for an error inside a field's value: `sections.1.fields.key` is the
`key` field of row 1 of the `sections` Repeater (see
[Repeater § Validation](../repeater.md#validation)). `code` is a best-effort
hint derived from the message (`required`, `unique`, `email`, `min`, `max`,
otherwise `invalid`). The top-level `message` is the resource's
`validationMessage()` on the resource endpoints, "Validation failed." on the
relationship and pivot endpoints, and "The given data was invalid." on the
Action endpoints.

The endpoints that validate with Laravel's `$request->validate()` (login,
registration, password reset, profile, two-factor, magic link) answer with
Laravel's own shape instead, a map of messages per field:

```json
{
  "message": "The email field is required.",
  "errors": {
    "email": ["The email field is required."]
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

### Conflict (409)

```json
{ "message": "The record changed since the card loaded; reload to see it.", "errors": [] }
```

A `PUT` or `DELETE` on a one-record card (`…/has-one/{rel}`, `…/morph-one/{rel}`) whose `?relatedId=` is not the record the relationship holds now: a newer one-of-many record, a replaced `HasOne` / `MorphOne`, or another record through a `HasOneThrough` (the shown one or its intermediate record trashed, for instance). Nothing is written; reload the card (its `GET`) and retry on the record it returns. The message is translated (`martis::messages.card_record_changed`).

### Service Unavailable (503)

```json
{ "message": "Impersonation is disabled. Set `martis.impersonation.enabled` to true." }
```

Returned by surfaces gated behind a master switch (impersonation, optionally magic-link / forgot-password / email verification).
