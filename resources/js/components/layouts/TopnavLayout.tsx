import { Outlet } from "react-router-dom"
import { NavLink } from "react-router-dom"
import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api"
import { config } from "@/lib/config"
import { useAuth } from "@/contexts/AuthContext"
import { useTheme } from "@/contexts/ThemeContext"
import { GlobalSearch } from "@/components/GlobalSearch"
import { Footer } from "@/components/Footer"
import { Menu } from "primereact/menu"
import type { MenuItem } from "primereact/menuitem"
import type { NavigationGroup } from "@/types"
import { useTranslation } from "react-i18next"
import { useRef, useState, useEffect, useCallback } from "react"
import logoSrc from "@images/logo.png"
import {
  SquaresFour,
  MagnifyingGlass,
  Bell,
  CaretDown,
  Sun,
  Moon,
  SignOut,
} from "@phosphor-icons/react"

export function TopnavLayout() {
  const { user, logout } = useAuth()
  const { theme, toggle } = useTheme()
  const { t } = useTranslation("navigation")
  const menuRef = useRef<Menu>(null)
  const [searchOpen, setSearchOpen] = useState(false)

  const { data: groups = [] } = useQuery<NavigationGroup[]>({
    queryKey: ["navigation"],
    queryFn: () => api.get("/api/navigation"),
    staleTime: 1000 * 60,
  })

  const brand = config.brand ?? "Martis"
  const showThemeToggle = config.userMenu?.showThemeToggle !== false
  const showNotifications = config.userMenu?.showNotifications !== false
  const searchEnabled = config.search?.enabled !== false

  const handleKeyDown = useCallback(
    (e: KeyboardEvent) => {
      if (!searchEnabled) return
      if (e.key === "/" && !e.ctrlKey && !e.metaKey) {
        const tag = (e.target as HTMLElement)?.tagName
        if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT") return
        e.preventDefault()
        setSearchOpen(true)
      }
    },
    [searchEnabled],
  )

  useEffect(() => {
    document.addEventListener("keydown", handleKeyDown)
    return () => document.removeEventListener("keydown", handleKeyDown)
  }, [handleKeyDown])

  function navClass({ isActive }: { isActive: boolean }) {
    return [
      "flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-all no-underline whitespace-nowrap",
      isActive
        ? "bg-[var(--martis-active)] martis-text"
        : "martis-text-muted hover:bg-[var(--martis-hover)]",
    ].join(" ")
  }

  const userMenuItems: MenuItem[] = [
    {
      template: () => (
        <div className="px-3 py-2 pointer-events-none">
          <div className="font-semibold text-sm" style={{ color: "var(--martis-text)" }}>
            {user?.name ?? user?.email ?? ""}
          </div>
          <div className="text-xs mt-0.5" style={{ color: "var(--martis-text-muted)" }}>
            {user?.email ?? ""}
          </div>
        </div>
      ),
    },
    { separator: true },
    ...(showThemeToggle
      ? [
          {
            template: (
              _item: MenuItem,
              options: { className: string; onClick: (e: React.SyntheticEvent) => void },
            ) => (
              <a
                className={options.className}
                onClick={(e) => {
                  toggle()
                  options.onClick(e)
                }}
                role="menuitem"
              >
                {theme === "dark" ? (
                  <Sun size={16} className="p-menuitem-icon" />
                ) : (
                  <Moon size={16} className="p-menuitem-icon" />
                )}
                <span className="p-menuitem-text">
                  {theme === "dark" ? t("light_mode") : t("dark_mode")}
                </span>
              </a>
            ),
          },
          { separator: true } as MenuItem,
        ]
      : []),
    {
      template: (
        _item: MenuItem,
        options: { className: string; onClick: (e: React.SyntheticEvent) => void },
      ) => (
        <a
          className={`${options.className} text-red-500`}
          onClick={(e) => {
            void logout()
            options.onClick(e)
          }}
          role="menuitem"
        >
          <SignOut size={16} className="p-menuitem-icon" />
          <span className="p-menuitem-text">{t("logout")}</span>
        </a>
      ),
    },
  ]

  return (
    <div className="martis-bg flex h-screen flex-col overflow-hidden">
      <header className="martis-topbar-bg border-b martis-border">
        <div className="flex h-14 items-center justify-between px-5">
          <div className="flex items-center gap-6">
            <img src={logoSrc} alt={brand} className="h-8 w-auto object-contain" style={{ maxWidth: 120 }} />
            <nav className="flex items-center gap-1 overflow-x-auto">
              <NavLink to="/" end className={navClass}>
                <SquaresFour size={16} className="shrink-0" />
                {t("dashboard")}
              </NavLink>
              {groups.flatMap((group) =>
                group.resources.map((r) => (
                  <NavLink key={r.uriKey} to={`/resources/${r.uriKey}`} className={navClass}>
                    {r.label}
                  </NavLink>
                )),
              )}
            </nav>
          </div>

          <div className="flex items-center gap-3">
            {searchEnabled && (
              <button
                type="button"
                onClick={() => setSearchOpen(true)}
                className="flex h-8 w-8 items-center justify-center rounded-lg transition-colors cursor-pointer border-0"
                style={{ backgroundColor: "transparent", color: "var(--martis-text-muted)" }}
                onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "var(--martis-hover)")}
                onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
                aria-label="Search"
              >
                <MagnifyingGlass size={16} />
              </button>
            )}
            {showNotifications && (
              <button
                type="button"
                className="flex h-8 w-8 items-center justify-center rounded-lg transition-colors border-0 cursor-pointer"
                style={{ color: "var(--martis-text-muted)", backgroundColor: "transparent" }}
                onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "var(--martis-hover)")}
                onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
                aria-label="Notifications"
              >
                <Bell size={16} />
              </button>
            )}
            <Menu model={userMenuItems} popup ref={menuRef} className="min-w-[220px]" />
            <div
              className="flex items-center gap-2 rounded-lg px-3 py-1.5 cursor-pointer transition-colors"
              style={{ backgroundColor: "transparent" }}
              onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "var(--martis-hover)")}
              onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
              onClick={(e) => menuRef.current?.toggle(e)}
              role="button"
              tabIndex={0}
              onKeyDown={(e) => {
                if (e.key === "Enter" || e.key === " ")
                  menuRef.current?.toggle(e as unknown as React.SyntheticEvent)
              }}
            >
              <div className="flex h-7 w-7 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">
                {(user?.name ?? user?.email ?? "?")[0].toUpperCase()}
              </div>
              <span className="hidden sm:inline text-sm font-medium martis-text">
                {user?.name ?? user?.email}
              </span>
              <CaretDown size={12} className="martis-text-muted" />
            </div>
          </div>
        </div>
      </header>

      <main className="flex-1 overflow-auto p-6">
        <Outlet />
      </main>

      <Footer />

      {searchOpen && <GlobalSearch onClose={() => setSearchOpen(false)} />}
    </div>
  )
}
