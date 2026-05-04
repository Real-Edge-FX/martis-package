import { Link, useMatches } from 'react-router-dom'
import { ChevronRight, Home } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useDynamicCrumbLabel } from '@/contexts/DynamicCrumbContext'

interface BreadcrumbHandle {
  crumb?: string
}

export function Breadcrumbs() {
  const { t } = useTranslation('navigation')
  const matches = useMatches()
  const dynamicLabel = useDynamicCrumbLabel()
  const crumbs = matches.filter(
    (m): m is typeof m & { handle: BreadcrumbHandle } =>
      typeof (m.handle as BreadcrumbHandle | undefined)?.crumb === 'string',
  )

  // Pages that publish a dynamic label (e.g. ToolPage with the resolved
  // tool name) override the static i18n key for the deepest crumb. Falls
  // back to the static key while the page is still resolving its title.
  const labelFor = (handle: BreadcrumbHandle, isLast: boolean): string => {
    if (isLast && dynamicLabel !== null && dynamicLabel.trim() !== '') {
      return dynamicLabel
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
