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
import type { ComponentType } from "react"

function SidebarLayout() {
  const isMobile = useIsMobile()
  const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false)
  const [collapsed, setCollapsed] = useState(() => {
    return localStorage.getItem("martis-sidebar-collapsed") === "true"
  })
  const location = useLocation()

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
      <Sidebar
        mobileOpen={isMobile ? mobileSidebarOpen : undefined}
        onMobileClose={() => setMobileSidebarOpen(false)}
        collapsed={!isMobile && collapsed}
      />

      <Topbar
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

  // Check for user-registered custom shell layout
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
