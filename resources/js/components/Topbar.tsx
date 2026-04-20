import { useRef, useState, useEffect, useCallback } from "react"
import { useNavigate } from "react-router-dom"
import { useAuth } from "@/contexts/AuthContext"
import { useTheme } from "@/contexts/ThemeContext"
import { config } from "@/lib/config"
import { Breadcrumbs } from "@/components/Breadcrumbs"
import { GlobalSearch } from "@/components/GlobalSearch"
import { PreferencesMenu, type PreferencesMenuHandle } from "@/components/PreferencesMenu"
import { Menu } from "primereact/menu"
import type { MenuItem } from "primereact/menuitem"
import { useTranslation } from "react-i18next"
import { MagnifyingGlassIcon, CaretDownIcon, SignOutIcon, UserCircleIcon, ListIcon, CaretDoubleLeftIcon, CaretDoubleRightIcon } from "@phosphor-icons/react"
import { useIsMobile } from "@/hooks/useIsMobile"

interface TopbarProps {
  /** Callback for the mobile hamburger — undefined on desktop. */
  onToggleSidebar?: () => void
  /** Callback to toggle the desktop collapsed state. When provided, a
   *  chevron button renders on the left of the topbar. */
  onToggleCollapse?: () => void
  /** Current collapsed state, used to pick chevron direction + tooltip. */
  sidebarCollapsed?: boolean
}

export function Topbar({ onToggleSidebar, onToggleCollapse, sidebarCollapsed = false }: TopbarProps = {}) {
  const { user, logout } = useAuth()
  const { t } = useTranslation("navigation")
  const navigate = useNavigate()
  const menuRef = useRef<Menu>(null)
  const prefsRef = useRef<PreferencesMenuHandle>(null)
  const [searchOpen, setSearchOpen] = useState(false)
  const isMobile = useIsMobile()

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

  const hasAvatar = !!user?.avatar_url?.trim()
  const avatarInitial = (user?.name ?? user?.email ?? '?')[0].toUpperCase()

  return (
    <header className="martis-tb" data-mobile={onToggleSidebar ? "true" : undefined}>
      {onToggleSidebar && (
        <button
          type="button"
          className="martis-tb-menu-btn"
          onClick={onToggleSidebar}
          aria-label={t("open_sidebar", "Menu")}
        >
          <ListIcon size={18} />
        </button>
      )}

      {onToggleCollapse && (
        <button
          type="button"
          className="martis-tb-collapse-btn"
          onClick={onToggleCollapse}
          aria-label={sidebarCollapsed ? t("expand_sidebar") : t("collapse_sidebar")}
          data-pr-tooltip={
            sidebarCollapsed ? t("expand_sidebar") : t("collapse_sidebar")
          }
          data-pr-position="bottom"
        >
          {sidebarCollapsed ? (
            <CaretDoubleRightIcon size={16} />
          ) : (
            <CaretDoubleLeftIcon size={16} />
          )}
        </button>
      )}

      <nav className="martis-tb-breadcrumbs">
        <Breadcrumbs />
      </nav>

      {searchMode === "bar" && (
        <button
          type="button"
          className="martis-tb-search"
          onClick={() => setSearchOpen(true)}
          aria-label={t("search_placeholder", "Press / to search")}
        >
          <MagnifyingGlassIcon size={14} />
          <span className="martis-tb-search-placeholder">
            {config.search?.placeholder ?? t("search_placeholder", "Press / to search")}
          </span>
          <kbd>/</kbd>
        </button>
      )}

      <div className="martis-tb-right">
        {searchMode === "icon" && (
          <button
            type="button"
            className="martis-tb-icon-btn"
            onClick={() => setSearchOpen(true)}
            aria-label={t("search_placeholder", "Search")}
          >
            <MagnifyingGlassIcon size={16} />
          </button>
        )}

        <PreferencesMenu ref={prefsRef} />

        <span className="martis-tb-divider" aria-hidden="true" />

        <Menu model={userMenuItems} popup ref={menuRef} className="min-w-[220px]" />

        <div
          className="martis-tb-user"
          onClick={(e) => {
            prefsRef.current?.hide()
            menuRef.current?.toggle(e)
          }}
          role="button"
          tabIndex={0}
          onKeyDown={(e) => {
            if (e.key === "Enter" || e.key === " ")
              menuRef.current?.toggle(e as unknown as React.SyntheticEvent)
          }}
        >
          <div
            className="martis-tb-user-avatar"
            style={{
              backgroundColor: hasAvatar ? "transparent" : "var(--martis-accent)",
            }}
          >
            {hasAvatar ? (
              <img
                src={user!.avatar_url!}
                alt={user?.name ?? ""}
                style={{ width: "100%", height: "100%", objectFit: "cover" }}
                onError={(e) => {
                  ;(e.target as HTMLImageElement).style.display = "none"
                }}
              />
            ) : (
              avatarInitial
            )}
          </div>
          <span className="martis-tb-user-name">
            {user?.name ?? user?.email}
          </span>
          <CaretDownIcon size={12} className="martis-text-muted" />
        </div>
      </div>

      {searchOpen && <GlobalSearch onClose={() => setSearchOpen(false)} />}
    </header>
  )
}
