import { useRef, useState, useEffect } from "react"
import { useNavigate } from "react-router-dom"
import { useAuth } from "@/contexts/AuthContext"
import { config } from "@/lib/config"
import { buildCustomMenuItems } from "@/lib/userMenu"
import { ResourceIcon } from "@/components/ResourceIcon"
import { Breadcrumbs } from "@/components/Breadcrumbs"
import { GlobalSearch } from "@/components/GlobalSearch"
import { isMacPlatform } from "@/lib/platform"
import { addShortcut } from "@/lib/keyboardShortcuts"
import { NotificationBell } from "@/components/NotificationBell"
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
  const { t, i18n } = useTranslation("navigation")
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

  // Keyboard shortcuts:
  // - mod+K (Cmd on macOS, Ctrl on Windows/Linux) — the canonical
  //   command-palette toggle from the design-system spec. Fires even
  //   while typing in an input so it always feels available.
  // - "/" — opens the palette when focus is not in an input
  //   (Gmail/GitHub-style).
  // Both flow through `addShortcut()` so the help overlay (`?`) lists
  // them automatically and consumer Tools see them in `listShortcuts()`.
  useEffect(() => {
    if (searchMode === "disabled") return

    const dispose = [
      addShortcut("mod+k", () => setSearchOpen((open) => !open), {
        description: "Open command palette",
        group: "Navigation",
        allowInInput: true,
      }),
      addShortcut("/", () => setSearchOpen(true), {
        description: "Open command palette",
        group: "Navigation",
      }),
    ]

    return () => { dispose.forEach((fn) => fn()) }
  }, [searchMode])

  // Resolve a config label through i18n when it matches a translation key.
  // Config can't call __() (it breaks config:cache), so a consumer passes a
  // key and it follows the active locale — parity with the Profile entry.
  const resolveMenuLabel = (label: string): string => (i18n.exists(label) ? t(label) : label)

  // Build the user-menu entries for custom items at a given placement
  // (before/after the built-in Profile entry). Defaults to "before".
  //
  // Each item is rendered through a template that draws its icon with
  // <ResourceIcon> — the SAME inline-SVG Phosphor path the built-in Profile /
  // Sign-out entries use — instead of handing the icon string to PrimeReact
  // (which renders a PrimeIcons font glyph with different box metrics, leaving
  // the custom label horizontally misaligned against the built-in items).
  const renderCustomItems = (placement: "before" | "after"): MenuItem[] =>
    buildCustomMenuItems(config.userMenu?.customItems, placement, resolveMenuLabel).map((item) => {
      if ("separator" in item) return { separator: true } as MenuItem
      const url = item.url
      // Internal app paths ("/…") navigate via the SPA router; everything with
      // a scheme (http(s):, mailto:, tel:) or protocol-relative ("//…") is a
      // native link. Only http(s) targets open in a new tab — mailto:/tel:
      // must hand off to the OS handler in place.
      const internal = url ? url.startsWith("/") : false
      const httpExternal = url ? /^https?:\/\//.test(url) : false
      return {
        template: (
          _menuItem: MenuItem,
          options: { className: string; onClick: (e: React.SyntheticEvent) => void },
        ) => (
          <a
            className={options.className}
            href={url}
            role="menuitem"
            target={httpExternal ? "_blank" : undefined}
            rel={httpExternal ? "noopener noreferrer" : undefined}
            onClick={(e) => {
              if (url && internal) {
                e.preventDefault()
                navigate(url)
              }
              options.onClick(e)
            }}
          >
            {/* Render the icon only when set — ResourceIcon(null) falls back to
                a database glyph, which would surprise an icon-less item. */}
            {item.icon && (
              <ResourceIcon iconName={item.icon} size={16} className="p-menuitem-icon" />
            )}
            <span className="p-menuitem-text">{item.label}</span>
          </a>
        ),
      } as MenuItem
    })

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
    // Custom items placed BEFORE the built-in Profile entry (the default).
    // Labels are resolved through i18n when they match a translation key —
    // config files can't call __(), so a consumer passes a key and it follows
    // the active locale, matching every other Martis surface.
    ...renderCustomItems("before"),
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
    // Custom items placed AFTER Profile (opt-in via `position: "after"`).
    ...renderCustomItems("after"),
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
          data-pr-tooltip={t("open_sidebar", "Menu")}
          data-pr-position="bottom"
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
          <kbd className="martis-kbd">{isMacPlatform() ? "\u2318K" : "Ctrl K"}</kbd>
        </button>
      )}

      <div className="martis-tb-right">
        {/* Search icon inside the right cluster.
            - searchMode==="icon" shows it always (config picks icon-only).
            - searchMode==="bar" shows it only at narrow viewports (CSS-gated
              via .martis-tb-search-icon--narrow), so the absolute-centered
              bar can hide without leaving a stranded search trigger.
            v1.8.8 \u2014 fixes the overlap where the absolute search collapsed
            to a 36px icon and visually clipped under the bell. */}
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
        {searchMode === "bar" && (
          <button
            type="button"
            className="martis-tb-icon-btn martis-tb-search-icon--narrow"
            onClick={() => setSearchOpen(true)}
            aria-label={t("search_placeholder", "Search")}
          >
            <MagnifyingGlassIcon size={16} />
          </button>
        )}

        <NotificationBell />

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
