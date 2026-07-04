import { Outlet, Navigate, useLocation } from "react-router-dom"
import { MartisTooltip } from "@/components/MartisTooltip"
import { useAuth } from "@/contexts/AuthContext"
import { config } from "@/lib/config"
import { componentRegistry } from "@/lib/componentRegistry"
import { Sidebar } from "@/components/Sidebar"
import { Topbar } from "@/components/Topbar"
import { Footer } from "@/components/Footer"
import { ImpersonationBanner as BundledImpersonationBanner } from "@/components/ImpersonationBanner"
import { KeyboardShortcutsHelp } from "@/components/KeyboardShortcutsHelp"
import { NavigationProgress } from "@/components/NavigationProgress"
import { TopnavLayout } from "@/components/layouts/TopnavLayout"
import { MinimalLayout } from "@/components/layouts/MinimalLayout"
import { TableSkeleton } from "@/components/LoadingSkeleton"
import { useIsMobile } from "@/hooks/useIsMobile"
import { useState, useEffect } from "react"
import type { ComponentProps, ComponentType } from "react"

/**
 * Resolve a shell-level component (sidebar, topbar, footer) from the
 * registry so consumers can swap any of them without replacing the
 * entire shell. Resolution:
 *
 *   1. `config('martis.layout.components.<piece>')` — a custom registry
 *      key set in PHP config. Wins when the key is registered.
 *   2. `layout:<piece>` — the default registry key. Any component
 *      registered there wins when no config override is set.
 *   3. Fallback to the bundled component.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
function resolveShellComponent<C extends ComponentType<any>>(
  piece: "shell" | "sidebar" | "topbar" | "footer",
  fallback: C,
): C {
  const configured = config.layout?.components?.[piece]
  if (configured && componentRegistry.has(configured)) {
    const override = componentRegistry.resolve(configured)
    if (override) return override as unknown as C
  }
  const defaultKey = `layout:${piece}`
  if (componentRegistry.has(defaultKey)) {
    const override = componentRegistry.resolve(defaultKey)
    if (override) return override as unknown as C
  }
  return fallback
}

function SidebarLayout() {
  const isMobile = useIsMobile()
  const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false)
  const [collapsed, setCollapsed] = useState(() => {
    return localStorage.getItem("martis-sidebar-collapsed") === "true"
  })
  const location = useLocation()

  const SidebarComponent = resolveShellComponent<ComponentType<ComponentProps<typeof Sidebar>>>(
    "sidebar",
    Sidebar,
  )
  const TopbarComponent = resolveShellComponent<ComponentType<ComponentProps<typeof Topbar>>>(
    "topbar",
    Topbar,
  )

  useEffect(() => {
    setMobileSidebarOpen(false)
  }, [location.pathname])

  useEffect(() => {
    localStorage.setItem("martis-sidebar-collapsed", String(collapsed))
  }, [collapsed])

  // Mirror the shell's layout flags onto <html> so portal'd overlays
  // (command palette, toasts, modals) can read them via `html[data-*]`
  // selectors — a React portal into document.body does not inherit the
  // attribute from .martis-shell, which is a sibling subtree.
  useEffect(() => {
    const root = document.documentElement
    if (isMobile) root.setAttribute("data-mobile", "true")
    else root.removeAttribute("data-mobile")
    if (!isMobile && collapsed) root.setAttribute("data-sidebar-collapsed", "true")
    else root.removeAttribute("data-sidebar-collapsed")
    return () => {
      root.removeAttribute("data-mobile")
      root.removeAttribute("data-sidebar-collapsed")
    }
  }, [isMobile, collapsed])

  useEffect(() => {
    if (isMobile && mobileSidebarOpen) {
      document.body.style.overflow = "hidden"
    } else {
      document.body.style.overflow = ""
    }
    return () => {
      document.body.style.overflow = ""
    }
  }, [isMobile, mobileSidebarOpen])

  return (
    <div
      className="martis-shell martis-bg"
      data-mobile={isMobile ? "true" : undefined}
      data-sidebar-collapsed={!isMobile && collapsed ? "true" : undefined}
    >
      <NavigationProgress />

      <SidebarComponent
        mobileOpen={isMobile ? mobileSidebarOpen : undefined}
        onMobileClose={() => setMobileSidebarOpen(false)}
        collapsed={!isMobile && collapsed}
      />

      <TopbarComponent
        onToggleSidebar={isMobile ? () => setMobileSidebarOpen((v) => !v) : undefined}
        onToggleCollapse={!isMobile ? () => setCollapsed((c) => !c) : undefined}
        sidebarCollapsed={collapsed}
      />

      <main className="martis-shell-content">
        {(() => {
          // Allow consumer override under the canonical registry key
          // `impersonation:banner`. When unset the bundled banner
          // renders. Same pattern as `martis:profile-sessions`.
          const Override = componentRegistry.has('impersonation:banner')
            ? (componentRegistry.resolve('impersonation:banner') as React.ComponentType | undefined)
            : undefined
          const Banner = Override ?? BundledImpersonationBanner
          return <Banner />
        })()}
        <div className="martis-page">
          <Outlet />
        </div>
        <Footer />
      </main>

      {/* Mounted once at the shell root: listens for `Shift+?` from
          anywhere and surfaces every shortcut registered via
          `addShortcut()`. Lightweight — no work until the modal opens. */}
      <KeyboardShortcutsHelp />

      {isMobile && (
        <div
          className="martis-shell-backdrop"
          data-open={mobileSidebarOpen ? "true" : undefined}
          onClick={() => setMobileSidebarOpen(false)}
          aria-hidden="true"
        />
      )}
    </div>
  )
}

const presets: Record<string, ComponentType> = {
  sidebar: SidebarLayout,
  topnav: TopnavLayout,
  minimal: MinimalLayout,
}

export function Layout() {
  const { user, isLoading } = useAuth()

  if (isLoading) {
    return (
      <div className="martis-bg flex min-h-screen items-center justify-center">
        <div className="w-96">
          <TableSkeleton />
        </div>
      </div>
    )
  }

  if (!user) return <Navigate to="/login" replace />

  // Whole-shell override — honours both the PHP config key and the
  // default `layout:shell` registry entry.
  const shellConfigKey = config.layout?.components?.shell
  if (shellConfigKey && componentRegistry.has(shellConfigKey)) {
    const CustomShell = componentRegistry.resolve(shellConfigKey) as ComponentType
    return <CustomShell />
  }
  if (componentRegistry.has("layout:shell")) {
    const CustomShell = componentRegistry.resolve("layout:shell") as ComponentType
    return <CustomShell />
  }

  // Resolve layout preset from config. `custom` means the app promises
  // to register its own shell via `layout:shell` — we deliberately
  // don't silently fall back to the bundled sidebar layout so the
  // missing registration surfaces loudly instead of being masked.
  const preset = config.layout?.preset ?? "sidebar"
  if (preset === "custom") {
    return (
      <div className="martis-bg flex min-h-screen items-center justify-center p-6">
        <div className="max-w-md rounded-lg border p-4 text-sm" style={{
          borderColor: "var(--martis-danger)",
          color: "var(--martis-text)",
          backgroundColor: "var(--martis-danger-bg)",
        }}>
          <strong>Layout preset is <code>custom</code></strong> but no component
          is registered under <code>layout:shell</code>. Register one via{" "}
          <code>componentRegistry.register('layout:shell', MyShell)</code> in{" "}
          <code>resources/js/martis/boot.ts</code>, or set{" "}
          <code>config('martis.layout.components.shell')</code> to the key of
          an existing component.
        </div>
      </div>
    )
  }
  const LayoutComponent = presets[preset] ?? SidebarLayout

  return (
    <>
      <MartisTooltip />
      <LayoutComponent />
    </>
  )
}
