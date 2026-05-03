import { Outlet, NavLink, useLocation } from "react-router-dom"
import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api"
import { config } from "@/lib/config"
import {
  flattenNavigationItems,
  getItemCount,
  formatItemCount,
  mergeBadgeCounts,
  useNavigationRefreshOnNavigate,
} from "@/lib/navigation"
import { useAuth } from "@/contexts/AuthContext"
import { Breadcrumbs } from "@/components/Breadcrumbs"
import { GlobalSearch } from "@/components/GlobalSearch"
import { PreferencesMenu, type PreferencesMenuHandle } from "@/components/PreferencesMenu"
import { Footer } from "@/components/Footer"
import { Menu } from "primereact/menu"
import { OverlayPanel } from "primereact/overlaypanel"
import type { MenuItem } from "primereact/menuitem"
import type { NavigationGroup } from "@/types"
import { ResourceIcon } from "@/components/ResourceIcon"
import { useTranslation } from "react-i18next"
import { useRef, useState, useEffect, useMemo } from "react"
import { addShortcut } from "@/lib/keyboardShortcuts"
import logoSrcDefault from "@images/martis-icon.png"
import {
  SquaresFourIcon,
  MagnifyingGlassIcon,
  CaretDownIcon,
  SignOutIcon,
  UserCircleIcon,
} from "@phosphor-icons/react"

/**
 * Topnav brand resolution (v1.7.0). Mirrors `Sidebar.getBrandMark()`
 * but without the collapse override (topnav is single-row, no
 * collapsed state). Theme variants follow the same rule: when only
 * one of light/dark is set, it is reused for the missing slot.
 */
function getBrandMark(): {
  light: string
  dark: string
  mode: "logo" | "icon"
} {
  const logoLight = config.logo ?? config.logoDark ?? null
  const logoDark = config.logoDark ?? config.logo ?? null
  const iconLight = config.icon ?? config.iconDark ?? null
  const iconDark = config.iconDark ?? config.icon ?? null

  if (logoLight) {
    return { light: logoLight, dark: logoDark ?? logoLight, mode: "logo" }
  }
  if (iconLight) {
    return { light: iconLight, dark: iconDark ?? iconLight, mode: "icon" }
  }
  const bundled = logoSrcDefault as string
  return { light: bundled, dark: bundled, mode: "icon" }
}

export function TopnavLayout() {
  const { user, logout } = useAuth()
  const { t } = useTranslation("navigation")
  const menuRef = useRef<Menu>(null)
  const prefsRef = useRef<PreferencesMenuHandle>(null)
  const groupRefs = useRef<Map<string, OverlayPanel>>(new Map())
  const [searchOpen, setSearchOpen] = useState(false)
  const location = useLocation()

  // Full navigation tree: fetched once per session + on route mutations.
  // NOT auto-polled — menu structure rarely changes in production.
  const { data: rawGroups = [] } = useQuery<NavigationGroup[]>({
    queryKey: ["navigation"],
    queryFn: () => api.get("/api/navigation"),
    staleTime: Infinity,
    refetchInterval: false,
    refetchOnWindowFocus: false,
  })
  // Lightweight badges payload: polled on a separate, longer interval.
  const badgesPollInterval = config.navigation?.badgesPollInterval ?? 300_000
  const { data: badges } = useQuery<Record<string, number>>({
    queryKey: ["navigation", "badges"],
    queryFn: () => api.get("/api/navigation/badges"),
    staleTime: 1000 * 30,
    refetchInterval: badgesPollInterval > 0 ? badgesPollInterval : false,
    refetchOnWindowFocus: true,
    enabled: rawGroups.length > 0,
  })
  const groups = badges ? mergeBadgeCounts(rawGroups, badges) : rawGroups
  useNavigationRefreshOnNavigate()

  const brand = config.brand ?? "Martis"
  const brandMark = getBrandMark()
  const sameVariant = brandMark.light === brandMark.dark
  const showProfile = config.profile?.enabled !== false && config.userMenu?.showProfile !== false
  const searchEnabled = config.search?.enabled !== false

  // mod+K opens the palette regardless of focus; "/" only fires when
  // the user is not currently typing in a form element. Both flow
  // through the central registry so the help overlay (`?`) lists them
  // and consumer Tools see them via `listShortcuts()`.
  useEffect(() => {
    if (!searchEnabled) return

    const dispose = [
      addShortcut("mod+k", () => setSearchOpen(true), {
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
  }, [searchEnabled])

  // Active group — the one whose items include the current path.
  const activeGroup = useMemo(() => {
    for (const g of groups) {
      for (const i of flattenNavigationItems(g)) {
        if (location.pathname.startsWith(i.url)) return g.label ?? ""
      }
    }
    return null
  }, [groups, location.pathname])

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
    ...(showProfile
      ? [
          {
            template: (
              _item: MenuItem,
              options: { className: string; onClick: (e: React.SyntheticEvent) => void },
            ) => (
              <a
                className={options.className}
                href="/martis/profile"
                role="menuitem"
                onClick={options.onClick}
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
  const avatarInitial = (user?.name ?? user?.email ?? "?")[0].toUpperCase()

  return (
    <div className="martis-bg flex h-screen flex-col overflow-hidden">
      <header className="martis-topnav-bar">
        <div className="martis-topnav-brand" data-mode={brandMark.mode}>
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
          {brandMark.mode === "icon" && (
            <span className="martis-topnav-brand-text">{brand}</span>
          )}
        </div>

        <nav className="martis-topnav-links">
          <NavLink
            to="/"
            end
            className={({ isActive }) =>
              "martis-topnav-link" + (isActive ? " active" : "")
            }
          >
            <SquaresFourIcon size={16} className="shrink-0" />
            <span>{t("dashboard")}</span>
          </NavLink>

          {groups.map((group, i) => {
            const groupKey = group.label ?? `group-${i}`
            const items = flattenNavigationItems(group)
            const isActive = activeGroup === groupKey

            // Ungrouped items render inline as top-level links.
            if (!group.label) {
              return items.map((item) => {
                const iconName = item.type === "resource" ? item.icon : item.icon ?? "link"
                return item.external ? (
                  <a
                    key={`${groupKey}-${item.label}-${item.url}`}
                    href={item.url}
                    target="_blank"
                    rel="noreferrer"
                    className="martis-topnav-link"
                  >
                    <ResourceIcon iconName={iconName ?? null} size={16} className="shrink-0" />
                    <span>{item.label}</span>
                  </a>
                ) : (
                  <NavLink
                    key={item.type === "resource" ? item.uriKey : `${groupKey}-${item.label}-${item.url}`}
                    to={item.url}
                    className={({ isActive }) =>
                      "martis-topnav-link" + (isActive ? " active" : "")
                    }
                  >
                    <ResourceIcon iconName={iconName ?? null} size={16} className="shrink-0" />
                    <span>{item.label}</span>
                  </NavLink>
                )
              })
            }

            // Grouped items collapse into a dropdown trigger + OverlayPanel.
            return (
              <div key={groupKey} className="martis-topnav-group">
                <button
                  type="button"
                  className={"martis-topnav-link" + (isActive ? " active" : "")}
                  onClick={(e) => groupRefs.current.get(groupKey)?.toggle(e)}
                >
                  {group.icon && (
                    <ResourceIcon iconName={group.icon} size={16} className="shrink-0" />
                  )}
                  <span>{group.label}</span>
                  <CaretDownIcon size={12} className="shrink-0" />
                </button>
                <OverlayPanel
                  ref={(el) => {
                    if (el) groupRefs.current.set(groupKey, el)
                    else groupRefs.current.delete(groupKey)
                  }}
                  showCloseIcon={false}
                  style={{
                    backgroundColor: "var(--martis-surface)",
                    border: "1px solid var(--martis-border)",
                    borderRadius: "var(--martis-radius-lg)",
                    padding: 6,
                    minWidth: 220,
                  }}
                >
                  {items.map((item) => {
                    const iconName = item.type === "resource" ? item.icon : item.icon ?? "link"
                    const onClick = () => groupRefs.current.get(groupKey)?.hide()
                    const count = getItemCount(item)
                    return item.external ? (
                      <a
                        key={`${groupKey}-${item.label}-${item.url}`}
                        href={item.url}
                        target="_blank"
                        rel="noreferrer"
                        className="martis-topnav-dropdown-item"
                        onClick={onClick}
                      >
                        <ResourceIcon iconName={iconName ?? null} size={16} />
                        <span className="martis-sb-item-label">{item.label}</span>
                        {count !== null && (
                          <span className="martis-sb-item-badge">
                            {formatItemCount(count)}
                          </span>
                        )}
                      </a>
                    ) : (
                      <NavLink
                        key={item.type === "resource" ? item.uriKey : `${groupKey}-${item.label}-${item.url}`}
                        to={item.url}
                        className={({ isActive }) =>
                          "martis-topnav-dropdown-item" + (isActive ? " active" : "")
                        }
                        onClick={onClick}
                      >
                        <ResourceIcon iconName={iconName ?? null} size={16} />
                        <span className="martis-sb-item-label">{item.label}</span>
                        {count !== null && (
                          <span className="martis-sb-item-badge">
                            {formatItemCount(count)}
                          </span>
                        )}
                      </NavLink>
                    )
                  })}
                </OverlayPanel>
              </div>
            )
          })}
        </nav>

        <div className="martis-topnav-right">
          {searchEnabled && (
            <button
              type="button"
              className="martis-tb-icon-btn"
              onClick={() => setSearchOpen(true)}
              aria-label="Search"
              title={t("search_placeholder", "Search")}
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
      </header>

      <div className="martis-topnav-breadcrumbs">
        <Breadcrumbs />
      </div>

      <main className="flex-1 overflow-auto">
        <div className="martis-page">
          <Outlet />
        </div>
      </main>

      <Footer />

      {searchOpen && <GlobalSearch onClose={() => setSearchOpen(false)} />}
    </div>
  )
}
