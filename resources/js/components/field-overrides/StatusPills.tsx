import type { FieldDisplayProps } from "@/components/fields/types"

/**
 * Built-in field display override: Status Pills
 *
 * Renders select/status values as colored pill badges.
 * Activated via PHP: Select::make("status")->component("status-pills")
 */
const statusColors: Record<string, { bg: string; text: string }> = {
  planning: { bg: "#dbeafe", text: "#1d4ed8" },
  in_progress: { bg: "#fef3c7", text: "#92400e" },
  on_hold: { bg: "#fce7f3", text: "#9d174d" },
  completed: { bg: "#d1fae5", text: "#065f46" },
  cancelled: { bg: "#fee2e2", text: "#991b1b" },
}

export function StatusPills({ value }: FieldDisplayProps) {
  const strValue = String(value ?? "")
  const colors = statusColors[strValue] ?? { bg: "var(--martis-surface)", text: "var(--martis-text)" }
  const displayLabel = strValue.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase())

  return (
    <span
      className="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
      style={{ backgroundColor: colors.bg, color: colors.text }}
    >
      {displayLabel}
    </span>
  )
}
