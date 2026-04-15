import { useState, useEffect } from "react"
import { NavLink } from "react-router-dom"
import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api"
import { config } from "@/lib/config"
import type { NavigationGroup } from "@/types"
import { useTranslation } from "react-i18next"
import logoSrcDefault from "@images/logo.png"
import { SquaresFourIcon, CaretDownIcon, CaretRightIcon, CaretDoubleRightIcon, CaretDoubleLeftIcon } from "@phosphor-icons/react"
import { ResourceIcon } from "./ResourceIcon"

function getBrand(): string {
  return config.brand ?? "Martis"
}

function getLogoSrc(): string {
  return (config.logo ?? logoSrcDefault) as string
}

function navClass({ isActive }: { isActive: boolean }) {
  return [
    "flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-all no-underline",
    isActive
      ? "bg-[var(--martis-active)]" + " " + "martis-text"
      : "martis-text-muted hover:bg-[var(--martis-hover)]",
  ].join(" ")
}

interface SidebarProps {
  mobileOpen?: boolean
  onMobileClose?: () => void
}

export function Sidebar({ mobileOpen, onMobileClose }: SidebarProps = {}) {
  const { t } = useTranslation("navigation")
  const { data: groups = [] } = useQuery<NavigationGroup[]>({
    queryKey: ["navigation"],
    queryFn: () => api.get("/api/navigation"),
    staleTime: 1000 * 60,
  })

  const isMobile = mobileOpen !== undefined

  const [collapsed, setCollapsed] = useState(() => {
    return localStorage.getItem("martis-sidebar-collapsed") === "true"
  })

  const [expandedGroups, setExpandedGroups] = useState<Record<string, boolean>>({})

  useEffect(() => {
    if (!isMobile) {
      localStorage.setItem("martis-sidebar-collapsed", String(collapsed))
    }
  }, [collapsed, isMobile])

  function toggleGroup(label: string) {
    setExpandedGroups((prev) => ({ ...prev, [label]: !prev[label] }))
  }

  const brand = getBrand()
  const logoSrc = getLogoSrc()

  // On mobile, hide entirely when closed
  if (isMobile && !mobileOpen) {
    return null
  }

  const sidebarContent = (
    <>
      {/* Brand */}
      <div className={["mb-8 flex items-center", (!isMobile && collapsed) ? "justify-center" : "px-3"].join(" ")}>
        {(!isMobile && collapsed) ? (
          <div className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden">
            <img
              src={logoSrc}
              alt={brand}
              className="h-9 w-auto object-contain object-left"
              style={{ maxWidth: 'none', width: 100 }}
            />
          </div>
        ) : (
          <img
            src={logoSrc}
            alt={brand}
            className="h-8 w-auto object-contain"
            style={{ maxWidth: 160 }}
          />
        )}
      </div>

      <nav className="flex-1 space-y-1 overflow-y-auto overflow-x-hidden">
        {/* Dashboard section */}
        {(isMobile || !collapsed) && (
          <p className="mb-2 px-3 text-[11px] font-semibold uppercase tracking-widest martis-text-muted">
            {t("dashboards", "Dashboards")}
          </p>
        )}
        <NavLink
          to="/"
          end
          className={navClass}
          data-pr-tooltip={t("dashboard")}
          data-pr-position="right"
          onClick={isMobile ? onMobileClose : undefined}
        >
          <SquaresFourIcon size={16} className="shrink-0" />
          {(isMobile || !collapsed) && t("dashboard")}
        </NavLink>

        {/* Resource groups with chevrons */}
        {groups.map((group, i) => {
          const groupKey = group.label ?? `group-${i}`
          const isExpanded = expandedGroups[groupKey] !== false
          return (
            <div key={groupKey} className="pt-5">
              {group.label && (isMobile || !collapsed) && (
                <button
                  type="button"
                  onClick={() => toggleGroup(groupKey)}
                  className="mb-2 flex w-full items-center justify-between px-3 text-[11px] font-semibold uppercase tracking-widest martis-text-muted hover:opacity-80 transition-opacity cursor-pointer bg-transparent border-0"
                >
                  <span>{group.label}</span>
                  {isExpanded ? <CaretDownIcon size={10} /> : <CaretRightIcon size={10} />}
                </button>
              )}
              {!isMobile && collapsed && group.label && (
                <div className="mb-2 mx-auto w-6 border-t" style={{ borderColor: 'var(--martis-border)' }} />
              )}
              {(isExpanded || (!isMobile && collapsed)) && group.resources.map((r) => (
                <NavLink
                  key={r.uriKey}
                  to={`/resources/${r.uriKey}`}
                  className={navClass}
                  data-pr-tooltip={r.label}
                  data-pr-position="right"
                  onClick={isMobile ? onMobileClose : undefined}
                >
                  <ResourceIcon iconName={r.icon} size={16} className="shrink-0" />
                  {(isMobile || !collapsed) && r.label}
                </NavLink>
              ))}
            </div>
          )
        })}
      </nav>

      {/* Collapse toggle — desktop only */}
      {!isMobile && (
        <div className="mt-auto pt-4 border-t" style={{ borderColor: 'var(--martis-border)' }}>
          <button
            type="button"
            onClick={() => setCollapsed((c) => !c)}
            className="flex w-full items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm martis-text-muted hover:bg-[var(--martis-hover)] transition-all no-underline border-0 bg-transparent cursor-pointer"
            data-pr-tooltip={collapsed ? t("expand_sidebar") : t("collapse_sidebar")}
            data-pr-position="top"
          >
            {collapsed ? <CaretDoubleRightIcon size={16} /> : <CaretDoubleLeftIcon size={16} />}
            {!collapsed && <span className="text-xs">{t("collapse_sidebar")}</span>}
          </button>
        </div>
      )}
    </>
  )

  if (isMobile) {
    return (
      <aside
        className="martis-sidebar-bg fixed left-0 top-0 z-50 flex h-full w-72 flex-col border-r px-3 py-5 martis-border"
        style={{ boxShadow: '4px 0 24px rgba(0,0,0,0.3)' }}
      >
        {sidebarContent}
      </aside>
    )
  }

  return (
    <aside
      className={[
        "martis-sidebar-bg flex h-full flex-col border-r transition-all duration-200 martis-border",
        collapsed ? "w-16 px-2 py-5" : "w-60 px-3 py-5",
      ].join(" ")}
    >
      {sidebarContent}
    </aside>
  )
}
