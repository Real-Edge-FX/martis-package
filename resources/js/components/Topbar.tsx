import { useRef, useState, useEffect, useCallback } from "react"
import { useNavigate } from "react-router-dom"
import { useAuth } from "@/contexts/AuthContext"
import { useTheme } from "@/contexts/ThemeContext"
import { config } from "@/lib/config"
import { Breadcrumbs } from "@/components/Breadcrumbs"
import { GlobalSearch } from "@/components/GlobalSearch"
import { Menu } from "primereact/menu"
import type { MenuItem } from "primereact/menuitem"
import { useTranslation } from "react-i18next"
import { MagnifyingGlassIcon, CaretDownIcon, SunIcon, MoonIcon, SignOutIcon, UserCircleIcon, ListIcon } from "@phosphor-icons/react"
import { useIsMobile } from "@/hooks/useIsMobile"

interface TopbarProps {
  onToggleSidebar?: () => void
}

export function Topbar({ onToggleSidebar }: TopbarProps = {}) {
  const { user, logout } = useAuth()
  const { theme, toggle } = useTheme()
  const { t } = useTranslation("navigation")
  const navigate = useNavigate()
  const menuRef = useRef<Menu>(null)
  const [searchOpen, setSearchOpen] = useState(false)
  const isMobile = useIsMobile()

  const showThemeToggle = config.userMenu?.showThemeToggle !== false && config.theme?.allowToggle !== false
  const showProfile = config.profile?.enabled !== false && config.userMenu?.showProfile !== false

  // Search mode resolution
  const desktopMode = config.search?.enabled === false ? "disabled" : (config.search?.mode ?? "bar")
  const mobileMode = config.search?.mobileMode ?? "icon"
  const searchMode = isMobile ? mobileMode : desktopMode

  // Keyboard shortcut: "/" to open search
  const handleKeyDown = useCallback(
    (e: KeyboardEvent) => {
      if (searchMode === "disabled") return
      if (e.key === "/" && !e.ctrlKey && !e.metaKey) {
        const tag = (e.target as HTMLElement)?.tagName
        if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT") return
        e.preventDefault()
        setSearchOpen(true)
      }
    },
    [searchMode],
  )

  useEffect(() => {
    document.addEventListener("keydown", handleKeyDown)
    return () => document.removeEventListener("keydown", handleKeyDown)
  }, [handleKeyDown])

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
                  <SunIcon size={16} className="p-menuitem-icon" />
                ) : (
                  <MoonIcon size={16} className="p-menuitem-icon" />
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
    ...(config.userMenu?.customItems?.map((item) =>
      item.separator
        ? ({ separator: true } as MenuItem)
        : ({
            label: item.label,
            icon: item.icon,
            url: item.url,
          } as MenuItem),
    ) ?? []),
    ...(showProfile
      ? [
          {
            template: (
              _item: MenuItem,
              options: { className: string; onClick: (e: React.SyntheticEvent) => void },
            ) => (
              <a
                className={options.className}
                onClick={(e) => {
                  navigate('/profile')
                  options.onClick(e)
                }}
                role="menuitem"
              >
                <UserCircleIcon size={16} className="p-menuitem-icon" />
                <span className="p-menuitem-text">
                  {config.profile?.menu?.label ?? t("profile", "Profile")}
                </span>
              </a>
            ),
          } as MenuItem,
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
          <SignOutIcon size={16} className="p-menuitem-icon" />
          <span className="p-menuitem-text">{t("logout")}</span>
        </a>
      ),
    },
  ]

  return (
    <header className="martis-topbar-bg flex h-14 items-center justify-between border-b martis-border px-5">
      <div className="flex items-center gap-3">
        {/* Hamburger — only on mobile when sidebar is managed externally */}
        {onToggleSidebar && (
          <button
            type="button"
            onClick={onToggleSidebar}
            className="flex h-8 w-8 items-center justify-center rounded-lg transition-colors cursor-pointer border-0"
            style={{ color: "var(--martis-text-muted)", backgroundColor: "transparent" }}
            onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "var(--martis-hover)")}
            onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
            aria-label={t("open_sidebar", "Menu")}
          >
            <ListIcon size={20} />
          </button>
        )}
        <Breadcrumbs />
      </div>

      {/* Search — full bar mode */}
      {searchMode === "bar" && (
        <button
          type="button"
          onClick={() => setSearchOpen(true)}
          className="flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm transition-colors cursor-pointer border-0"
          style={{
            backgroundColor: "var(--martis-search-bg)",
            border: "1px solid var(--martis-search-border)",
            color: "var(--martis-text-muted)",
            minWidth: 220,
          }}
        >
          <MagnifyingGlassIcon size={12} />
          <span>
            {config.search?.placeholder ?? t("search_placeholder", "Press / to search")}
          </span>
          <kbd
            className="ml-auto rounded px-1.5 py-0.5 text-[10px] font-mono"
            style={{
              backgroundColor: "var(--martis-hover)",
              border: "1px solid var(--martis-search-border)",
              color: "var(--martis-text-muted)",
            }}
          >
            /
          </kbd>
        </button>
      )}

      {/* Search — icon-only mode */}
      {searchMode === "icon" && (
        <button
          type="button"
          onClick={() => setSearchOpen(true)}
          className="flex h-8 w-8 items-center justify-center rounded-lg transition-colors cursor-pointer border-0"
          style={{
            backgroundColor: "transparent",
            color: "var(--martis-text-muted)",
          }}
          onMouseEnter={(e) =>
            (e.currentTarget.style.backgroundColor = "var(--martis-hover)")
          }
          onMouseLeave={(e) =>
            (e.currentTarget.style.backgroundColor = "transparent")
          }
          aria-label="Search"
        >
          <MagnifyingGlassIcon size={16} />
        </button>
      )}

      <div className="flex items-center gap-3">
        {/* User avatar + dropdown menu */}
        <Menu model={userMenuItems} popup ref={menuRef} className="min-w-[220px]" />
        <div
          className="flex items-center gap-2 rounded-lg px-3 py-1.5 cursor-pointer transition-colors"
          style={{ backgroundColor: "transparent" }}
          onMouseEnter={(e) =>
            (e.currentTarget.style.backgroundColor = "var(--martis-hover)")
          }
          onMouseLeave={(e) =>
            (e.currentTarget.style.backgroundColor = "transparent")
          }
          onClick={(e) => menuRef.current?.toggle(e)}
          role="button"
          tabIndex={0}
          onKeyDown={(e) => {
            if (e.key === "Enter" || e.key === " ")
              menuRef.current?.toggle(e as unknown as React.SyntheticEvent)
          }}
        >
          <div className={`flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold overflow-hidden flex-shrink-0 ${user?.avatar_url?.trim() ? '' : 'bg-indigo-600 text-white'}`}>
            {user?.avatar_url?.trim() ? (
              <img src={user.avatar_url} alt={user?.name ?? ''} className="h-full w-full object-cover" onError={(e) => { (e.target as HTMLImageElement).style.display = 'none' }} />
            ) : (
              (user?.name ?? user?.email ?? '?')[0].toUpperCase()
            )}
          </div>
          <span className="hidden sm:inline text-sm font-medium martis-text">
            {user?.name ?? user?.email}
          </span>
          <CaretDownIcon size={12} className="martis-text-muted" />
        </div>
      </div>

      {/* Global search modal */}
      {searchOpen && <GlobalSearch onClose={() => setSearchOpen(false)} />}
    </header>
  )
}
