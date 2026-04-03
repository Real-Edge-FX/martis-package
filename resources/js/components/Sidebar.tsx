import { useState, useEffect } from "react"
import { NavLink } from "react-router-dom"
import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api"
import { config } from "@/lib/config"
import type { NavigationGroup } from "@/types"
import { useTranslation } from "react-i18next"
import logoSrc from "@images/logo.png"

function getBrand(): string {
  return config.brand ?? "Martis"
}

function navClass({ isActive }: { isActive: boolean }) {
  return [
    "flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-all no-underline",
    isActive
      ? "bg-[var(--martis-active)]" + " " + "martis-text"
      : "martis-text-muted hover:bg-[var(--martis-hover)]",
  ].join(" ")
}

export function Sidebar() {
  const { t } = useTranslation("navigation")
  const { data: groups = [] } = useQuery<NavigationGroup[]>({
    queryKey: ["navigation"],
    queryFn: () => api.get("/api/navigation"),
    staleTime: 1000 * 60,
  })

  const [collapsed, setCollapsed] = useState(() => {
    return localStorage.getItem("martis-sidebar-collapsed") === "true"
  })

  const [expandedGroups, setExpandedGroups] = useState<Record<string, boolean>>({})

  useEffect(() => {
    localStorage.setItem("martis-sidebar-collapsed", String(collapsed))
  }, [collapsed])

  function toggleGroup(label: string) {
    setExpandedGroups((prev) => ({ ...prev, [label]: !prev[label] }))
  }

  const brand = getBrand()

  return (
    <aside
      className={[
        "martis-sidebar-bg flex h-full flex-col border-r transition-all duration-200 martis-border",
        collapsed ? "w-16 px-2 py-5" : "w-60 px-3 py-5",
      ].join(" ")}
    >
      {/* Brand */}
      <div className={["mb-8 flex items-center", collapsed ? "justify-center" : "px-3"].join(" ")}>
        {collapsed ? (
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
        {!collapsed && (
          <p className="mb-2 px-3 text-[11px] font-semibold uppercase tracking-widest martis-text-muted">
            {t("dashboards", "Dashboards")}
          </p>
        )}
        <NavLink to="/" end className={navClass} title={t("dashboard")}>
          <i className="pi pi-th-large text-sm shrink-0" />
          {!collapsed && t("dashboard")}
        </NavLink>

        {/* Resource groups with chevrons */}
        {groups.map((group, i) => {
          const groupKey = group.label ?? `group-${i}`
          const isExpanded = expandedGroups[groupKey] !== false
          return (
            <div key={groupKey} className="pt-5">
              {group.label && !collapsed && (
                <button
                  type="button"
                  onClick={() => toggleGroup(groupKey)}
                  className="mb-2 flex w-full items-center justify-between px-3 text-[11px] font-semibold uppercase tracking-widest martis-text-muted hover:opacity-80 transition-opacity cursor-pointer bg-transparent border-0"
                >
                  <span>{group.label}</span>
                  <i className={`pi ${isExpanded ? "pi-chevron-down" : "pi-chevron-right"} text-[9px]`} />
                </button>
              )}
              {collapsed && group.label && (
                <div className="mb-2 mx-auto w-6 border-t" style={{ borderColor: 'var(--martis-border)' }} />
              )}
              {(isExpanded || collapsed) && group.resources.map((r) => (
                <NavLink key={r.uriKey} to={`/resources/${r.uriKey}`} className={navClass} title={r.label}>
                  <i className="pi pi-database text-sm shrink-0" />
                  {!collapsed && r.label}
                </NavLink>
              ))}
            </div>
          )
        })}
      </nav>

      {/* Collapse toggle */}
      <div className="mt-auto pt-4 border-t" style={{ borderColor: 'var(--martis-border)' }}>
        <button
          type="button"
          onClick={() => setCollapsed((c) => !c)}
          className="flex w-full items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm martis-text-muted hover:bg-[var(--martis-hover)] transition-all no-underline border-0 bg-transparent cursor-pointer"
          title={collapsed ? t("expand_sidebar") : t("collapse_sidebar")}
        >
          <i className={`pi ${collapsed ? "pi-angle-double-right" : "pi-angle-double-left"} text-sm`} />
          {!collapsed && <span className="text-xs">{t("collapse_sidebar")}</span>}
        </button>
        {!collapsed && (
          <p className="mt-2 text-[11px] text-center" style={{ color: 'var(--martis-text-muted)', opacity: 0.5 }}>
            {t("footer")}
          </p>
        )}
      </div>
    </aside>
  )
}
