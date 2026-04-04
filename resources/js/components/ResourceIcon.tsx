import * as PhosphorIcons from "@phosphor-icons/react"
import type { IconProps } from "@phosphor-icons/react"

/**
 * Aliases for backward compatibility and convenience.
 * Maps short/custom names to their Phosphor PascalCase export names.
 */
const aliases: Record<string, string> = {
  folders: "FolderSimple",
  chat: "ChatCircle",
  "chart-bar": "ChartBar",
  "file-text": "FileText",
  "shopping-cart": "ShoppingCart",
  "map-pin": "MapPin",
  "credit-card": "CreditCard",
}

/**
 * Converts kebab-case icon names to PascalCase.
 * e.g., "arrow-counter-clockwise" → "ArrowCounterClockwise"
 */
function kebabToPascal(name: string): string {
  return name
    .split("-")
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join("")
}

const iconExports = PhosphorIcons as unknown as Record<string, React.ComponentType<IconProps>>

/**
 * Resolves any Phosphor icon by name.
 * Accepts kebab-case ("shopping-cart"), PascalCase ("ShoppingCart"),
 * or lowercase ("database"). Falls back to Database icon.
 */
function resolveIcon(name: string): React.ComponentType<IconProps> {
  // 1. Check aliases first
  const aliased = aliases[name]
  if (aliased && typeof iconExports[aliased] === "function") {
    return iconExports[aliased]
  }

  // 2. Try PascalCase conversion from kebab-case
  const pascal = kebabToPascal(name)
  if (typeof iconExports[pascal] === "function") {
    return iconExports[pascal]
  }

  // 3. Try direct lookup (user passes PascalCase)
  if (typeof iconExports[name] === "function") {
    return iconExports[name]
  }

  // 4. Try capitalizing first letter only (e.g., "database" → "Database")
  const capitalized = name.charAt(0).toUpperCase() + name.slice(1)
  if (typeof iconExports[capitalized] === "function") {
    return iconExports[capitalized]
  }

  return PhosphorIcons.Database
}

interface ResourceIconProps {
  iconName: string | null | undefined
  size?: number
  className?: string
  weight?: IconProps["weight"]
}

export function ResourceIcon({ iconName, size, className, weight }: ResourceIconProps) {
  const Icon = resolveIcon(iconName ?? "database")
  return <Icon size={size} className={className} weight={weight} />
}
