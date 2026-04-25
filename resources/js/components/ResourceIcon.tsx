import type { IconProps } from '@phosphor-icons/react'
import { iconRegistry } from '@/lib/iconRegistry'

interface ResourceIconProps {
  iconName: string | null | undefined
  size?: number
  className?: string
  weight?: IconProps['weight']
  color?: string
}

/**
 * Render a Phosphor icon by name. Backed by `iconRegistry` (curated set
 * of icons that get tree-shaken at build time, ~250 KB instead of the
 * ~5 MB whole-namespace import that lived here pre-v0.8). Unknown
 * names fall back to `DatabaseIcon` so the page never crashes on a
 * typo. Consumer apps register additional icons via
 * `iconRegistry.register('my-icon', MyIconComponent)` at boot.
 */
export function ResourceIcon({ iconName, size, className, weight, color }: ResourceIconProps) {
  const Icon = iconRegistry.resolve(iconName)
  return <Icon size={size} className={className} weight={weight} color={color} />
}
