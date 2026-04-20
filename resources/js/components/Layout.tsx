import { Outlet, Navigate, useLocation } from "react-router-dom"
import { MartisTooltip } from "@/components/MartisTooltip"
import { useAuth } from "@/contexts/AuthContext"
import { config } from "@/lib/config"
import { componentRegistry } from "@/lib/componentRegistry"
import { Sidebar } from "@/components/Sidebar"
import { Topbar } from "@/components/Topbar"
import { Footer } from "@/components/Footer"
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
function resolveShellComponent<C extends ComponentType<object>>(
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
        <div className="martis-page">
          <Outlet />
        </div>
        <Footer />
      </main>

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

  // Resolve layout preset from config
  const preset = config.layout?.preset ?? "sidebar"
  const LayoutComponent = presets[preset] ?? SidebarLayout

  return (
    <>
      <MartisTooltip />
      <LayoutComponent />
    </>
  )
}
