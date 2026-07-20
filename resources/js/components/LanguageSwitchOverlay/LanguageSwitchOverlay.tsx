import { useTranslation } from "react-i18next"
import { MartisLoader } from "@/components/Loader"
import { useLocaleSwitching } from "@/lib/localeSwitching"
import { useDelayedFlag } from "@/hooks/useDelayedFlag"

/** Keep visible at least this long so a fast switch never flickers illegibly. */
const MIN_VISIBLE_MS = 400
/** Never leave the overlay up longer than this, even if a query hangs. */
const MAX_VISIBLE_MS = 10000
/**
 * Above every other fixed layer: PrimeReact/custom modals + drawers use
 * `fixed inset-0`, and NavigationProgress uses `zIndex: 9999`. The switch is a
 * blocking, app-wide transition, so it sits on top of all of them.
 */
const OVERLAY_Z_INDEX = 10000

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
  const visible = useDelayedFlag(switching, {
    minVisibleMs: MIN_VISIBLE_MS,
    maxVisibleMs: MAX_VISIBLE_MS,
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
