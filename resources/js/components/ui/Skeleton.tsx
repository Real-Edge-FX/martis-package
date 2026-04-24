import type { CSSProperties } from 'react'

interface SkeletonProps {
  /** Fixed width — number is treated as pixels. */
  width?: number | string
  /** Fixed height — number is treated as pixels. Defaults to 12px for text lines. */
  height?: number | string
  /** Corner radius override. Defaults to `var(--martis-radius-sm)` from the class. */
  radius?: number | string
  /** Render as an inline element (for inline text placeholders). */
  inline?: boolean
  /** Extra className appended after `.martis-skeleton`. */
  className?: string
  /** Inline style overrides (merged after the computed dimensions). */
  style?: CSSProperties
}

function sizeToken(value: number | string | undefined): string | undefined {
  if (value === undefined) return undefined
  return typeof value === 'number' ? `${value}px` : value
}

/**
 * Shimmering placeholder bar matching Catalog `.skeleton` spec. Use in a
 * loading state before a real element settles in. Honours reduced-motion
 * both from the OS and the user-level preference — the gradient freezes
 * but stays visible so the layout still reads as "not yet loaded".
 */
export function Skeleton({
  width,
  height,
  radius,
  inline,
  className,
  style,
}: SkeletonProps = {}) {
  const cls = className ? `martis-skeleton ${className}` : 'martis-skeleton'
  const computed: CSSProperties = {
    display: inline ? 'inline-block' : undefined,
    width: sizeToken(width),
    height: sizeToken(height) ?? '12px',
    borderRadius: sizeToken(radius),
    ...style,
  }
  return <span className={cls} style={computed} aria-hidden="true" />
}
