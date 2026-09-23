# Fields in Tools

Reuse any Martis Field — `Slug`, `Text`, `BelongsTo`, … — inside a custom Tool and get the exact same behaviour it has in a Resource form: slugify, generate-from-source, `dependsOn`, validation display, i18n, and theming, from one shared code path.

> **Status:** shipped in v1.20.0. Purely additive (semver-minor) — nothing on the base `Tool` class changes, existing Tools are unaffected, and the Resource create/update pages now render through the *same* harness this page exposes, so there is a single code path and no behaviour drift.

## The problem

Martis Fields and their behaviours used to be usable only inside a **Resource** form. A [Tool](tools.md) is a free-form full-canvas React page — a custom "create project" drawer, an import wizard, a settings surface. Historically a Tool could compose a single `FieldInput` (see [overrides.md §5.A](overrides.md#5a-composing-native-field-components-v1140)), but it could not get the *form-level* behaviour that lives in the Resource pages: shared `values` state, the `dependsOn` sync, per-field error clearing, and the container render loop (`tab_group` / `section` / `panel`).

The root cause was that "Resource form behaviour" was inline in `ResourceCreate.tsx` / `ResourceUpdate.tsx`. That machinery is now **extracted once** into a shared harness (`useMartisForm` + `FieldsForm`) that both the Resource pages and your Tools consume. Reusing it in a Tool is the whole point of this page.

## Mental model

Three moving parts, exposed on `@martis/runtime`:

| Piece | What it owns |
|---|---|
| `useMartisForm(options)` | Form **state**: `values`, `errors`, `setValue`, the `dependsOn` sync, and the `fieldProps(field)` bundle a `FieldInput` needs. This is the single source of truth for the form. |
| `FieldsForm` | The whole-form **renderer**: walks `form.resolvedFields` in declaration order and renders scalar fields plus the `tab_group` / `section` / `panel` containers — the exact loop the Resource pages use. |
| `useToolFields(toolKey)` | Optional **fetcher**: pulls field definitions declared in PHP (`Tool::fields()`) from the authorization-gated endpoint. |

A field's behaviour splits into two tiers:

- **Pure-frontend behaviours** (slugify, slug-from-source, formatting, error display) work **unconditionally** — no backend, no scope.
- **Server-backed behaviours** (slug uniqueness check, `BelongsTo` options, server `dependsOn` closures) need a **scope**. Bind one with `resourceKey` (and `recordId` for an existing record) so those requests route at an existing Resource's endpoints. Without a scope they degrade gracefully to offline (the slug still generates and formats, it just doesn't check uniqueness). This is a DEV choice, not a limitation.

## The three modes

Where a field's *definition* comes from is your choice, and the three sources are mixable in the same Tool.

### Mode A — hand-defined fields in TypeScript

Define a `FieldDefinition[]` inline in your Tool and feed it to `useMartisForm`. No backend at all. Pure-frontend behaviours work immediately.

```tsx
import { useMartisForm, FieldsForm } from '@martis/runtime'
import type { FieldDefinition } from '@martis/runtime'

const fields: FieldDefinition[] = [
  { type: 'text', attribute: 'title', label: 'Title' },
  // A real Martis Slug field: generates from `title`, formats as you type.
  { type: 'slug', attribute: 'slug', label: 'Slug', sourceAttribute: 'title' },
]

export function CreateProjectTool() {
  const form = useMartisForm({ fields })
  return <FieldsForm form={form} />
}
```

Typing into the Title field generates and formats the Slug — the same behaviour the field has in a Resource form, with zero PHP.

A definition you write needs only `type`, `attribute` and `label` (v1.38.0): the flags the server fills in (`nullable`, `required`, `showOnForms`, `rules`, …) are optional, and `type` also takes a custom field's type.

### Mode B — fields declared in PHP

Declare the fields on the Tool itself and fetch them at runtime. Opt in with the `ProvidesFields` contract and the `ProvidesToolFields` trait:

```php
// app/Martis/Tools/CreateProject.php
use Illuminate\Http\Request;
use Martis\Tools\Tool;
use Martis\Contracts\ProvidesFields;
use Martis\Concerns\ProvidesToolFields;
use Martis\Fields\Text;
use Martis\Fields\Slug;

class CreateProject extends Tool implements ProvidesFields
{
    use ProvidesToolFields;

    public function fields(Request $request): array
    {
        return [
            Text::make('Title'),
            Slug::make('Slug')->from('title'),
        ];
    }
}
```

The Tool declares its React component key exactly as any Tool does (see [tools.md](tools.md)). On the frontend, fetch the definitions with `useToolFields`:

```tsx
import { useToolFields, useMartisForm, FieldsForm } from '@martis/runtime'

export function CreateProjectTool() {
  const { fields, isLoading, error } = useToolFields('create-project')
  const form = useMartisForm({ fields })

  if (isLoading) return <p>Loading…</p>
  if (error) return <p>Could not load fields.</p>
  return <FieldsForm form={form} />
}
```

`useToolFields('create-project')` issues `GET /api/tools/create-project/fields`, which serializes `Tool::fields()` through the same `Field::toArray()` serializer the Resource and Action forms already consume — so the returned shape is identical.

A field the user cannot see (its own `canSee()`) is left out, as the Resource schema leaves it out (v1.38.0): at every depth of the layout containers, a `Section`, `Panel`, `TabGroup` or `Tab` left without fields goes with them, and a `Repeater`'s row types list only the row fields the user can see. The form never renders such a field, and its option search answers like an undeclared field's (see [Server-side option search](#server-side-option-search)). Before v1.38.0 the endpoint served every field of `fields()`, hidden ones included.

```php
public function fields(Request $request): array
{
    return [
        Text::make('Title'),
        // Served, and rendered, for an admin only.
        Text::make('Internal note')->canSee(fn (Request $request) => $request->user()?->isAdmin() ?? false),
    ];
}
```

Tools that don't `use ProvidesToolFields` (the default) return no fields; the endpoint responds with `{ "fields": [] }`.

### Mode C — bound to an existing Resource

Pass `resourceKey` (and `recordId` when editing an existing record) so **server-backed** behaviours reuse that Resource's endpoints. This is what unlocks slug-uniqueness checks, `BelongsTo` option loading, and server `dependsOn` closures inside a Tool.

```tsx
import { useMartisForm, FieldsForm } from '@martis/runtime'
import type { FieldDefinition } from '@martis/runtime'

const fields: FieldDefinition[] = [
  { type: 'text', attribute: 'title', label: 'Title' },
  { type: 'slug', attribute: 'slug', label: 'Slug', sourceAttribute: 'title' },
]

export function CreateProjectTool() {
  // Route server-backed behaviours at the `projects` Resource's endpoints.
  const form = useMartisForm({ fields, resourceKey: 'projects' })
  return <FieldsForm form={form} />
}
```

With `resourceKey: 'projects'`, the Slug field's uniqueness probe hits the `projects` Resource's slug endpoint; a `BelongsTo` resolves its options against `projects`. Editing a specific record? Add `recordId` so relatable and `dependsOn` queries scope to it:

```tsx
const form = useMartisForm({
  fields,
  resourceKey: 'projects',
  recordId: 42,
  context: 'update',
})
```

With `context: 'update'`, `form.resolvedFields` (and so `FieldsForm`) carries an `immutable()` field as `readonly`, so its input renders read-only as on the Resource update page. See [Fields → Immutable fields](fields.md#immutable-fields).

### Server-side option search

A `Select` declared in `Tool::fields()` with `searchOptionsUsing(...)` searches its options on the server through `GET /api/tools/{uriKey}/fields/{attribute}/options?search=...` (same `canSee()` gate as `/fields`: 404 when denied). A select the user cannot see (the field's own `canSee()`) answers 422 exactly like an undeclared one (v1.38.0). The form only needs to know which Tool owns the fields:

```tsx
const { fields } = useToolFields('settings')
const form = useMartisForm({ fields, toolKey: 'settings' })
```

Without `toolKey` (or a `resourceKey` for Mode C) the select keeps working with the initial `options()` list and filters it locally. See [Fields → Select](fields.md#select).

## The anchor example — a custom "create project" drawer

The end-to-end target: your own drawer (composed from `runtime.DrawerShell`, the bare slide-over) hosting a Martis Slug field with `Slug::make('slug')->from('title')` that behaves identically to the same field in a Resource form. Typing the title generates the slug; binding a `resourceKey` makes the uniqueness check live.

```tsx
import { useState } from 'react'
import { DrawerShell, useMartisForm, FieldsForm, api, ApiError } from '@martis/runtime'
import type { FieldDefinition } from '@martis/runtime'

const fields: FieldDefinition[] = [
  { type: 'text', attribute: 'title', label: 'Title' },
  { type: 'slug', attribute: 'slug', label: 'Slug', sourceAttribute: 'title' },
]

export function CreateProjectTool() {
  const [open, setOpen] = useState(false)
  // Mode C: server-backed slug uniqueness routes at the `projects` Resource.
  const form = useMartisForm({ fields, resourceKey: 'projects' })

  async function save() {
    try {
      await api.post('/api/tools/create-project', form.values)
      setOpen(false)
    } catch (e) {
      // Feed a 422 into the form: each error renders under its field, and a
      // Repeater row error under the row field it belongs to.
      form.setErrors(e instanceof ApiError ? e.errorsByField() : {})
    }
  }

  if (!open) return <button onClick={() => setOpen(true)}>New project</button>

  return (
    <DrawerShell title="Create project" onClose={() => setOpen(false)}>
      <FieldsForm form={form} />
      <div className="martis-form-actions">
        <button onClick={save}>Create</button>
      </div>
    </DrawerShell>
  )
}
```

Persistence stays the Tool's job — Martis does not impose a save pipeline on Tools. Point `save()` at whatever endpoint your Tool owns (register it from `Tool::boot()`; see [tools.md](tools.md)). The Slug field's generate-from-source, formatting, and (with `resourceKey`) uniqueness all come for free from the shared harness.

## The "where" freedom — whole form vs standalone field

`FieldsForm` renders the **entire** field set, containers included. But because `useMartisForm` hands you the `fieldProps(field)` bundle, you can also drop a **single** `FieldInput` anywhere in your own JSX and interleave it with non-Martis UI. Every field driven by the same `form` shares one state, so a Slug field still sees the Title field's value.

```tsx
import { useMartisForm, FieldInput } from '@martis/runtime'
import type { FieldDefinition } from '@martis/runtime'

const titleField: FieldDefinition = { type: 'text', attribute: 'title', label: 'Title' }
const slugField: FieldDefinition = { type: 'slug', attribute: 'slug', label: 'Slug', sourceAttribute: 'title' }

export function CreateProjectTool() {
  const form = useMartisForm({ fields: [titleField, slugField] })

  return (
    <div className="martis-form-body">
      <FieldInput {...form.fieldProps(titleField)} />

      {/* Your own UI in between — the fields still share form state. */}
      <p className="martis-help">Pick a URL-safe slug:</p>

      <FieldInput {...form.fieldProps(slugField)} />
    </div>
  )
}
```

Because both fields go through the same `useMartisForm`, typing the title still drives the slug even though they are not adjacent in a single container.

## Filter controls (v1.29.0)

A Tool that renders its own filter bar — separate from the field-form harness above — can reach for the same PrimeReact controls Martis's built-in filters use, now exposed on `@martis/runtime` alongside `FieldInput` / `DrawerShell` / `Tooltip`:

| Export | Purpose |
|---|---|
| `Dropdown`, `MultiSelect` | Single / multi filter controls. Add the `martis-filter-dropdown` class for the compact look, and pass `field.className` when routing through `FieldInput` (see [fields.md](fields.md#select) — the `select` field honours `variant: 'filter'`). Prefer the native `select` field with `searchableOptions` / `allowCustomValues` (v1.37.0) over a raw `Dropdown` when the control lives in a form. |
| `createPortal` | `react-dom`'s portal for overlays that must escape a clipped container, the host's copy. Since v1.38.0 `import { createPortal } from 'react-dom'` reaches the same function: the extension build sends `react-dom` to a shim that carries it and nothing else of `react-dom`. |
| `DropdownProps`, `MultiSelectProps` (types) | Type the controls without importing from `primereact/*` (the extension build doesn't alias it). |

```tsx
import { Dropdown } from '@martis/runtime'

<Dropdown
  className="martis-filter-dropdown"
  options={[{ label: 'Active', value: 'active' }, { label: 'Archived', value: 'archived' }]}
  onChange={(e) => setStatus(e.value)}
  placeholder="Status"
  showClear
/>
```

See [overrides.md §5.A](overrides.md#5a-composing-native-field-components-v1140) for the full runtime-exports table and a longer example.

The three are on the runtime since v1.29.0, but the extension's `.shims/runtime.mjs` exports them by name only since v1.38.0: on an extension scaffolded earlier, refresh the shim first (see [Refreshing the extension scaffold after an upgrade](installation-guide.md#refreshing-the-extension-scaffold-after-an-upgrade)).

## API contracts

### `useMartisForm(options): MartisForm`

`resources/js/hooks/useMartisForm.ts`.

**Options** (`MartisFormOptions`):

| Option | Type | Purpose |
|---|---|---|
| `fields` | `FieldDefinition[]` | The field set. May contain `tab_group` / `section` / `panel` containers. |
| `initialValues?` | `Record<string, unknown>` | Seed values (e.g. an existing record's attributes for an edit form). |
| `resourceKey?` | `string` | Scope for server-backed behaviours (Mode C). Omit for a pure-frontend form. |
| `toolKey?` | `string` | URI key of the Tool that declared the fields (Mode B). Scopes server-backed behaviours a Tool can own, today the remote Select search (v1.37.0), at `/api/tools/{toolKey}/...`. `dependsOn` sync still needs a `resourceKey`. |
| `context?` | `'create' \| 'update'` | Render/behaviour context. Defaults to `'create'`. |
| `recordId?` | `string \| number` | Id of the record being edited — threaded to relatable (`BelongsTo` / `MorphTo`) and `dependsOn` queries. Omit for create forms. |

**Returns** (`MartisForm`):

| Member | Type | Purpose |
|---|---|---|
| `values` | `Record<string, unknown>` | Current form values. |
| `setValue(attribute, value)` | `(string, unknown) => void` | Set one field; also clears that field's error. |
| `setValues(v)` | `(Record<string, unknown>) => void` | Replace all values. |
| `errors` | `Record<string, string>` | One message per validated path, as `ApiError.errorsByField()` returns a 422: a field's own error under its attribute, an error inside a field's value under its dotted path (`lines.1.fields.name`, a Repeater row field). |
| `setErrors(e)` | `(Record<string, string>) => void` | Set errors: pass `ApiError.errorsByField()` from a 422 (the server's `errors` list is not a map). |
| `resolvedFields` | `FieldDefinition[]` | Fields with `dependsOn` overrides applied through the whole container tree. |
| `recordId?` | `string \| number` | Echo of the bound record id. |
| `toolKey?` | `string` | Echo of the bound Tool key. |
| `fieldProps(field)` | see below | The exact prop bundle for a `FieldInput`. |

`fieldProps(field)` returns `{ field, value, onChange, error, nestedErrors, resourceKey, recordId, toolKey, context, formValues }` (`context` is the form's since v1.38.0, so an input that behaves differently on an edit form, a `Slug` or a Repeater's immutable row fields, sees `'update'`): spread it straight onto `<FieldInput {...form.fieldProps(field)} />`. `nestedErrors` holds the errors inside the field's value (a Repeater's rows, keyed `1.fields.name`), so a Repeater shows each row error under its row field (since v1.38.0).

Internally `useMartisForm` runs the **same** `useDependsOnSync` the Resource pages run, and applies the resulting `dependsOn` overrides through the entire container tree (top-level and nested inside `section` / `panel` / `tab_group`). When there is no `resourceKey` the server `dependsOn` round-trip is disabled and overrides simply stay empty — offline degradation, not an error.

### `FieldsForm`

`resources/js/components/fields/FieldsForm.tsx`.

| Prop | Type | Purpose |
|---|---|---|
| `form` | `MartisForm` | The form from `useMartisForm`. Owns state. |
| `context?` | `'create' \| 'update'` | Render context. Defaults to the context the form was built with (`useMartisForm({ context })`; before v1.38.0 it defaulted to `'create'`, so a form built for a stored record rendered its inputs as a create form unless told). |

Renders `form.resolvedFields` in declaration order — scalar fields wrapped in the standard `FieldWrapper` (label, required marker, tooltip, help text) and the `tab_group` / `section` / `panel` containers via their canonical renderers. This is the exact loop the Resource create/update pages use; they now consume this same component, so there is no duplication and no drift.

To start the form over for another entry, clear it with `setValues({})` and render `<FieldsForm>` under a `key` you change at the same time, so every input mounts again with the empty values, as the create page does for "Create & add another" (v1.38.0+). An input that keeps state of its own cannot always tell a cleared value from its own last one: a slug the user had emptied by hand is `null` before and after the form is cleared, and a custom input that reads its value once, when it mounts, never sees the clear at all.

### `useToolFields(toolKey): UseToolFieldsResult`

`resources/js/hooks/useToolFields.ts`.

Fetches `GET /api/tools/{toolKey}/fields` via the api client + react-query. Returns `{ fields, isLoading, error }` (`UseToolFieldsResult`). Disabled until a non-empty `toolKey` is supplied. Feed `fields` straight into `useMartisForm({ fields })`.

### Backend — `ProvidesFields` + the endpoint

`src/Contracts/ProvidesFields.php`, `src/Concerns/ProvidesToolFields.php`, `src/Http/Controllers/ToolFieldsController.php`.

- **`Martis\Contracts\ProvidesFields`** — opt-in contract. One method: `fields(Request $request): array`, returning Field builders exactly like `Action::fields()`.
- **`Martis\Concerns\ProvidesToolFields`** — trait supplying the default `fields(): []`. A Tool opts in with `implements ProvidesFields` + `use ProvidesToolFields` and overrides `fields()`. Tools that don't are unaffected.
- **`GET /api/tools/{uriKey}/fields`** (`ToolFieldsController`) — returns `{ "fields": [...] }`, each entry serialized via `Field::toArray()` (the serializer the Resource/Action forms already use).

The endpoint is **authorization-gated**. `Martis::findTool()` filters on `authorizedToSee()`, so a user who cannot see the Tool gets a **404** — indistinguishable from "tool does not exist", so field definitions never leak to an unauthorised caller. (Same guarantee as `GET /api/tools/{uriKey}` in [tools.md](tools.md).)

## Types

Also exported from `@martis/runtime` so you can type your options and results without reaching into internal paths: `MartisFormOptions`, `MartisForm`, `UseToolFieldsResult`, and (re-exported already) `FieldDefinition`.

## Caveats

Two operational caveats carry over from composing native field components. They are documented in full in [overrides.md §5.A "Composing native field components"](overrides.md#5a-composing-native-field-components-v1140); the short version:

1. **A consumer bundle hosted outside the Martis shell must load the published `martis.css`.** Field components rely on the `martis-*` class namespace. If your Tool renders inside Martis pages (the normal case — registered via `componentRegistry`) you inherit the styles for free. A bundle running outside the shell must also load `vendor/martis/assets/app-*.css`, or the fields render unstyled.
2. **Relation pickers take their scope from the props you pass.** `BelongsTo`, `MorphTo` and `Tag` scope their options with `resourceKey` / `recordId`, or `actionEndpoint` in a custom Action component. With no resource at all, the `FieldDefinition` must carry `relatedResource` (the target resource's `uriKey`). For pure enum dropdowns prefer `select`.

## Compatibility

This whole surface is **additive** (semver-minor):

- Nothing on the base `Tool` class changes; `Tool::fields()` is opt-in and defaults to `[]`, so existing Tools are untouched.
- The new runtime exports (`useMartisForm`, `FieldsForm`, `useToolFields` and their types) are new; removing or renaming them would be the breaking change, adding them is not.
- The endpoint and route are new; no existing route changed.
- The Resource create/update pages were refactored **behaviour-preservingly** onto this shared harness, guarded by characterization tests — Resource forms and Tool forms now come from one code path, so they cannot drift.

## See also

- [tools.md](tools.md) — the Tool class, registration, `boot()` lifecycle, REST surface, and the React side.
- [overrides.md §5.A](overrides.md#5a-composing-native-field-components-v1140) — composing a single `FieldInput` / `FieldDisplay` and the `DrawerShell`, plus the two caveats in full.
