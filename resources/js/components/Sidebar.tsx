import { Fragment, useState } from "react"
import { NavLink } from "react-router-dom"
import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api"
import { config } from "@/lib/config"
import type { NavigationGroup } from "@/types"
import {
  getNavigationItems,
  getItemCount,
  formatItemCount,
  useNavigationRefreshOnNavigate,
} from "@/lib/navigation"
import { useTranslation } from "react-i18next"
import logoSrcDefault from "@images/martis-icon.png"
import {
  SquaresFourIcon,
  CaretRightIcon,
} from "@phosphor-icons/react"
import { ResourceIcon } from "./ResourceIcon"

function getBrand(): string {
  return config.brand ?? "Martis"
}

/**
 * Sidebar brand resolution. Three modes:
 *
 *   1. `config.logo` set → render the full lockup ALONE (the wordmark
 *      is assumed to live inside the asset, so we hide the brand text
 *      next to it).
 *   2. `config.icon` set → render the square icon + brand text
 *      side-by-side. Same shape as the bundled experience but with
 *      a consumer-supplied icon.
 *   3. Neither → bundled Martis cube + brand text (the default).
 */
function getBrandMark(): { src: string; mode: "logo" | "icon" } {
  if (config.logo) {
    return { src: config.logo, mode: "logo" }
  }
  if (config.icon) {
    return { src: config.icon, mode: "icon" }
  }
  return { src: logoSrcDefault as string, mode: "icon" }
}

interface SidebarProps {
  mobileOpen?: boolean
  onMobileClose?: () => void
  /** When provided, the sidebar renders in collapsed mode on desktop. */
  collapsed?: boolean
}

export function Sidebar({ mobileOpen, onMobileClose, collapsed = false }: SidebarProps = {}) {
  const { t } = useTranslation("navigation")
  const pollInterval = config.navigation?.pollInterval ?? 60_000
  const { data: groups = [] } = useQuery<NavigationGroup[]>({
    queryKey: ["navigation"],
    queryFn: () => api.get("/api/navigation"),
    staleTime: 1000 * 30,
    refetchInterval: pollInterval > 0 ? pollInterval : false,
    refetchOnWindowFocus: true,
  })
  useNavigationRefreshOnNavigate()

  const isMobile = mobileOpen !== undefined

  const [expandedGroups, setExpandedGroups] = useState<Record<string, boolean>>({})

  function toggleGroup(label: string) {
    setExpandedGroups((prev) => {
      // Groups default to open when no entry exists, so the first click
      // must explicitly write `false` instead of toggling `undefined`.
      const currentlyOpen = prev[label] !== false
      return { ...prev, [label]: !currentlyOpen }
    })
  }

  const brand = getBrand()
  const brandMark = getBrandMark()
  const logoSrc = brandMark.src

  const mobileAttr = isMobile ? (mobileOpen ? "open" : "true") : undefined

  return (
    <aside
      className="martis-sb"
      data-collapsed={!isMobile && collapsed ? "true" : "false"}
      data-mobile={mobileAttr}
      style={!isMobile && !collapsed ? { width: "var(--sidebar-width, 240px)" } : undefined}
      aria-label={t("section_resources", "Resources")}
    >
      <div className="martis-sb-logo">
        <div className="martis-sb-logo-mark">
          <img src={logoSrc} alt={brand} />
        </div>
        {brandMark.mode === "icon" && (
          <span className="martis-sb-logo-text">{brand}</span>
        )}
      </div>

      <div className="martis-sb-scroll">
        <div className="martis-sb-group" data-open="true">
          {(isMobile || !collapsed) && (
            <div className="martis-sb-group-label">
              <span>{t("dashboards", "Dashboards")}</span>
            </div>
          )}
          <NavLink
            to="/"
            end
            className={({ isActive }) =>
              "martis-sb-item" + (isActive ? " active" : "")
            }
            data-pr-tooltip={!isMobile && collapsed ? t("dashboard") : undefined}
            data-pr-position="right"
            onClick={isMobile ? onMobileClose : undefined}
          >
            <SquaresFourIcon size={16} className="shrink-0" />
            {(isMobile || !collapsed) && (
              <span className="martis-sb-item-label">{t("dashboard")}</span>
            )}
          </NavLink>
        </div>

        {groups.map((group, i) => {
          const groupKey = group.label ?? `group-${i}`
          const isExpanded = expandedGroups[groupKey] !== false
          const currentSection = group.section ?? null
          const previousSection = i > 0 ? groups[i - 1].section ?? null : null
          const showSection =
            currentSection !== null &&
            currentSection !== previousSection &&
            (isMobile || !collapsed)
          return (
            <Fragment key={groupKey}>
              {showSection && (
                <div className="martis-sb-section-heading" aria-hidden="true">
                  {currentSection}
                </div>
              )}
            <div
              className="martis-sb-group"
              data-open={isExpanded ? "true" : "false"}
            >
              {group.label && (isMobile || !collapsed) && (
                <button
                  type="button"
                  className="martis-sb-group-label"
                  onClick={() => toggleGroup(groupKey)}
                >
                  {group.icon && (
                    <ResourceIcon iconName={group.icon} size={14} className="shrink-0" />
                  )}
                  <span>{group.label}</span>
                  <CaretRightIcon size={10} className="caret" />
                </button>
              )}
              {(isExpanded || (!isMobile && collapsed)) &&
                getNavigationItems(group).map((item) => {
                  const iconName =
                    item.type === "resource" ? item.icon : item.icon ?? "link"
                  const showTooltip = !isMobile && collapsed ? item.label : undefined
                  const count = getItemCount(item)
                  const showLabel = isMobile || !collapsed
                  const showCount = showLabel && count !== null

                  if (item.external) {
                    return (
                      <a
                        key={`${groupKey}-${item.label}-${item.url}`}
                        href={item.url}
                        target="_blank"
                        rel="noreferrer"
                        className="martis-sb-item"
                        data-pr-tooltip={showTooltip}
                        data-pr-position="right"
                        onClick={isMobile ? onMobileClose : undefined}
                      >
                        <ResourceIcon
                          iconName={iconName ?? null}
                          size={16}
                          className="shrink-0"
                        />
                        {showLabel && (
                          <span className="martis-sb-item-label">{item.label}</span>
                        )}
                        {showCount && (
                          <span className="martis-sb-item-badge">
                            {formatItemCount(count!)}
                          </span>
                        )}
                      </a>
                    )
                  }

                  return (
                    <NavLink
                      key={
                        item.type === "resource"
                          ? item.uriKey
                          : `${groupKey}-${item.label}-${item.url}`
                      }
                      to={item.url}
                      className={({ isActive }) =>
                        "martis-sb-item" + (isActive ? " active" : "")
                      }
                      data-pr-tooltip={showTooltip}
                      data-pr-position="right"
                      onClick={isMobile ? onMobileClose : undefined}
                    >
                      <ResourceIcon
                        iconName={iconName ?? null}
                        size={16}
                        className="shrink-0"
                      />
                      {showLabel && (
                        <span className="martis-sb-item-label">{item.label}</span>
                      )}
                      {showCount && (
                        <span className="martis-sb-item-badge">
                          {formatItemCount(count!)}
                        </span>
                      )}
                    </NavLink>
                  )
                })}
            </div>
            </Fragment>
          )
        })}
      </div>

      {(config.version || config.docsUrl) && (
        <div className="martis-sb-footer">
          {config.version && (
            <span className="martis-sb-footer-version">
              {/^\d/.test(config.version) ? `v${config.version}` : config.version}
            </span>
          )}
          {config.docsUrl && (
            <a
              href={config.docsUrl}
              target="_blank"
              rel="noreferrer"
              className="martis-sb-footer-link"
            >
              {t("docs", "Docs")} ↗
            </a>
          )}
        </div>
      )}
    </aside>
  )
}
