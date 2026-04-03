import { useState, useEffect } from "react"
import { NavLink } from "react-router-dom"
import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api"
import type { NavigationGroup } from "@/types"
import { useTranslation } from "react-i18next"

function getBrand(): string {
  return window.MartisConfig?.brand ?? "Martis"
}

function navClass({ isActive }: { isActive: boolean }) {
  return [
    "flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-all no-underline",
    isActive
      ? "bg-white/10 text-white"
      : "text-slate-400 hover:bg-white/5 hover:text-slate-200",
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

  useEffect(() => {
    localStorage.setItem("martis-sidebar-collapsed", String(collapsed))
  }, [collapsed])

  return (
    <aside
      className={[
        "flex h-full flex-col border-r border-slate-700/50 transition-all duration-200",
        collapsed ? "w-16 px-2 py-5" : "w-60 px-3 py-5",
      ].join(" ")}
      style={{ backgroundColor: "#111827" }}
    >
      {/* Brand */}
      <div className={["mb-8 flex items-center gap-3", collapsed ? "justify-center" : "px-3"].join(" ")}>
        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-indigo-500/20">
          <i className="pi pi-shield text-lg text-indigo-400" />
        </div>
        {!collapsed && <span className="text-lg font-bold text-white">{getBrand()}</span>}
      </div>

      <nav className="flex-1 space-y-1 overflow-y-auto overflow-x-hidden">
        {/* Dashboard link */}
        <NavLink to="/" end className={navClass} title={t("dashboard")}>
          <i className="pi pi-th-large text-sm shrink-0" />
          {!collapsed && t("dashboard")}
        </NavLink>

        {groups.map((group, i) => (
          <div key={group.label ?? i} className="pt-5">
            {group.label && !collapsed && (
              <p className="mb-2 px-3 text-[11px] font-semibold uppercase tracking-widest text-slate-500">
                {group.label}
              </p>
            )}
            {collapsed && group.label && (
              <div className="mb-2 mx-auto w-6 border-t border-slate-700" />
            )}
            {group.resources.map((r) => (
              <NavLink key={r.uriKey} to={`/resources/${r.uriKey}`} className={navClass} title={r.label}>
                <i className="pi pi-database text-sm shrink-0" />
                {!collapsed && r.label}
              </NavLink>
            ))}
          </div>
        ))}
      </nav>

      {/* Collapse toggle */}
      <div className="mt-auto pt-4 border-t border-slate-700/50">
        <button
          type="button"
          onClick={() => setCollapsed((c) => !c)}
          className="flex w-full items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-400 hover:bg-white/5 hover:text-slate-200 transition-all no-underline border-0 bg-transparent cursor-pointer"
          title={collapsed ? t("expand_sidebar") : t("collapse_sidebar")}
        >
          <i className={`pi ${collapsed ? "pi-angle-double-right" : "pi-angle-double-left"} text-sm`} />
          {!collapsed && <span className="text-xs">{t("collapse_sidebar")}</span>}
        </button>
        {!collapsed && (
          <p className="mt-2 text-[11px] text-slate-600 text-center">{t("footer")}</p>
        )}
      </div>
    </aside>
  )
}
