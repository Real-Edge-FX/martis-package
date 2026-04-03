import { useRef, useState, useEffect, useCallback } from "react"
import { useAuth } from "@/contexts/AuthContext"
import { useTheme } from "@/contexts/ThemeContext"
import { config } from "@/lib/config"
import { Breadcrumbs } from "@/components/Breadcrumbs"
import { GlobalSearch } from "@/components/GlobalSearch"
import { Button } from "primereact/button"
import { Menu } from "primereact/menu"
import type { MenuItem } from "primereact/menuitem"
import { useTranslation } from "react-i18next"

export function Topbar() {
  const { user, logout } = useAuth()
  const { theme, toggle } = useTheme()
  const { t } = useTranslation("navigation")
  const menuRef = useRef<Menu>(null)
  const [searchOpen, setSearchOpen] = useState(false)

  const showThemeToggle = config.userMenu?.showThemeToggle !== false
  const showNotifications = config.userMenu?.showNotifications !== false
  const searchEnabled = config.search?.enabled !== false

  // Keyboard shortcut: "/" to open search
  const handleKeyDown = useCallback((e: KeyboardEvent) => {
    if (!searchEnabled) return
    if (e.key === "/" && !e.ctrlKey && !e.metaKey) {
      const tag = (e.target as HTMLElement)?.tagName
      if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT") return
      e.preventDefault()
      setSearchOpen(true)
    }
  }, [searchEnabled])

  useEffect(() => {
    document.addEventListener("keydown", handleKeyDown)
    return () => document.removeEventListener("keydown", handleKeyDown)
  }, [handleKeyDown])

  const userMenuItems: MenuItem[] = [
    {
      label: user?.name ?? user?.email ?? "",
      className: "font-bold pointer-events-none opacity-80",
      disabled: true,
    },
    {
      label: user?.email ?? "",
      className: "text-xs pointer-events-none opacity-60",
      disabled: true,
    },
    { separator: true },
    ...(showThemeToggle
      ? [
          {
            label: theme === "dark" ? t("light_mode") : t("dark_mode"),
            icon: `pi pi-${theme === "dark" ? "sun" : "moon"}`,
            command: () => toggle(),
          },
          { separator: true } as MenuItem,
        ]
      : []),
    ...(config.userMenu?.customItems?.map((item) =>
      item.separator
        ? ({ separator: true } as MenuItem)
        : ({
            label: item.label,
            icon: item.icon,
            url: item.url,
          } as MenuItem),
    ) ?? []),
    {
      label: t("logout"),
      icon: "pi pi-sign-out",
      className: "text-red-500",
      command: () => void logout(),
    },
  ]

  return (
    <header
      className="martis-topbar-bg flex h-14 items-center justify-between border-b martis-border px-5"
    >
      <Breadcrumbs />

      {/* Search bar trigger — Nova style */}
      {searchEnabled && (
        <button
          type="button"
          onClick={() => setSearchOpen(true)}
          className="flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm transition-colors cursor-pointer border-0"
          style={{
            backgroundColor: 'var(--martis-search-bg)',
            border: '1px solid var(--martis-search-border)',
            color: 'var(--martis-text-muted)',
            minWidth: 220,
          }}
        >
          <i className="pi pi-search text-xs" />
          <span>{config.search?.placeholder ?? t("search_placeholder", "Press / to search")}</span>
          <kbd
            className="ml-auto rounded px-1.5 py-0.5 text-[10px] font-mono"
            style={{
              backgroundColor: 'var(--martis-hover)',
              border: '1px solid var(--martis-search-border)',
              color: 'var(--martis-text-muted)',
            }}
          >
            /
          </kbd>
        </button>
      )}

      <div className="flex items-center gap-3">
        {showNotifications && (
          <Button
            icon="pi pi-bell"
            aria-label="Notifications"
            rounded
            text
            severity="secondary"
            size="small"
            className="martis-text-muted"
          />
        )}

        {/* User avatar + dropdown menu */}
        <Menu model={userMenuItems} popup ref={menuRef} className="min-w-[200px]" />
        <div
          className="flex items-center gap-2 rounded-lg px-3 py-1.5 cursor-pointer transition-colors"
          style={{ backgroundColor: 'transparent' }}
          onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = 'var(--martis-hover)')}
          onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = 'transparent')}
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
          <span className="text-sm font-medium martis-text">
            {user?.name ?? user?.email}
          </span>
          <i className="pi pi-chevron-down text-xs martis-text-muted" />
        </div>
      </div>

      {/* Global search modal */}
      {searchOpen && <GlobalSearch onClose={() => setSearchOpen(false)} />}
    </header>
  )
}
