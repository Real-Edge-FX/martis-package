import { Link, useMatches } from 'react-router'
import { ChevronRight, Home } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useDynamicCrumbLabel } from '@/contexts/DynamicCrumbContext'

interface BreadcrumbHandle {
  /** An i18n key of the `navigation` namespace (the package's own routes). */
  crumb?: string
  /**
   * A label shown as given, never through `t()`: the crumb of a route an
   * application registered on `routeRegistry` (v2.2.0+). With i18next's
   * default separators, `t('Findings: open')` would look up the namespace
   * `Findings`.
   */
  crumbLabel?: string
}

function isCrumb(handle: unknown): handle is BreadcrumbHandle {
  const candidate = handle as BreadcrumbHandle | undefined
  return typeof candidate?.crumb === 'string' || typeof candidate?.crumbLabel === 'string'
}

export function Breadcrumbs() {
  const { t } = useTranslation('navigation')
  const matches = useMatches()
  const dynamicLabel = useDynamicCrumbLabel()
  const crumbs = matches.filter((m) => isCrumb(m.handle))

  // Pages that publish a dynamic label (e.g. ToolPage with the resolved
  // tool name) override the static label for the deepest crumb. Falls
  // back to the static label while the page is still resolving its title.
  const labelFor = (handle: BreadcrumbHandle, isLast: boolean): string => {
    if (isLast && dynamicLabel !== null && dynamicLabel.trim() !== '') {
      return dynamicLabel
    }
    if (typeof handle.crumbLabel === 'string') {
      return handle.crumbLabel
    }
    return t(handle.crumb!)
  }

  return (
    <nav aria-label="Breadcrumbs" className="flex items-center gap-1 text-sm martis-text-muted">
      <Link to="/" className="flex items-center gap-1 hover:opacity-80">
        <Home size={14} />
      </Link>
      {crumbs.map((m, i) => {
        const isLast = i === crumbs.length - 1
        const handle = m.handle as BreadcrumbHandle
        return (
          <span key={m.id} className="flex items-center gap-1">
            <ChevronRight size={12} />
            {isLast ? (
              <span className="font-medium martis-text">{labelFor(handle, true)}</span>
            ) : (
              <Link to={m.pathname} className="hover:opacity-80">
                {labelFor(handle, false)}
              </Link>
            )}
          </span>
        )
      })}
    </nav>
  )
}
