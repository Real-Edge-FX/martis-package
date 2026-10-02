# Custom pages at your own URLs

Register a page of your application at a URL of its own below the Martis path, rendered inside the standard Martis shell. The route comes from your extension bundle, through `routeRegistry` on `@martis/runtime` (v2.2.0+).

The page keeps the sidebar, the topbar, the footer, the mobile drawer, the breadcrumbs and the navigation progress bar, in every layout preset, at URLs such as `/martis/findings`, `/martis/findings/{finding}` or `/martis/projects/{project}/repositories`.

Use it when a page needs a product URL. A page that is fine at `/tools/{uriKey}` can stay a plain [Tool](tools.md).

## Registering a page

```tsx
// resources/js/martis-extensions/index.ts, at the top level
import { routeRegistry } from '@martis/runtime'
import FindingsPage from './pages/FindingsPage'
import FindingDetailPage from './pages/FindingDetailPage'

routeRegistry.register({ path: 'findings', component: FindingsPage, crumb: 'Findings' })
routeRegistry.register({ path: 'findings/:findingId', component: FindingDetailPage, crumb: 'Findings', tool: 'findings' })
routeRegistry.register({ path: 'projects/:projectId/repositories', component: 'page:project-repositories' })
```

`register()` takes:

| Key | Required | Meaning |
|---|---|---|
| `path` | yes | The path below the Martis base path. Leading and trailing slashes are ignored: `findings` and `/findings/` are the same route. |
| `component` | yes | The React component (`FindingsPage`, not `<FindingsPage />`; `lazy()` components work), or a `componentRegistry` key resolved when the page renders. |
| `crumb` | no | The breadcrumb label, shown as given. Without it, the first segment of the path is used: `project-reports` shows `Project reports`. |
| `tool` | no | The `uriKey` of a Tool whose `canSee()` and soft lock guard the page. See [Guarding a page with a Tool](#guarding-a-page-with-a-tool). |

It returns `true` when the route is accepted. A refused route is not added: `register()` returns `false` and the browser console shows `[martis] routeRegistry:` with the reason. Nothing throws, so one bad route never stops the rest of your bundle from registering.

Build the bundle as usual (`npm run build:extensions`). A reload of a registered URL needs no server route: Martis serves its SPA for every path below its base path except `api/`.

### When to register

Martis builds its router once, after every bundle listed in `MARTIS_EXTENSIONS` has loaded. Register routes at the top level of the bundle, as above. A `register()` call that runs later (in an effect, after a click, after an `await`) is refused with `routes must be registered when the extension bundle loads (at its top level): the router was already built`. The same refusal shows for a bundle that took longer than the loader's 5-second timeout to load: Martis mounted without it, so check the bundle's size and URL before moving any code.

## Path rules

- Each segment is static text (letters, digits, `.`, `_`, `~`, `-`), a parameter (`:findingId`), or `*`, allowed only as the last segment, where it matches the rest of the path.
- Optional segments (`:id?`), empty segments, spaces, `#` and `%` are refused.
- The first segment must be static text that Martis does not use itself. These are reserved, in any letter case:

  | Reserved first segment | Why |
  |---|---|
  | `dashboards`, `profile`, `system`, `dev`, `tools`, `resources`, `403`, `500` | Pages of the Martis shell |
  | `login`, `register`, `forgot-password`, `reset-password`, `email`, `invitations`, `magic-link`, `2fa`, `password` | Sign-in and account pages |
  | `api`, `api-docs`, `sso`, `logout`, `favicon.ico` | Server routes: a reload would never reach the SPA (`api-docs` is the default path of the [API documentation](api/overview.md#enabling-the-openapi-surface)) |

  So a registered page can never take the place of a Martis page, and `:slug`, `*` and an empty path are refused.
- An app that moves the API documentation elsewhere (`martis.api_docs.path`) must not register a page on that path either: the server answers it on a reload.
- Two routes with the same shape (`findings/:id` and `Findings/:findingId`) are one route, and registering it twice refuses both: the console shows the refusal and the path renders the 404 page until one registration is removed. Keeping either would make the page depend on which extension bundle happened to load first, since bundles load in parallel.
- Two routes of different shapes can both match one URL: a static segment beats a parameter (`findings/new` wins over `findings/:findingId` on `/findings/new`). When React Router ranks them equal (`a/:x/c` and `a/b/:y` on `/a/b/c`), the route whose path comes first in alphabetical order wins, whatever order they were registered in.

A path that matches no registered route and no Martis page still renders the 404 page inside the shell.

## Writing the page

The page is an ordinary React component. Read the route parameters with `useParams()` and set the tab title with `usePageTitle()`:

```tsx
// resources/js/martis-extensions/pages/FindingDetailPage.tsx
import { ApiError, ForbiddenPage, MartisLoader, NotFoundPage, api, useDynamicCrumb, usePageTitle, useParams, useQuery } from '@martis/runtime'

export default function FindingDetailPage() {
  const { findingId } = useParams<{ findingId: string }>()
  const finding = useQuery({
    queryKey: ['finding', findingId],
    queryFn: () => api.get<{ id: string; title: string }>(`/api/findings/${encodeURIComponent(findingId!)}`),
  })

  usePageTitle(finding.data?.title)
  useDynamicCrumb(finding.data?.title)

  if (finding.error instanceof ApiError && finding.error.status === 404) return <NotFoundPage />
  if (finding.error instanceof ApiError && finding.error.isForbidden()) return <ForbiddenPage />
  if (!finding.data) return <MartisLoader />

  return <h1>{finding.data.title}</h1>
}
```

- **Parameters.** `useParams()` returns the `:parameters` of the path.
- **Remounting.** The page remounts when the part of the path its own segments match changes (`/findings/1` to `/findings/2`), so its state starts fresh for each record. A change of the query string alone keeps it, and so does a change below a final `*`: a `reports/*` page keeps its state from `/reports/summary` to `/reports/by-team`, so it can own its sub-paths (tabs, steps, nested `<Routes>`). In `orgs/:org/*`, a change of `:org` remounts it.
- **Breadcrumb.** The trail reads Home, then the route's crumb. `useDynamicCrumb(label)` replaces that crumb while the page is mounted, for example with the record's title; `null` or `undefined` keeps the registered one. The trail has one level: `findings/:findingId` does not link back to `findings`.
- **Error screens.** `NotFoundPage` and `ForbiddenPage` render the shell's 404 and 403 screens in place and keep the URL. Navigating to `/403` would change it.
- **Data.** `api` calls paths below the Martis base path, so `api.get('/api/findings/...')` reaches an API route of your app under `/{martis-path}/api/`, on the `martis.api` middleware group, which runs the same authentication as the Martis API.
- **Encode what you put in a path.** A route parameter is URL-decoded (`useParams()` returns `..%2Fusers%2F5` as `../users/5`), and so is a value from `useSearchParams()`. Interpolated raw into `api.get(`/api/findings/${id}`)`, a crafted link or a record keyed `../users/5` rewrites the request to another endpoint of the panel, with the signed-in user's session and CSRF token (client-side path traversal). Build the path with `apiPath` from `@martis/runtime` (v2.4.0+): `api.get(apiPath`/api/findings/${id}`)` encodes every interpolated value as one path segment, and `withQuery(path, query)` appends a built query string. Do not use `encodeURIComponent()` for this: Laravel decodes `%2F` before it routes, so an id `5/force` sent as `5%2Fforce` reaches the `/5/force` route. `apiPath` spells a slash `%252F`, which no route splits (the request answers 404). For a single value use `pathSegment(id)`. A path value that holds a literal `%2F` (either case) makes `apiPath` / `pathSegment` throw an `ApiError` (status 400), because its spelling would collide with a slash and address another record; a record keyed that way cannot be addressed from the panel, as one keyed `..` cannot. Use `routePath` for a link of the SPA's own router (`navigate(routePath`/findings/${id}`)`), which keeps a plain `%2F`. As a last line of defence `api` refuses a path that holds a dot segment (`.` or `..`, also spelt `%2e`) with an `ApiError` (status 400) and sends nothing.

## Guarding a page with a Tool

Without `tool`, a registered page is open to every user who may open the panel (see [Panel access](authorization.md#panel-access-viewmartis)); the data it shows is protected by the API routes it calls. To hide the page itself, bind it to a Tool:

```php
namespace App\Martis\Tools;

use App\Models\Finding;
use Illuminate\Http\Request;
use Martis\Tools\Tool;

class Findings extends Tool
{
    public function __construct()
    {
        parent::__construct(name: 'Findings', uriKey: 'findings');

        $this->canSee(fn (Request $request): bool => $request->user()?->can('viewAny', Finding::class) ?? false);
    }
}
```

Register the Tool with `Martis::tools([Findings::class])`, then pass `tool: 'findings'`. Before the page renders, the shell asks `GET /api/tools/findings`, exactly as `/tools/findings` does:

- a user the Tool is hidden from gets the Tool's "Tool not found" state, the same answer as for a Tool that does not exist;
- a user the Tool is locked for (`lockedFor()`, see [Gates](gates.md)) gets the lock page, and the lock modal opens;
- otherwise the page renders and receives the Tool as its `tool` prop (a `ToolDescriptor`, typed on `@martis/runtime`).

One Tool can guard several pages. The Tool needs no component of its own. For its menu entry, point it at your page: `MenuItem::tool(Findings::class)->path('/findings')` shows the entry only to the users the Tool is visible to, and opens `/findings`. Without a `Martis::mainMenu()`, the automatic menu lists every Tool at `/tools/{uriKey}`, where a Tool without a component shows only its header, so an app that guards pages this way usually declares its menu.

## Linking to a page

- **Menu:** `MenuItem::link('Findings', '/findings')`, or the Tool entry above. The link opens the page without a full reload. See [Menus](menus.md).
- **Command palette:** a `Martis::commandPalette()` entry with the same path.
- **Records:** a resource's `recordUrl()` can return `'/findings/{id}'`, so search results and relation links open the page. See [`recordUrl()`](resources.md#recordurl).
- **In React:** `Link` and `useNavigate` from `@martis/runtime` take paths relative to the Martis base path (`<Link to="/findings/0192f7c1">`).

## Existing installs

`martis:install` publishes the runtime shim once. An extension scaffolded before v2.2.0 imports `routeRegistry`, `useDynamicCrumb`, `ForbiddenPage` and `NotFoundPage` by name after refreshing it:

```bash
php artisan vendor:publish --tag=martis-extension-shims --force
```

Until then, read them from the default export: `import runtime from '@martis/runtime'`, then `runtime.routeRegistry`. See [Refreshing the extension scaffold after an upgrade](installation-guide.md#refreshing-the-extension-scaffold-after-an-upgrade).

## Coming from Nova

Nova declares a tool's pages in PHP, with `Nova::router()` and a `routes/inertia.php` file, and registers their components in JS with `Nova.inertia()`. The tool's `Authorize` middleware answers `403` to a user its `canSee()` refuses, and a tool page shows no breadcrumb ([Nova: Tools](https://nova.laravel.com/docs/v5/customization/tools)).

Martis routes on the client, so the path and the component are registered in one call. A page bound to a Tool answers as `/tools/{uriKey}` does, with `404` where the Tool is hidden (see [Differentials](differentials.md#tool-routes-run-behind-the-martis-api-middleware)). The page gets a breadcrumb, and a registered path can never take a URL Martis uses.
