import type { ComponentType } from 'react'
import { useLocation } from 'react-router'
import { useTranslation } from 'react-i18next'
import { WrenchIcon } from '@phosphor-icons/react'
import { componentRegistry } from '@/lib/componentRegistry'
import { useToolDescriptor } from '@/hooks/useToolDescriptor'
import { MartisLoader } from '@/components/Loader'
import { ToolHiddenState, ToolLockedState } from '@/components/tools/ToolStates'
import type { RegisteredRoute, RegisteredRoutePageProps } from '@/lib/routeRegistry'

/**
 * The page of a route an application registered on `routeRegistry`
 * (v2.2.0+, docs/custom-pages.md). `createAppRouter()` mounts one per
 * registered route inside the shell route.
 *
 * Keyed on the pathname: moving from `/findings/1` to `/findings/2`
 * remounts the page, so no state leaks from one record to the next (as
 * `ToolPage` remounts between tools, and as an Inertia visit resets page
 * state by default); a change of the query string alone keeps it.
 *
 * Sets neither the tab title nor the dynamic crumb: effects run child
 * first, so a call here would overwrite the one the page makes
 * (`usePageTitle`, `useDynamicCrumb`).
 */
export function RegisteredRoutePage({ route }: { route: RegisteredRoute }) {
  const { pathname } = useLocation()
  return <RegisteredRouteContent key={pathname} route={route} />
}

function RegisteredRouteContent({ route }: { route: RegisteredRoute }) {
  if (route.tool !== null) return <ToolGuardedPage uriKey={route.tool} component={route.component} />
  return <PageComponent component={route.component} props={{}} />
}

/**
 * A page bound to a Tool resolves the Tool exactly as `/tools/{uriKey}`
 * does: a user the Tool is hidden from gets its not-found state, a locked
 * user its lock page, before the page component renders.
 */
function ToolGuardedPage({ uriKey, component }: { uriKey: string; component: RegisteredRoute['component'] }) {
  const resolution = useToolDescriptor(uriKey)

  if (resolution.status === 'hidden') return <ToolHiddenState />
  if (resolution.status === 'locked') return <ToolLockedState payload={resolution.payload} />
  if (resolution.status === 'loading') return <MartisLoader />

  return <PageComponent component={component} props={{ tool: resolution.descriptor }} />
}

function PageComponent({ component, props }: { component: RegisteredRoute['component']; props: RegisteredRoutePageProps }) {
  const { t } = useTranslation('messages')
  const Component =
    typeof component === 'string'
      ? (componentRegistry.resolve(component) as ComponentType<RegisteredRoutePageProps> | undefined)
      : component

  if (Component === undefined) {
    // Developer ergonomics: the key is resolved when the page renders, so
    // a missing registration shows here rather than as a blank page.
    return (
      <div className="martis-tool-missing-component" role="alert">
        <WrenchIcon size={36} weight="duotone" />
        <p>
          {t('registered_route_component_missing', {
            defaultValue:
              "No React component is registered for the key \"{{key}}\". Register it with componentRegistry.register('{{key}}', MyPage), or pass the component itself to routeRegistry.register().",
            key: component as string,
          })}
        </p>
      </div>
    )
  }

  return <Component {...props} />
}
