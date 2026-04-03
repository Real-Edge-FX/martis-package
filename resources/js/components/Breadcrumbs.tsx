import { Link, useMatches } from 'react-router-dom'
import { ChevronRight, Home } from 'lucide-react'

interface BreadcrumbHandle {
  crumb?: string
}

export function Breadcrumbs() {
  const matches = useMatches()
  const crumbs = matches.filter(
    (m): m is typeof m & { handle: BreadcrumbHandle } =>
      typeof (m.handle as BreadcrumbHandle | undefined)?.crumb === 'string',
  )

  return (
    <nav aria-label="Breadcrumbs" className="flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400">
      <Link to="/" className="flex items-center gap-1 hover:text-gray-900 dark:hover:text-gray-100">
        <Home size={14} />
      </Link>
      {crumbs.map((m, i) => (
        <span key={m.id} className="flex items-center gap-1">
          <ChevronRight size={12} />
          {i === crumbs.length - 1 ? (
            <span className="font-medium text-gray-900 dark:text-gray-100">
              {(m.handle as BreadcrumbHandle).crumb}
            </span>
          ) : (
            <Link to={m.pathname} className="hover:text-gray-900 dark:hover:text-gray-100">
              {(m.handle as BreadcrumbHandle).crumb}
            </Link>
          )}
        </span>
      ))}
    </nav>
  )
}

