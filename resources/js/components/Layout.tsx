import { Outlet, Navigate, useLocation } from "react-router-dom"
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
  const location = useLocation()

  // Close sidebar on navigation
  useEffect(() => {
    setMobileSidebarOpen(false)
  }, [location.pathname])

  // Prevent body scroll while mobile sidebar is open
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
    <div className="martis-bg flex h-screen overflow-hidden">
      {/* Mobile backdrop */}
      {isMobile && mobileSidebarOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/50"
          onClick={() => setMobileSidebarOpen(false)}
          aria-hidden="true"
        />
      )}

      <Sidebar
        mobileOpen={isMobile ? mobileSidebarOpen : undefined}
        onMobileClose={() => setMobileSidebarOpen(false)}
      />

      <div className="flex flex-1 flex-col overflow-hidden">
        <Topbar
          onToggleSidebar={isMobile ? () => setMobileSidebarOpen((v) => !v) : undefined}
        />
        <main className="flex-1 overflow-auto p-6">
          <Outlet />
        </main>
        <Footer />
      </div>
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

  return <LayoutComponent />
}
