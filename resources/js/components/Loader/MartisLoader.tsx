import { useTranslation } from "react-i18next"
import { config, type MartisLoaderConfig } from "@/lib/config"
import { SpinnerGapIcon } from "@phosphor-icons/react"
import { ResourceIcon } from "@/components/ResourceIcon"

export interface MartisLoaderProps {
  /** Whether the loader is visible */
  loading?: boolean
  /** Override config message */
  message?: string | null
  /** Overlay mode: covers parent container with semi-transparent overlay */
  overlay?: boolean
  /** Disable this specific loader (per-context opt-out) */
  disabled?: boolean
  /** Size variant */
  size?: "sm" | "md" | "lg"
  /** Children to render under the loader overlay */
  children?: React.ReactNode
  /**
   * Context identifier used to check loader.disableOn config.
   * Supported: "table", "search", "components", "detail".
   */
  context?: "table" | "search" | "components" | "detail"
  /**
   * Per-instance override merged on top of `config.loader`.
   *
   * Used by the Loader wrapper in `@/components/Loader` to apply
   * `Resource::loaderConfig()` while a resource page is mounted (the
   * wrapper reads the override from `LoaderConfigContext` and forwards
   * it here). Consumers that import `MartisLoader` directly can pass
   * the override inline for one-off cases.
   */
  configOverride?: Partial<MartisLoaderConfig>
}

/**
 * MartisLoader — Configurable loading indicator.
 *
 * Configurable via window.MartisConfig.loader:
 * - message: custom loading text (default: translation key)
 * - icon: Phosphor icon name (replaces spinner)
 * - logo: URL to logo image (replaces spinner)
 * - spinnerColor: CSS color for spinner
 * - overlayOpacity: 0-1 overlay opacity
 * - overlayColor: CSS color for overlay background
 *
 * This is a Martis extension. Fully customizable per config.
 */
export function MartisLoader({
  loading = true,
  message,
  overlay = false,
  disabled = false,
  size = "md",
  children,
  context,
  configOverride,
}: MartisLoaderProps) {
  const { t } = useTranslation("messages")

  // Merge global config with the per-instance override so a resource
  // can supply only the keys it cares about and inherit the rest.
  // `disableOn` is shallow-merged so a resource opting in/out of a
  // single context doesn't clobber the others.
  const baseCfg = config.loader as MartisLoaderConfig | undefined
  const loaderCfg: MartisLoaderConfig | undefined = configOverride
    ? {
        ...baseCfg,
        ...configOverride,
        disableOn: {
          ...(baseCfg?.disableOn ?? {}),
          ...(configOverride.disableOn ?? {}),
        },
      }
    : baseCfg

  const globallyDisabled = loaderCfg?.disabled === true
  const contextDisabled = context !== undefined && loaderCfg?.disableOn?.[context] === true

  if (disabled || globallyDisabled || contextDisabled || !loading) {
    return overlay ? <>{children}</> : null
  }

  const displayMessage = message ?? loaderCfg?.message ?? t("loading")
  const iconName = loaderCfg?.icon
  const logoUrl = loaderCfg?.logo
  const spinnerColor = loaderCfg?.spinnerColor ?? "var(--martis-accent)"
  const overlayOpacity = loaderCfg?.overlayOpacity ?? 0.6
  const overlayColor = loaderCfg?.overlayColor ?? "var(--martis-bg)"

  const spinnerSize = size === "sm" ? 16 : size === "lg" ? 32 : 24
  const textSize = size === "sm" ? "text-xs" : size === "lg" ? "text-base" : "text-sm"

  const indicator = logoUrl ? (
    <img src={logoUrl} alt="" className="animate-pulse" style={{ width: spinnerSize, height: spinnerSize }} />
  ) : iconName ? (
    <ResourceIcon iconName={iconName} size={spinnerSize} className="animate-spin" />
  ) : (
    <SpinnerGapIcon size={spinnerSize} className="animate-spin" style={{ color: spinnerColor }} />
  )

  const loaderContent = (
    <div className="flex items-center justify-center gap-2">
      {indicator}
      {displayMessage && (
        <span className={textSize} style={{ color: "var(--martis-text)" }}>
          {displayMessage}
        </span>
      )}
    </div>
  )

  if (overlay) {
    // The overlay tint and the loader chrome live in two stacked layers
    // so the spinner + text stay at full opacity even when the tint is
    // dimmed (dark mode previously rendered the label at 60% over a
    // 60% dark backdrop, which is illegible).
    return (
      <div className="relative">
        {children}
        <div
          aria-hidden="true"
          className="absolute inset-0 rounded-lg transition-opacity"
          style={{
            backgroundColor: overlayColor,
            opacity: overlayOpacity,
            zIndex: 10,
          }}
        />
        <div
          className="absolute inset-0 flex items-center justify-center"
          style={{ zIndex: 11 }}
        >
          {loaderContent}
        </div>
      </div>
    )
  }

  return loaderContent
}
