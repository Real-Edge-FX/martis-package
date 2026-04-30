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
 * Sidebar brand resolution (v1.7.0).
 *
 * Three modes, with per-theme variants:
 *
 *   1. `config.logo` set → render the full lockup ALONE (no separate
 *      brand text). When `config.logoDark` is also set, both images
 *      ship in the DOM and CSS hides one based on `<html data-theme>`.
 *   2. `config.icon` set → render the square icon + brand text
 *      side-by-side. `config.iconDark` enables the same theme switch.
 *   3. Neither → bundled Martis cube + brand text.
 *
 * Collapse override (v1.7.0): when the sidebar is collapsed AND the
 * consumer shipped an icon, the icon wins regardless of `logo` —
 * a horizontal lockup gets crammed into the 64px rail otherwise.
 */
function getBrandMark(collapsed: boolean): {
  light: string
  dark: string
  mode: "logo" | "icon"
} {
  const logoLight = config.logo ?? config.logoDark ?? null
  const logoDark = config.logoDark ?? config.logo ?? null
  const iconLight = config.icon ?? config.iconDark ?? null
  const iconDark = config.iconDark ?? config.icon ?? null

  // Collapsed → prefer the icon when the consumer shipped one. The
  // 64px rail cannot host a horizontal lockup without distortion.
  if (collapsed && iconLight) {
    return { light: iconLight, dark: iconDark ?? iconLight, mode: "icon" }
  }

  if (logoLight) {
    return { light: logoLight, dark: logoDark ?? logoLight, mode: "logo" }
  }
  if (iconLight) {
    return { light: iconLight, dark: iconDark ?? iconLight, mode: "icon" }
  }
  const bundled = logoSrcDefault as string
  return { light: bundled, dark: bundled, mode: "icon" }
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
  const brandMark = getBrandMark(!isMobile && collapsed)
  const sameVariant = brandMark.light === brandMark.dark

  const mobileAttr = isMobile ? (mobileOpen ? "open" : "true") : undefined

  return (
    <aside
      className="martis-sb"
      data-collapsed={!isMobile && collapsed ? "true" : "false"}
      data-mobile={mobileAttr}
      style={!isMobile && !collapsed ? { width: "var(--sidebar-width, 240px)" } : undefined}
      aria-label={t("section_resources", "Resources")}
    >
      <div className="martis-sb-logo" data-mode={brandMark.mode}>
        <div className="martis-sb-logo-mark">
          {sameVariant ? (
            <img src={brandMark.light} alt={brand} />
          ) : (
            <>
              <img
                src={brandMark.light}
                alt={brand}
                className="martis-brand-img--light"
              />
              <img
                src={brandMark.dark}
                alt={brand}
                className="martis-brand-img--dark"
                aria-hidden="true"
              />
            </>
          )}
        </div>
        {brandMark.mode === "icon" && (!isMobile && collapsed ? null : (
          <span className="martis-sb-logo-text">{brand}</span>
        ))}
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
