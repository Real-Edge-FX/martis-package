import * as PhosphorIcons from "@phosphor-icons/react"
import type { IconProps } from "@phosphor-icons/react"

/**
 * Convenience aliases: short/custom names → Phosphor PascalCase export names.
 */
const aliases: Record<string, string> = {
  folders: "FolderSimpleIcon",
  chat: "ChatCircleIcon",
  "chart-bar": "ChartBarIcon",
  "file-text": "FileTextIcon",
  "shopping-cart": "ShoppingCartIcon",
  "map-pin": "MapPinIcon",
  "credit-card": "CreditCardIcon",
}

/**
 * Converts kebab-case icon names to PascalCase.
 * e.g., "users-three" → "UsersThree"
 */
function kebabToPascal(name: string): string {
  return name
    .split("-")
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join("")
}

const iconExports = PhosphorIcons as unknown as Record<string, React.ComponentType<IconProps>>

/**
 * Check if a value is a valid React component (function or forwardRef object).
 * Phosphor icons use React.forwardRef, so typeof is "object", not "function".
 */
function isComponent(val: unknown): val is React.ComponentType<IconProps> {
  if (val == null) return false
  const t = typeof val
  // Regular function component
  if (t === "function") return true
  // forwardRef / memo wrapped component (object with $$typeof or render)
  if (t === "object" && val !== null) {
    const obj = val as Record<string, unknown>
    return "$$typeof" in obj || "render" in obj
  }
  return false
}

/**
 * Resolves ANY Phosphor icon by name.
 * Accepts kebab-case ("shopping-cart"), PascalCase ("ShoppingCart"),
 * or lowercase ("database"). Falls back to Database icon.
 *
 * All 1500+ Phosphor icons are available — no static map needed.
 */
function resolveIcon(name: string): React.ComponentType<IconProps> {
  // 1. Check aliases first
  const aliased = aliases[name]
  if (aliased && isComponent(iconExports[aliased])) {
    return iconExports[aliased]
  }

  // 2. Try PascalCase conversion from kebab-case
  const pascal = kebabToPascal(name)
  if (isComponent(iconExports[`${pascal}Icon`])) {
    return iconExports[`${pascal}Icon`]
  }
  if (isComponent(iconExports[pascal])) {
    return iconExports[pascal]
  }

  // 3. Try direct lookup (user passes PascalCase)
  if (isComponent(iconExports[`${name}Icon`])) {
    return iconExports[`${name}Icon`]
  }
  if (isComponent(iconExports[name])) {
    return iconExports[name]
  }

  // 4. Try capitalizing first letter only (e.g., "database" → "Database")
  const capitalized = name.charAt(0).toUpperCase() + name.slice(1)
  if (isComponent(iconExports[`${capitalized}Icon`])) {
    return iconExports[`${capitalized}Icon`]
  }
  if (isComponent(iconExports[capitalized])) {
    return iconExports[capitalized]
  }

  return PhosphorIcons.DatabaseIcon
}

interface ResourceIconProps {
  iconName: string | null | undefined
  size?: number
  className?: string
  weight?: IconProps["weight"]
  color?: string
}

export function ResourceIcon({ iconName, size, className, weight, color }: ResourceIconProps) {
  const Icon = resolveIcon(iconName ?? "database")
  return <Icon size={size} className={className} weight={weight} color={color} />
}
