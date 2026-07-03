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
import { martisRuntime } from '@martis/runtime'
import type { FieldDefinition } from '@martis/runtime'

const { useMartisForm, FieldsForm } = martisRuntime

const fields: FieldDefinition[] = [
  { type: 'text', attribute: 'title', label: 'Title' },
  // A real Martis Slug field: generates from `title`, formats as you type.
  { type: 'slug', attribute: 'slug', label: 'Slug', from: 'title' },
]

export function CreateProjectTool() {
  const form = useMartisForm({ fields })
  return <FieldsForm form={form} />
}
```

Typing into the Title field generates and formats the Slug — the same behaviour the field has in a Resource form, with zero PHP.

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
import { martisRuntime } from '@martis/runtime'

const { useToolFields, useMartisForm, FieldsForm } = martisRuntime

export function CreateProjectTool() {
  const { fields, isLoading, error } = useToolFields('create-project')
  const form = useMartisForm({ fields })

  if (isLoading) return <p>Loading…</p>
  if (error) return <p>Could not load fields.</p>
  return <FieldsForm form={form} />
}
```

`useToolFields('create-project')` issues `GET /api/tools/create-project/fields`, which serializes `Tool::fields()` through the same `Field::toArray()` serializer the Resource and Action forms already consume — so the returned shape is identical.

Tools that don't `use ProvidesToolFields` (the default) return no fields; the endpoint responds with `{ "fields": [] }`.

### Mode C — bound to an existing Resource

Pass `resourceKey` (and `recordId` when editing an existing record) so **server-backed** behaviours reuse that Resource's endpoints. This is what unlocks slug-uniqueness checks, `BelongsTo` option loading, and server `dependsOn` closures inside a Tool.

```tsx
import { martisRuntime } from '@martis/runtime'
import type { FieldDefinition } from '@martis/runtime'

const { useMartisForm, FieldsForm } = martisRuntime

const fields: FieldDefinition[] = [
  { type: 'text', attribute: 'title', label: 'Title' },
  { type: 'slug', attribute: 'slug', label: 'Slug', from: 'title' },
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

## The anchor example — a custom "create project" drawer

The end-to-end target: your own drawer (composed from `runtime.DrawerShell`, the bare slide-over) hosting a Martis Slug field with `Slug::make('slug')->from('title')` that behaves identically to the same field in a Resource form. Typing the title generates the slug; binding a `resourceKey` makes the uniqueness check live.

```tsx
import { useState } from 'react'
import { martisRuntime } from '@martis/runtime'
import type { FieldDefinition } from '@martis/runtime'

const { DrawerShell, useMartisForm, FieldsForm, api } = martisRuntime

const fields: FieldDefinition[] = [
  { type: 'text', attribute: 'title', label: 'Title' },
  { type: 'slug', attribute: 'slug', label: 'Slug', from: 'title' },
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
      // Feed a 422 straight into the form — errors render under each field.
      form.setErrors((e as { errors?: Record<string, string> }).errors ?? {})
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
import { martisRuntime } from '@martis/runtime'
import type { FieldDefinition } from '@martis/runtime'

const { useMartisForm, FieldInput } = martisRuntime

const titleField: FieldDefinition = { type: 'text', attribute: 'title', label: 'Title' }
const slugField: FieldDefinition = { type: 'slug', attribute: 'slug', label: 'Slug', from: 'title' }

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

## API contracts

### `useMartisForm(options): MartisForm`

`resources/js/hooks/useMartisForm.ts`.

**Options** (`MartisFormOptions`):

| Option | Type | Purpose |
|---|---|---|
| `fields` | `FieldDefinition[]` | The field set. May contain `tab_group` / `section` / `panel` containers. |
| `initialValues?` | `Record<string, unknown>` | Seed values (e.g. an existing record's attributes for an edit form). |
| `resourceKey?` | `string` | Scope for server-backed behaviours (Mode C). Omit for a pure-frontend form. |
| `context?` | `'create' \| 'update'` | Render/behaviour context. Defaults to `'create'`. |
| `recordId?` | `string \| number` | Id of the record being edited — threaded to relatable (`BelongsTo` / `MorphTo`) and `dependsOn` queries. Omit for create forms. |

**Returns** (`MartisForm`):

| Member | Type | Purpose |
|---|---|---|
| `values` | `Record<string, unknown>` | Current form values. |
| `setValue(attribute, value)` | `(string, unknown) => void` | Set one field; also clears that field's error. |
| `setValues(v)` | `(Record<string, unknown>) => void` | Replace all values. |
| `errors` | `Record<string, string>` | Per-attribute error messages. |
| `setErrors(e)` | `(Record<string, string>) => void` | Set errors — feed a server 422 body straight in. |
| `resolvedFields` | `FieldDefinition[]` | Fields with `dependsOn` overrides applied through the whole container tree. |
| `recordId?` | `string \| number` | Echo of the bound record id. |
| `fieldProps(field)` | see below | The exact prop bundle for a `FieldInput`. |

`fieldProps(field)` returns `{ field, value, onChange, error, resourceKey, recordId, formValues }` — spread it straight onto `<FieldInput {...form.fieldProps(field)} />`.

Internally `useMartisForm` runs the **same** `useDependsOnSync` the Resource pages run, and applies the resulting `dependsOn` overrides through the entire container tree (top-level and nested inside `section` / `panel` / `tab_group`). When there is no `resourceKey` the server `dependsOn` round-trip is disabled and overrides simply stay empty — offline degradation, not an error.

### `FieldsForm`

`resources/js/components/fields/FieldsForm.tsx`.

| Prop | Type | Purpose |
|---|---|---|
| `form` | `MartisForm` | The form from `useMartisForm`. Owns state. |
| `context?` | `'create' \| 'update'` | Render context. Defaults to `'create'`. |

Renders `form.resolvedFields` in declaration order — scalar fields wrapped in the standard `FieldWrapper` (label, required marker, tooltip, help text) and the `tab_group` / `section` / `panel` containers via their canonical renderers. This is the exact loop the Resource create/update pages use; they now consume this same component, so there is no duplication and no drift.

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
2. **`BelongsTo` outside a resource form needs `related_resource`.** Without a parent resource context, `BelongsTo` resolves options against a synthetic endpoint and needs the target resource's `uriKey` on its `FieldDefinition` (`related_resource`). For pure enum dropdowns prefer `select` — it has no async dependency and works anywhere. Binding a `resourceKey` (Mode C) is the cleaner path when the field belongs to a real Resource.

## Compatibility

This whole surface is **additive** (semver-minor):

- Nothing on the base `Tool` class changes; `Tool::fields()` is opt-in and defaults to `[]`, so existing Tools are untouched.
- The new runtime exports (`useMartisForm`, `FieldsForm`, `useToolFields` and their types) are new; removing or renaming them would be the breaking change, adding them is not.
- The endpoint and route are new; no existing route changed.
- The Resource create/update pages were refactored **behaviour-preservingly** onto this shared harness, guarded by characterization tests — Resource forms and Tool forms now come from one code path, so they cannot drift.

## See also

- [tools.md](tools.md) — the Tool class, registration, `boot()` lifecycle, REST surface, and the React side.
- [overrides.md §5.A](overrides.md#5a-composing-native-field-components-v1140) — composing a single `FieldInput` / `FieldDisplay` and the `DrawerShell`, plus the two caveats in full.
