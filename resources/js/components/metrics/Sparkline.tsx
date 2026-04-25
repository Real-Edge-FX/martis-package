import { useMemo } from 'react'

interface SparklineProps {
  /** Numeric series to plot. Empty or single-value arrays render nothing. */
  values: number[]
  /** Stroke + gradient colour. Accepts any CSS colour or token. */
  color?: string
  /** Preset aspect. `inline` fits next to a KPI value; `block` fills width. */
  variant?: 'inline' | 'block'
  /** Optional aria-label for the SVG. */
  label?: string
}

/**
 * Tiny area sparkline. Renders a filled path with a 0.35 → 0 vertical
 * gradient behind a 1.5px stroke. Matches the design-system `<Spark>`
 * helper in Dashboard.html — kept intentionally simple so it can drop
 * into the TrendCard sparkline mode (F7-17) without pulling Chart.js.
 */
export function Sparkline({ values, color = 'var(--martis-accent)', variant = 'block', label }: SparklineProps) {
  const gradientId = useMemo(
    () => `martis-sparkline-${Math.random().toString(36).slice(2, 8)}`,
    [],
  )

  if (values.length < 2) {
    return null
  }

  const W = 100
  const H = variant === 'inline' ? 28 : 36
  const max = Math.max(...values)
  const min = Math.min(...values)
  const range = max - min || 1

  const points = values.map((v, i) => {
    const x = (i / (values.length - 1)) * W
    const y = H - ((v - min) / range) * H
    return [x, y] as const
  })
  const line = points
    .map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`)
    .join(' ')
  const area = `${line} L${W},${H} L0,${H} Z`

  const className = variant === 'inline' ? 'martis-sparkline martis-sparkline-inline' : 'martis-sparkline'

  return (
    <svg
      className={className}
      viewBox={`0 0 ${W} ${H}`}
      preserveAspectRatio="none"
      role="img"
      aria-label={label}
    >
      <defs>
        <linearGradient id={gradientId} x1="0" x2="0" y1="0" y2="1">
          <stop offset="0%" stopColor={color} stopOpacity="0.35" />
          <stop offset="100%" stopColor={color} stopOpacity="0" />
        </linearGradient>
      </defs>
      <path d={area} fill={`url(#${gradientId})`} />
      <path
        d={line}
        fill="none"
        stroke={color}
        strokeWidth="1.5"
        strokeLinejoin="round"
        strokeLinecap="round"
      />
    </svg>
  )
}
