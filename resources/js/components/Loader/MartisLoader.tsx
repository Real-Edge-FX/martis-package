import { useTranslation } from "react-i18next"
import { config } from "@/lib/config"
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
 * This is a Martis extension (not Nova v5). Fully customizable per config.
 */
export function MartisLoader({
  loading = true,
  message,
  overlay = false,
  disabled = false,
  size = "md",
  children,
  context,
}: MartisLoaderProps) {
  const { t } = useTranslation("messages")

  const loaderCfg = config.loader
  const globallyDisabled = loaderCfg?.disabled === true
  const contextDisabled = context !== undefined && loaderCfg?.disableOn?.[context] === true

  if (disabled || globallyDisabled || contextDisabled || !loading) {
    return overlay ? <>{children}</> : null
  }

  const loaderConfig = (config as Record<string, unknown>).loader as {
    message?: string
    icon?: string
    logo?: string
    spinnerColor?: string
    overlayOpacity?: number
    overlayColor?: string
  } | undefined

  const displayMessage = message ?? loaderConfig?.message ?? t("loading")
  const iconName = loaderConfig?.icon
  const logoUrl = loaderConfig?.logo
  const spinnerColor = loaderConfig?.spinnerColor ?? "var(--martis-accent)"
  const overlayOpacity = loaderConfig?.overlayOpacity ?? 0.6
  const overlayColor = loaderConfig?.overlayColor ?? "var(--martis-bg)"

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
        <span className={textSize} style={{ color: "var(--martis-text-muted)" }}>
          {displayMessage}
        </span>
      )}
    </div>
  )

  if (overlay) {
    return (
      <div className="relative">
        {children}
        <div
          className="absolute inset-0 flex items-center justify-center rounded-lg transition-opacity"
          style={{
            backgroundColor: overlayColor,
            opacity: overlayOpacity,
            zIndex: 10,
          }}
        >
          {loaderContent}
        </div>
      </div>
    )
  }

  return loaderContent
}
