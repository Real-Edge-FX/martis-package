import { Outlet } from "react-router"
import { Footer } from "@/components/Footer"
import { MAIN_CONTENT_ID } from "@/components/SkipLink"

// No repeated navigation to bypass, so no skip link; the landmark keeps the
// id so a link of the host page can still target it.
export function MinimalLayout() {
  return (
    <div className="martis-bg flex h-screen flex-col overflow-hidden">
      <main id={MAIN_CONTENT_ID} tabIndex={-1} className="flex-1 overflow-auto p-6">
        <Outlet />
      </main>
      <Footer />
    </div>
  )
}
