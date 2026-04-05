import { Link, useMatches } from "react-router-dom"
import { ChevronRight, Home } from "lucide-react"
import { useTranslation } from "react-i18next"

interface BreadcrumbHandle {
  crumb?: string
}

export function Breadcrumbs() {
  const { t } = useTranslation("navigation")
  const matches = useMatches()
  const crumbs = matches.filter(
    (m): m is typeof m & { handle: BreadcrumbHandle } =>
      typeof (m.handle as BreadcrumbHandle | undefined)?.crumb === "string",
  )

  return (
    <nav aria-label="Breadcrumbs" className="flex items-center gap-1 text-sm martis-text-muted">
      <Link to="/" className="flex items-center gap-1 hover:opacity-80">
        <Home size={14} />
      </Link>
      {crumbs.map((m, i) => (
        <span key={m.id} className="flex items-center gap-1">
          <ChevronRight size={12} />
          {i === crumbs.length - 1 ? (
            <span className="font-medium martis-text">
              {t((m.handle as BreadcrumbHandle).crumb!)}
            </span>
          ) : (
            <Link to={m.pathname} className="hover:opacity-80">
              {t((m.handle as BreadcrumbHandle).crumb!)}
            </Link>
          )}
        </span>
      ))}
    </nav>
  )
}
