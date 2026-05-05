import { Fragment, useState } from "react"
import { NavLink, useLocation } from "react-router-dom"
import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api"
import { config } from "@/lib/config"
import type {
  DashboardDefinition,
  NavigationGroup,
  NavigationGroupChild,
  NavigationItem,
  NavigationNestedGroup,
} from "@/types"
import {
  getNavigationItems,
  getItemCount,
  formatItemCount,
  isLeafActive,
  isNestedGroup,
  isGroupActive,
  mergeBadgeCounts,
  useNavigationRefreshOnNavigate,
} from "@/lib/navigation"
import { useTranslation } from "react-i18next"
import { useGateOptional } from "@/contexts/GateContext"
import logoSrcDefault from "@images/martis-icon.png"
import {
  SquaresFourIcon,
  CaretRightIcon,
  LockKeyIcon,
} from "@phosphor-icons/react"
import { ResourceIcon } from "./ResourceIcon"

function getBrand(): string {
  return config.brand ?? "Martis"
}

/**
 * Sidebar brand resolution (v1.7.0).
 *
 * Three modes, with per-theme variants:
 *
 *   1. `config.logo` set → render the full lockup ALONE (no separate
 *      brand text). When `config.logoDark` is also set, both images
 *      ship in the DOM and CSS hides one based on `<html data-theme>`.
 *   2. `config.icon` set → render the square icon + brand text
 *      side-by-side. `config.iconDark` enables the same theme switch.
 *   3. Neither → bundled Martis cube + brand text.
 *
 * Collapse override (v1.7.0): when the sidebar is collapsed AND the
 * consumer shipped an icon, the icon wins regardless of `logo` —
 * a horizontal lockup gets crammed into the 64px rail otherwise.
 */
function getBrandMark(collapsed: boolean): {
  light: string
  dark: string
  mode: "logo" | "icon"
} {
  const logoLight = config.logo ?? config.logoDark ?? null
  const logoDark = config.logoDark ?? config.logo ?? null
  const iconLight = config.icon ?? config.iconDark ?? null
  const iconDark = config.iconDark ?? config.icon ?? null

  // Collapsed → prefer the icon when the consumer shipped one. The
  // 64px rail cannot host a horizontal lockup without distortion.
  if (collapsed && iconLight) {
    return { light: iconLight, dark: iconDark ?? iconLight, mode: "icon" }
  }

  if (logoLight) {
    return { light: logoLight, dark: logoDark ?? logoLight, mode: "logo" }
  }
  if (iconLight) {
    return { light: iconLight, dark: iconDark ?? iconLight, mode: "icon" }
  }
  const bundled = logoSrcDefault as string
  return { light: bundled, dark: bundled, mode: "icon" }
}

interface SidebarProps {
  mobileOpen?: boolean
  onMobileClose?: () => void
  /** When provided, the sidebar renders in collapsed mode on desktop. */
  collapsed?: boolean
}

interface RenderItemContext {
  groupKey: string
  collapsed: boolean
  isMobile: boolean
  onMobileClose?: () => void
  location: { pathname: string; search: string }
}


/**
 * Render a single leaf navigation item (resource / link / tool / dashboard /
 * lens / filter). Shared between flat groups and nested MenuGroups so the
 * row markup stays consistent across both levels.
 */
function renderLeafItem(
  item: NavigationItem,
  ctx: RenderItemContext,
): JSX.Element {
  const iconName = item.type === "resource" ? item.icon : item.icon ?? "link"
  const showTooltip = !ctx.isMobile && ctx.collapsed ? item.label : undefined
  const count = getItemCount(item)
  const showLabel = ctx.isMobile || !ctx.collapsed
  const showCount = showLabel && count !== null
  const badge = item.badge ?? null

  const inner = (
    <>
      <ResourceIcon iconName={iconName ?? null} size={16} className="shrink-0" />
      {showLabel && (
        <span className="martis-sb-item-label">{item.label}</span>
      )}
      {showLabel && badge && (
        <span
          className="martis-sb-item-tag"
          data-tone={badge.tone}
        >
          {badge.text}
        </span>
      )}
      {showCount && (
        <span className="martis-sb-item-badge">
          {formatItemCount(count!)}
        </span>
      )}
    </>
  )

  const key =
    item.type === "resource"
      ? item.uriKey
      : `${ctx.groupKey}-${item.label}-${item.url}`

  if (item.external) {
    return (
      <a
        key={key}
        href={item.url}
        target="_blank"
        rel="noreferrer"
        className="martis-sb-item"
        data-pr-tooltip={showTooltip}
        data-pr-position="right"
        onClick={ctx.isMobile ? ctx.onMobileClose : undefined}
      >
        {inner}
      </a>
    )
  }

  // Use our own active rule (see isLeafActive) instead of NavLink's
  // default prefix matcher. NavLink with a *string* className would
  // still append its own " active" based on its internal isActive
  // (a bare-pathname prefix match), which lights up the resource link
  // plus every lens/filter sibling together. Passing a function lets
  // us ignore NavLink's verdict entirely and return the precomputed
  // class. `aria-current="page"` is still emitted by NavLink based on
  // its own match — visually irrelevant since we drive the highlight
  // via the `active` class.
  const active = isLeafActive(item, ctx.location)
  const className = "martis-sb-item" + (active ? " active" : "")

  // Mirror the resource's per-resource accent on the active leaf so
  // the sidebar selection lines up visually with the page header.
  // The accent var is set on this single element only — siblings on
  // the sidebar keep the user's global accent. See useResourceAccent
  // for the same pattern applied to the page wrapper.
  const accentProps =
    active && item.type === 'resource' && item.accentColor
      ? buildAccentProps(item.accentColor)
      : null

  return (
    <NavLink
      key={key}
      to={item.url}
      end
      className={() => className}
      data-pr-tooltip={showTooltip}
      data-pr-position="right"
      onClick={ctx.isMobile ? ctx.onMobileClose : undefined}
      {...accentProps}
    >
      {inner}
    </NavLink>
  )
}

const HEX_RE = /^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i

/**
 * Build the `data-resource-accent` / inline-style props that pin a
 * single sidebar leaf to a resource's accent. Mirrors the contract
 * of `useResourceAccent` (which targets the page wrapper) so the
 * two surfaces stay in lockstep.
 */
function buildAccentProps(accent: string): {
  'data-resource-accent'?: string
  style?: React.CSSProperties
} {
  if (HEX_RE.test(accent)) {
    return { style: { '--martis-accent': accent } as React.CSSProperties }
  }
  return { 'data-resource-accent': accent }
}

/**
 * Render a nested MenuGroup: its own collapsible header + an indented
 * list of leaf items underneath. Sits inside a section's items list.
 */
function NestedGroupBlock({
  group,
  parentKey,
  ctx,
}: {
  group: NavigationNestedGroup
  parentKey: string
  ctx: RenderItemContext
}): JSX.Element {
  const groupKey = `${parentKey}::${group.label}`
  const [open, setOpen] = useState(true)
  const showLabel = ctx.isMobile || !ctx.collapsed
  const collapsable = group.collapsable !== false
  const iconNode = group.icon && (
    <ResourceIcon iconName={group.icon} size={14} className="shrink-0" />
  )
  const labelNode = <span>{group.label}</span>
  const caretNode = collapsable && (
    <CaretRightIcon size={10} className="caret" />
  )

  return (
    <div className="martis-sb-subgroup" data-open={open ? "true" : "false"}>
      {showLabel && (
        <div className="martis-sb-subgroup-header">
          {/* Label area — link when path() is set, plain button otherwise.
              The chevron lives outside this so collapse + deep-link can
              coexist on the same header. */}
          {group.path ? (
            <NavLink
              to={group.path}
              end
              className={() =>
                "martis-sb-subgroup-label martis-sb-subgroup-label--link" +
                (isGroupActive(group.path, group.items, ctx.location) ? " active" : "")
              }
            >
              {iconNode}
              {labelNode}
            </NavLink>
          ) : (
            <button
              type="button"
              className="martis-sb-subgroup-label"
              onClick={() => collapsable && setOpen((o) => !o)}
            >
              {iconNode}
              {labelNode}
            </button>
          )}
          {/* Independent chevron — always toggles collapse, regardless of
              whether the label is a link. Hidden when the group opted
              out of collapsing entirely. */}
          {collapsable && (
            <button
              type="button"
              className="martis-sb-subgroup-toggle"
              aria-label={open ? 'Collapse' : 'Expand'}
              onClick={(e) => { e.preventDefault(); e.stopPropagation(); setOpen((o) => !o) }}
            >
              {caretNode}
            </button>
          )}
        </div>
      )}
      {(open || (!ctx.isMobile && ctx.collapsed)) && (
        <div className="martis-sb-subgroup-items">
          {group.items.map((item) =>
            renderLeafItem(item, { ...ctx, groupKey }),
          )}
        </div>
      )}
    </div>
  )
}

export function Sidebar({ mobileOpen, onMobileClose, collapsed = false }: SidebarProps = {}) {
  const { t } = useTranslation("navigation")
  // v1.11.0+ — soft-gate hook. Optional because tests may render the
  // sidebar without the GateProvider; in that case clicks on locked
  // entries fall through normally (no modal, no interception). Real
  // panel mounts always have it.
  const gate = useGateOptional()
  // Full navigation tree: fetched once per session + on route mutations.
  // NOT auto-polled — menu structure rarely changes in production.
  const { data: rawGroups = [] } = useQuery<NavigationGroup[]>({
    queryKey: ["navigation"],
    queryFn: () => api.get("/api/navigation"),
    staleTime: Infinity,
    refetchInterval: false,
    refetchOnWindowFocus: false,
  })
  // v1.10.5+: every dashboard whose `parent()` is null appears as a
  // top-level entry under DASHBOARDS. Children (with `parent()` set
  // to the uriKey of another dashboard) live inside their parent's
  // page as a tab strip — never in the sidebar. Same query key
  // Dashboard.tsx uses, so the two surfaces share the cache.
  const { data: dashboardsResp } = useQuery<{ data: { dashboards: DashboardDefinition[] } }>({
    queryKey: ["dashboards"],
    queryFn: () => api.get("/api/dashboards"),
    staleTime: Infinity,
    refetchInterval: false,
    refetchOnWindowFocus: false,
  })
  const rootDashboards = (dashboardsResp?.data?.dashboards ?? []).filter(
    (d) => d.parent === null,
  )
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

  const isMobile = mobileOpen !== undefined
  const location = useLocation()

  const [expandedGroups, setExpandedGroups] = useState<Record<string, boolean>>({})

  function toggleGroup(label: string) {
    setExpandedGroups((prev) => {
      // Groups default to open when no entry exists, so the first click
      // must explicitly write `false` instead of toggling `undefined`.
      const currentlyOpen = prev[label] !== false
      return { ...prev, [label]: !currentlyOpen }
    })
  }

  const brand = getBrand()
  const brandMark = getBrandMark(!isMobile && collapsed)
  const sameVariant = brandMark.light === brandMark.dark

  const mobileAttr = isMobile ? (mobileOpen ? "open" : "true") : undefined

  return (
    <aside
      className="martis-sb"
      data-collapsed={!isMobile && collapsed ? "true" : "false"}
      data-mobile={mobileAttr}
      style={!isMobile && !collapsed ? { width: "var(--sidebar-width, 240px)" } : undefined}
      aria-label={t("section_resources", "Resources")}
    >
      <div className="martis-sb-logo" data-mode={brandMark.mode}>
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
        {brandMark.mode === "icon" && (!isMobile && collapsed ? null : (
          <span className="martis-sb-logo-text">{brand}</span>
        ))}
      </div>

      <div className="martis-sb-scroll">
        <div className="martis-sb-group" data-open="true">
          {(isMobile || !collapsed) && (
            <div className="martis-sb-group-label">
              <span>{t("dashboards", "Dashboards")}</span>
            </div>
          )}
          {rootDashboards.length > 0 ? (
            // v1.10.5+: one entry per ROOT dashboard. Children (any
            // dashboard whose `parent()` returns a non-null uriKey)
            // never appear in the sidebar — they live inside their
            // parent's page as a tab strip. The first root dashboard
            // doubles as the panel root link (`/`) so deep-link
            // bookmarks and the sidebar stay in sync.
            rootDashboards.map((dashboard, idx) => {
              const isFirst = idx === 0
              const lock = dashboard.lock ?? null
              const isLocked = lock !== null
              return (
                <NavLink
                  key={dashboard.uriKey}
                  to={isFirst ? "/" : `/dashboards/${dashboard.uriKey}`}
                  end
                  className={({ isActive }) =>
                    "martis-sb-item" + (isActive ? " active" : "") + (isLocked ? " locked" : "")
                  }
                  data-pr-tooltip={!isMobile && collapsed ? dashboard.name : undefined}
                  data-pr-position="right"
                  onClick={(event) => {
                    if (isLocked && lock !== null && gate !== null) {
                      // v1.11.0+ — locked entries do not navigate;
                      // the modal opens instead. Fall back to navigation
                      // when the GateProvider is missing (tests, edge
                      // cases) so the route guard catches it.
                      event.preventDefault()
                      gate.open(lock)
                      return
                    }
                    if (isMobile && onMobileClose) onMobileClose()
                  }}
                >
                  <SquaresFourIcon size={16} className="shrink-0" />
                  {(isMobile || !collapsed) && (
                    <span className="martis-sb-item-label">{dashboard.name}</span>
                  )}
                  {(isMobile || !collapsed) && dashboard.badge && (
                    <span
                      className="martis-sb-item-tag"
                      data-tone={dashboard.badge.tone}
                    >
                      {dashboard.badge.text}
                    </span>
                  )}
                  {(isMobile || !collapsed) && isLocked && (
                    <LockKeyIcon
                      size={12}
                      className="shrink-0"
                      style={{ color: 'var(--martis-text-muted)' }}
                    />
                  )}
                </NavLink>
              )
            })
          ) : (
            // No dashboards registered — render a single entry pointing
            // at `/` so the sidebar stays usable on a bare-bones install
            // with no `Martis::dashboards([...])` call. The page renders
            // the built-in welcome view in that case.
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
          )}
        </div>

        {groups.map((group, i) => {
          const groupKey = group.label ?? `group-${i}`
          const isExpanded = expandedGroups[groupKey] !== false
          const currentSection = group.section ?? null
          const previousSection = i > 0 ? groups[i - 1].section ?? null : null
          const showSection =
            currentSection !== null &&
            currentSection !== previousSection &&
            (isMobile || !collapsed)
          const ctx: RenderItemContext = {
            groupKey,
            collapsed,
            isMobile,
            onMobileClose,
            location: { pathname: location.pathname, search: location.search },
          }
          const groupCollapsable = group.collapsable !== false
          const groupIcon = group.icon && (
            <ResourceIcon iconName={group.icon} size={14} className="shrink-0" />
          )
          const groupLabel = <span>{group.label}</span>
          return (
            <Fragment key={groupKey}>
              {showSection && (
                <div className="martis-sb-section-heading" aria-hidden="true">
                  {currentSection}
                </div>
              )}
              <div
                className="martis-sb-group"
                data-open={isExpanded ? "true" : "false"}
              >
                {group.label && (isMobile || !collapsed) && (
                  <div className="martis-sb-group-header">
                    {/* Label area: NavLink when path() is set, button
                        otherwise. The chevron lives outside so deep-link
                        and collapse can coexist on the same header. */}
                    {group.path ? (
                      <NavLink
                        to={group.path}
                        end
                        className={() =>
                          "martis-sb-group-label martis-sb-group-label--link" +
                          (isGroupActive(group.path, group.items, { pathname: location.pathname, search: location.search }) ? " active" : "")
                        }
                      >
                        {groupIcon}
                        {groupLabel}
                      </NavLink>
                    ) : (
                      <button
                        type="button"
                        className="martis-sb-group-label"
                        onClick={() => groupCollapsable && toggleGroup(groupKey)}
                      >
                        {groupIcon}
                        {groupLabel}
                      </button>
                    )}
                    {/* Independent chevron — always toggles collapse,
                        regardless of whether the label is a link. */}
                    {groupCollapsable && (
                      <button
                        type="button"
                        className="martis-sb-group-toggle"
                        aria-label={isExpanded ? 'Collapse' : 'Expand'}
                        onClick={(e) => { e.preventDefault(); e.stopPropagation(); toggleGroup(groupKey) }}
                      >
                        <CaretRightIcon size={10} className="caret" />
                      </button>
                    )}
                  </div>
                )}
                {(isExpanded || (!isMobile && collapsed)) &&
                  getNavigationItems(group).map((child: NavigationGroupChild, idx) => {
                    if (isNestedGroup(child)) {
                      return (
                        <NestedGroupBlock
                          key={`${groupKey}-nested-${child.label}-${idx}`}
                          group={child}
                          parentKey={groupKey}
                          ctx={ctx}
                        />
                      )
                    }
                    return renderLeafItem(child, ctx)
                  })}
              </div>
            </Fragment>
          )
        })}
      </div>

      {(config.version || config.docsUrl) && (
        <div className="martis-sb-footer">
          {config.version && (
            <span className="martis-sb-footer-version">
              {/^\d/.test(config.version) ? `v${config.version}` : config.version}
            </span>
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
