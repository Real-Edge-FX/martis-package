# Martis API — Overview

Martis provides a REST API for all CRUD operations, resource metadata, and configuration. The API is automatically documented via Scramble (OpenAPI/Swagger).

## Access

| Item | Value |
|------|-------|
| Swagger UI | `http://martis.realedgefx.com/docs/api` |
| Base URL | `/martis/api` |
| Format | JSON |
| Auth | Sanctum Bearer token (configurable guard via `MARTIS_GUARD`) |

## Authentication

### Login

```http
POST /martis/login
Content-Type: application/json

{
  "email": "admin@martis.local",
  "password": "password"
}
```

Response:

```json
{
  "token": "1|abc123..."
}
```

### Using the Token

Include the token in all subsequent requests:

```http
Authorization: Bearer 1|abc123...
```

### Check Current User

```http
GET /martis/api/auth/user
```

### Logout

```http
POST /martis/api/auth/logout
```

## Resource Endpoints

### List Available Resources

```http
GET /martis/api/resources
```

Returns all registered resources with their labels, icons, groups, and URI keys.

```json
[
  {
    "uriKey": "users",
    "label": "Users",
    "singularLabel": "User",
    "icon": "users",
    "group": null
  },
  {
    "uriKey": "posts",
    "label": "Posts",
    "singularLabel": "Post",
    "icon": "newspaper",
    "group": "Content"
  }
]
```

### Index (List Records)

```http
GET /martis/api/{resource}
```

**Query Parameters:**

| Parameter | Example | Description |
|-----------|---------|-------------|
| `search` | `?search=john` | Full-text search across `searchable()` fields |
| `sort` | `?sort=name` | Sort column |
| `direction` | `?direction=desc` | Sort direction: `asc` (default) or `desc` |
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
      "email": "admin@martis.local",
      "_title": "Admin User",
      "_resource": {
        "uriKey": "users",
        "label": "Users"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 15,
    "total": 42,
    "from": 1,
    "to": 15
  },
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  }
}
```

### Create Record

```http
POST /martis/api/{resource}
Content-Type: application/json

{
  "name": "New User",
  "email": "new@example.com",
  "password": "secret123"
}
```

For file uploads, use `multipart/form-data`:

```http
POST /martis/api/{resource}
Content-Type: multipart/form-data

name=New Post
title=Hello World
cover_image=@/path/to/image.jpg
```

### Show Record (Detail)

```http
GET /martis/api/{resource}/{id}
```

### Update Record

```http
PUT /martis/api/{resource}/{id}
Content-Type: application/json

{
  "name": "Updated Name"
}
```

### Delete Record

```http
DELETE /martis/api/{resource}/{id}
```

For soft-delete models, this archives the record. Use the restore endpoint to unarchive.

### Restore Soft-Deleted Record

```http
GET /martis/api/{resource}/{id}/restore
```

## Schema Endpoint

Returns the field structure and metadata for a resource.

```http
GET /martis/api/{resource}/schema
```

**Response:**

```json
{
  "resource": "posts",
  "label": "Posts",
  "singularLabel": "Post",
  "titleAttribute": "title",
  "icon": "newspaper",
  "group": "Content",
  "perPageOptions": [15, 25, 50],
  "defaultPerPage": 15,
  "indexSearchable": true,
  "tableStriped": false,
  "tableShowGridlines": false,
  "tableSize": "normal",
  "tableRowHover": true,
  "errorDisplay": "inline",
  "overrides": {
    "create": null,
    "update": null,
    "detail": null,
    "index": null
  },
  "fields": [
    {
      "attribute": "title",
      "label": "Title",
      "type": "text",
      "component": null,
      "sortable": true,
      "searchable": true,
      "nullable": false,
      "required": true,
      "readonly": false,
      "showOnIndex": true,
      "showOnDetail": true,
      "showOnForms": true,
      "rules": [],
      "placeholder": null,
      "colSpan": 12,
      "colSpanMd": null,
      "colSpanLg": null,
      "overrides": null
    }
  ],
  "fieldsForIndex": [...],
  "fieldsForDetail": [...],
  "fieldsForCreate": [...],
  "fieldsForUpdate": [...]
}
```

## Relationship Endpoints

### BelongsTo Options (Relatable)

Returns available options for a BelongsTo relationship dropdown.

```http
GET /martis/api/{resource}/{id}/relatable/{field}
GET /martis/api/{resource}/{id}/relatable/{field}?search=term
```

**Response:**

```json
{
  "options": [
    { "id": 1, "title": "Admin User" },
    { "id": 2, "title": "Regular User" }
  ]
}
```

The results are filtered by the resource's `relatableQuery()` method if defined.

### HasMany Records

Returns related records for a HasMany relationship.

```http
GET /martis/api/{resource}/{parentId}/has-many/{relationship}
```

Supports the same query parameters as the index endpoint (search, sort, direction, per_page, page).

## Translation Endpoint

Returns translated strings for a given locale. This endpoint is **public** (no authentication required).

```http
GET /martis/api/translations/{locale}
```

**Available locales:** `en`, `pt-BR`, `pt-PT`

**Response:**

```json
{
  "actions": {
    "save": "Save",
    "cancel": "Cancel",
    "delete": "Delete",
    "edit": "Edit",
    "create": "Create"
  },
  "messages": {
    "created": "Record created successfully.",
    "updated": "Record updated.",
    "deleted": "Record deleted.",
    "delete_confirm": "Are you sure you want to delete this record?"
  },
  "navigation": {
    "dashboard": "Dashboard"
  }
}
```

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
{
  "message": "Unauthenticated."
}
```

### Not Found (404)

```json
{
  "message": "Resource not found."
}
```

### Forbidden (403)

```json
{
  "message": "This action is unauthorized."
}
```

## Navigation Endpoint

Returns the canonical navigation structure used by the Martis frontend.

```http
GET /martis/api/navigation
```

**Response:**

```json
[
  {
    "label": "Quick Links",
    "icon": null,
    "collapsable": false,
    "items": [
      { "type": "link", "label": "Dashboard", "url": "/", "icon": "squares-four", "external": false }
    ]
  },
  {
    "label": "Content",
    "icon": null,
    "collapsable": true,
    "items": [
      { "type": "resource", "uriKey": "posts", "label": "Posts", "icon": "newspaper", "url": "/resources/posts", "external": false },
      { "type": "resource", "uriKey": "categories", "label": "Categories", "icon": "folder", "url": "/resources/categories", "external": false }
    ]
  }
]
```
