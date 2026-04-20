import { useState, useEffect } from "react"
import { NavLink } from "react-router-dom"
import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api"
import { config } from "@/lib/config"
import type { NavigationGroup } from "@/types"
import { getNavigationItems } from "@/lib/navigation"
import { useTranslation } from "react-i18next"
import logoSrcDefault from "@images/martis-icon.png"
import {
  SquaresFourIcon,
  CaretDownIcon,
} from "@phosphor-icons/react"
import { ResourceIcon } from "./ResourceIcon"

function getBrand(): string {
  return config.brand ?? "Martis"
}

function getLogoSrc(): string {
  return (config.logo ?? logoSrcDefault) as string
}

interface SidebarProps {
  mobileOpen?: boolean
  onMobileClose?: () => void
  /** When provided, the sidebar renders in collapsed mode on desktop. */
  collapsed?: boolean
}

export function Sidebar({ mobileOpen, onMobileClose, collapsed = false }: SidebarProps = {}) {
  const { t } = useTranslation("navigation")
  const { data: groups = [] } = useQuery<NavigationGroup[]>({
    queryKey: ["navigation"],
    queryFn: () => api.get("/api/navigation"),
    staleTime: 1000 * 60,
  })

  const isMobile = mobileOpen !== undefined

  const [expandedGroups, setExpandedGroups] = useState<Record<string, boolean>>({})

  function toggleGroup(label: string) {
    setExpandedGroups((prev) => ({ ...prev, [label]: !prev[label] }))
  }

  const brand = getBrand()
  const logoSrc = getLogoSrc()

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
        <span className="martis-sb-logo-text">{brand}</span>
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
          return (
            <div
              key={groupKey}
              className="martis-sb-group"
              data-open={isExpanded ? "true" : "false"}
            >
              {group.label && (isMobile || !collapsed) && (
                <button
                  type="button"
                  className="martis-sb-group-label"
                  onClick={() => toggleGroup(groupKey)}
                >
                  <span>{group.label}</span>
                  <CaretDownIcon size={10} className="caret" />
                </button>
              )}
              {(isExpanded || (!isMobile && collapsed)) &&
                getNavigationItems(group).map((item) => {
                  const iconName =
                    item.type === "resource" ? item.icon : item.icon ?? "link"
                  const showTooltip = !isMobile && collapsed ? item.label : undefined

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
                        {(isMobile || !collapsed) && (
                          <span className="martis-sb-item-label">{item.label}</span>
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
                      {(isMobile || !collapsed) && (
                        <span className="martis-sb-item-label">{item.label}</span>
                      )}
                    </NavLink>
                  )
                })}
            </div>
          )
        })}
      </div>

      {(config.version || config.docsUrl) && (
        <div className="martis-sb-footer">
          {config.version && (
            <span className="martis-sb-footer-version">v{config.version}</span>
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
