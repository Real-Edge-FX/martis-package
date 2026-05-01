# Default Row Actions

Every Martis resource index ships with a trailing column of built-in row actions — **View**, **Edit**, and **Delete** — out of the box. No registration required. Icons disable themselves automatically when the row's authorization denies the operation.

This is a Martis differential: the View/Edit/Delete row actions are the default experience, and you can customize or opt out with three layers of control (global config, per-action global flag, per-resource override).

## How it looks

```
[ 👁  ✏  🗑 ]  [ custom inline 1 ]  [ custom inline 2 ]  [ ⋮ grouped ]
   defaults             your inline actions follow
```

- Default actions render **first**, in a fixed order: view → edit → delete.
- Custom `showInline()` actions render **after** the defaults.
- Grouped inline actions (3-dot menu) render last.
- An icon is dimmed and non-clickable when the row's `_authorization` denies it (`authorizedToView`, `authorizedToUpdate`, `authorizedToDelete`).
- Delete opens the package's standard confirm modal (soft-delete aware).

## Global configuration

`config/martis.php`:

```php
'index' => [
    'default_row_actions' => [
        'enabled' => env('MARTIS_DEFAULT_ROW_ACTIONS', true),

        // Per-action global kill-switches. Each defaults to true; flip a
        // single one to false (env or config) to hide that icon across
        // every resource.
        'view'   => env('MARTIS_DEFAULT_ROW_ACTION_VIEW', true),
        'edit'   => env('MARTIS_DEFAULT_ROW_ACTION_EDIT', true),
        'delete' => env('MARTIS_DEFAULT_ROW_ACTION_DELETE', true),
    ],
    'row_click_opens_detail' => env('MARTIS_ROW_CLICK_OPENS_DETAIL', true),
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `default_row_actions.enabled` | `bool` | `true` | Master switch for the defaults column. Set to `false` to hide everywhere unless an individual resource re-enables. |
| `default_row_actions.view` | `bool` | `true` | Show the view (eye) icon globally. |
| `default_row_actions.edit` | `bool` | `true` | Show the edit (pencil) icon globally. |
| `default_row_actions.delete` | `bool` | `true` | Show the delete (trash) icon globally. |
| `row_click_opens_detail` | `bool` | `true` | Whether clicking anywhere on a row opens the detail view. Set to `false` when the default "view" icon makes row-click redundant. |

The per-action flags compose with the resource-level override via **AND**: a resource can subtract further (return a smaller whitelist) but can never force a globally-disabled action back on.

## Row-click redundancy

With default row actions enabled, clicking on the row body AND clicking the "view" icon both open detail — one behavior becomes redundant. Choose:

| Setting | Behavior |
|---|---|
| `row_click_opens_detail = true` (default) | Both work. User can click anywhere on the row OR the eye icon. |
| `row_click_opens_detail = false` | Row-click disabled. User must use the "view" icon. The row loses its pointer cursor and hover-to-navigate intent — ideal for data-dense tables where users select/scan rows. |

Override per resource:

```php
public function rowClickOpensDetail(Request $request): ?bool
{
    return false; // explicit per-resource override
    // return null; // fall back to global config
}
```

## Per-resource override

Override via a **method** (not a property) so the decision can depend on the request, the current user, feature flags, etc. The resource returns either a boolean or a list of `Martis\Enums\DefaultRowAction` cases:

```php
use Illuminate\Http\Request;
use Martis\Enums\DefaultRowAction;

class ClientResource extends Resource
{
    public function defaultRowActions(Request $request): bool|array
    {
        return [DefaultRowAction::View, DefaultRowAction::Edit]; // subset
    }
}
```

Return values:

| Return | Effect |
|---|---|
| `true` | Fall back to the global config (default behavior). |
| `false` | Hide the defaults column entirely for this resource. |
| `array` of `DefaultRowAction` cases | Show only the listed actions. Cases: `DefaultRowAction::View`, `DefaultRowAction::Edit`, `DefaultRowAction::Delete`. |

> The whitelist is type-checked. The resolver does a strict `in_array(DefaultRowAction::View, $array, true)` against the returned list, so plain strings like `'view'` will not match — always import the enum and pass cases.

## Composing with custom inline actions

Inline actions you define on the resource always render **after** the defaults. This lets you add extras (e.g. *Approve*, *Archive*, *Duplicate*) without losing view/edit/delete.

```php
use Martis\Actions\Action;
use Martis\Actions\ActionResponse;

public function actions(Request $request): array
{
    return [
        Action::using('Approve', fn ($f, $models) => ActionResponse::message('Approved.'))
            ->showInline()
            ->icon('check-circle'),
    ];
}
```

Rendered order:

```
[ 👁  ✏  🗑 ]  [ Approve ]
```

If your custom inline action replaces the meaning of a default (e.g. a soft "Archive" instead of hard delete), opt out of the relevant default:

```php
use Martis\Enums\DefaultRowAction;

public function defaultRowActions(Request $request): bool|array
{
    // Drop Delete — we have a custom Archive action instead.
    return [DefaultRowAction::View, DefaultRowAction::Edit];
}
```

For destructive UI styling (red border, destructive button label) extend `Martis\Actions\DestructiveAction` instead of toggling a flag — see [actions.md § Destructive Actions](actions.md#destructive-actions).

## Authorization

The disabled state uses the standard row authorization payload delivered by the index endpoint:

| Default action | Check |
|---|---|
| View | `_authorization.authorizedToView !== false` |
| Edit | `_authorization.authorizedToUpdate !== false` |
| Delete | `_authorization.authorizedToDelete !== false` |

These are resolved server-side from the resource policy before the row is serialized. No extra work required to enforce permissions.

## Localization

Tooltip labels come from the `actions` namespace:

- `actions.view` → "View" / "Ver" / "Ver"
- `actions.edit` → "Edit" / "Editar" / "Editar"
- `actions.delete` → "Delete" / "Apagar" / "Excluir"

Published in EN, pt_PT and pt_BR out of the box.

## Opting out globally for a project

Disable the entire defaults column across the app:

```env
MARTIS_DEFAULT_ROW_ACTIONS=false
```

Or hide a single icon globally without touching individual resources:

```env
MARTIS_DEFAULT_ROW_ACTION_DELETE=false
# MARTIS_DEFAULT_ROW_ACTION_VIEW=false
# MARTIS_DEFAULT_ROW_ACTION_EDIT=false
```

These are AND-composed with the per-resource `defaultRowActions()` override, so the global flag always wins when it says "off".
