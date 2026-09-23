import { Outlet, useParams } from 'react-router-dom'
import { layoutRegistry, type LayoutProps } from '@/lib/layoutRegistry'

function DefaultResourceLayout({ children }: LayoutProps) {
  return <>{children}</>
}

/**
 * Route element above every resource page (index, lens, create, detail,
 * update). Renders the page inside the layout a consumer registered for
 * the resource in the URL (`layoutRegistry.register('users', UsersLayout)`,
 * reachable from an extension through `@martis/runtime`), within the shell,
 * so the sidebar and topbar stay. A resource with no registered layout
 * renders its page as is.
 *
 * The registry is read on every render, like the component registry, so a
 * layout registered by an extension bundle applies from the first page.
 */
export function ResourceLayout() {
  const { resource } = useParams()
  const Layout = layoutRegistry.resolve(resource, DefaultResourceLayout)

  return (
    <Layout>
      <Outlet />
    </Layout>
  )
}
