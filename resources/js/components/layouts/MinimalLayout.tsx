import { Outlet } from "react-router-dom"
import { Footer } from "@/components/Footer"

export function MinimalLayout() {
  return (
    <div className="martis-bg flex h-screen flex-col overflow-hidden">
      <main className="flex-1 overflow-auto p-6">
        <Outlet />
      </main>
      <Footer />
    </div>
  )
}
