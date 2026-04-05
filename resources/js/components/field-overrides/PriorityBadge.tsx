import type { FieldDisplayProps } from "@/components/fields/types"

/**
 * Built-in field display override: Priority Badge
 *
 * Renders priority values as colored badges with icons.
 * Activated via PHP: Select::make("priority")->component("priority-badge")
 */
const priorityConfig: Record<string, { color: string; bg: string; icon: string }> = {
  low: { color: "#059669", bg: "#d1fae5", icon: "\u2193" },
  medium: { color: "#d97706", bg: "#fef3c7", icon: "\u2194" },
  high: { color: "#dc2626", bg: "#fee2e2", icon: "\u2191" },
  critical: { color: "#ffffff", bg: "#dc2626", icon: "\u26a0" },
}

export function PriorityBadge({ value }: FieldDisplayProps) {
  const strValue = String(value ?? "").toLowerCase()
  const config = priorityConfig[strValue] ?? { color: "var(--martis-text)", bg: "var(--martis-surface)", icon: "" }
  const displayLabel = strValue.charAt(0).toUpperCase() + strValue.slice(1)

  return (
    <span
      className="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-bold"
      style={{ backgroundColor: config.bg, color: config.color }}
    >
      <span>{config.icon}</span>
      {displayLabel}
    </span>
  )
}
