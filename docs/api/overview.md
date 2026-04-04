# API Overview

Martis exposes a JSON API for the React frontend. All endpoints are prefixed with the configured admin path (default: `/martis`).

## Authentication

### Login

```
POST /martis/api/auth/login
Content-Type: application/json

{ "email": "admin@example.com", "password": "secret" }
```

Returns the authenticated user on success, 401 on failure. Rate-limited to 5 attempts per minute.

### Logout

```
POST /martis/api/auth/logout
```

### Current User

```
GET /martis/api/auth/user
```

Returns the authenticated user or `null`.

## Navigation

```
GET /martis/api/navigation
```

Returns the navigation tree with all registered resources, grouped by `group()`.

## Translations

```
GET /martis/api/translations/{locale}
```

Returns all translation strings for the given locale (e.g. `en-US`, `pt-BR`).

## Resource CRUD

All resource endpoints follow the pattern `/martis/api/resources/{resource}` where `{resource}` is the resource URI key (e.g. `posts`, `users`).

### Schema

```
GET /martis/api/resources/{resource}/schema
```

Returns the resource metadata and pre-filtered field arrays for each context (index, detail, create, update). The frontend consumes this directly without additional filtering.

**Response:**

```json
{
  "uriKey": "posts",
  "label": "Posts",
  "singularLabel": "Post",
  "fields": {
    "index": [...],
    "detail": [...],
    "create": [...],
    "update": [...]
  },
  "authorization": {
    "viewAny": true,
    "create": true
  },
  "config": {
    "searchable": true,
    "perPage": 25,
    "perPageOptions": [15, 25, 50],
    "softDeletes": false,
    "tableStriped": true
  }
}
```

### Index (List)

```
GET /martis/api/resources/{resource}
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | integer | Page number (default: 1) |
| `per_page` | integer | Items per page (default: resource config) |
| `sort` | string | Sort field (e.g. `title`) |
| `direction` | string | Sort direction: `asc` or `desc` |
| `search` | string | Full-text search query |

### Create

```
POST /martis/api/resources/{resource}
Content-Type: application/json

{ "title": "My Post", "body": "Content..." }
```

Returns the created resource with a success message.

### Show

```
GET /martis/api/resources/{resource}/{id}
```

Returns a single resource record with detail fields resolved.

### Update

```
PUT /martis/api/resources/{resource}/{id}
Content-Type: application/json

{ "title": "Updated Title" }
```

### Delete

```
DELETE /martis/api/resources/{resource}/{id}
```

### Restore (Soft Deletes)

```
PUT /martis/api/resources/{resource}/{id}/restore
```

Only available when the resource has `softDeletes()` enabled.

## Middleware

All protected routes use the `martis.auth` middleware. Public routes (login, logout, translations, auth check) are accessible without authentication.

Protected API routes are additionally rate-limited to 60 requests per minute by default (configurable via `martis.api_middleware`).

## Error Responses

Validation errors return HTTP 422:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "title": ["The title field is required."]
  }
}
```

Authorization failures return HTTP 403. Unauthenticated requests return HTTP 401.
