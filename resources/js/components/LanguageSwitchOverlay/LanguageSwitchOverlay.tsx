import { useTranslation } from "react-i18next"
import { MartisLoader } from "@/components/Loader"
import { useLocaleSwitching, useLocaleSwitchGeneration } from "@/lib/localeSwitching"
import { useDelayedFlag } from "@/hooks/useDelayedFlag"

/** Keep visible at least this long so a fast switch never flickers illegibly. */
const MIN_VISIBLE_MS = 400
/** Never leave the overlay up longer than this, even if a query hangs. */
const MAX_VISIBLE_MS = 10000
/**
 * Sits above the picker that triggers the switch. The Preferences
 * `.p-overlaypanel` is pinned to `z-index: 10000 !important` (martis.css) and
 * is portaled to `document.body` (painted after `#martis-root`), so an equal
 * z-index would let it float on top; NavigationProgress is at 9999. 10050
 * clears both. Toasts / tooltips (99999) stay above on purpose — a toast
 * during the switch should remain visible.
 */
const OVERLAY_Z_INDEX = 10050

/**
 * Full-screen loading overlay shown while the UI language is switching.
 *
 * Switching funnels through `loadLocale()` (persist → fetch bundle →
 * changeLanguage → refetch every query), which can take a moment and, until
 * now, gave no feedback — the panel simply sat in the old language. This
 * overlay subscribes to the shared switching signal and blocks the viewport
 * with the standard MartisLoader chrome for the whole switch, so the operator
 * always sees that the change is under way.
 *
 * Mounted once at the app root (sibling to ToastContainer), so it covers both
 * the authenticated shell and the guest auth pages. The message renders in the
 * current (pre-switch) language — the language the operator can still read.
 */
export function LanguageSwitchOverlay() {
  const switching = useLocaleSwitching()
  const generation = useLocaleSwitchGeneration()
  const visible = useDelayedFlag(switching, {
    minVisibleMs: MIN_VISIBLE_MS,
    maxVisibleMs: MAX_VISIBLE_MS,
    // Each new switch bumps the generation, releasing the safety-cap latch so a
    // switch that follows a hung one still shows feedback.
    resetKey: generation,
  })
  const { t } = useTranslation("messages")

  if (!visible) return null

  const message = t("switching_language", "Switching language…")

  return (
    <div
      role="status"
      aria-live="polite"
      aria-busy="true"
      className="fixed inset-0 flex items-center justify-center"
      style={{ zIndex: OVERLAY_Z_INDEX }}
    >
      {/* Translucent tint kept on its own layer so the loader chrome stays at
          full opacity and legible over it (mirrors MartisLoader's overlay). */}
      <div
        aria-hidden="true"
        className="absolute inset-0"
        style={{ backgroundColor: "var(--martis-bg)", opacity: 0.75 }}
      />
      <div className="relative">
        <MartisLoader loading size="lg" message={message} />
      </div>
    </div>
  )
}
