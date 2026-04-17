interface ProgressCardProps {
  data: Record<string, unknown>
  color?: string | null
}

export function ProgressCard({ data, color }: ProgressCardProps) {
  const current = (data.current as number) ?? 0
  const target = (data.target as number) ?? 1
  const percentage = (data.percentage as number) ?? 0
  const avoid = (data.avoid as boolean) ?? false
  const prefix = (data.prefix as string) ?? ''
  const suffix = (data.suffix as string) ?? ''

  const clampedPercentage = Math.min(100, Math.max(0, percentage))

  // If developer specified a custom color, use it (regardless of progress);
  // otherwise use semantic color based on direction
  let barColor: string
  if (color) {
    barColor = color
  } else {
    const isGood = avoid ? percentage < 50 : percentage >= 50
    barColor = isGood ? 'var(--martis-success)' : avoid ? 'var(--martis-danger)' : 'var(--martis-warning)'
  }

  return (
    <div>
      <div className="flex items-baseline justify-between mb-2">
        <p
          className="text-2xl font-bold"
          style={{ color: 'var(--martis-text)' }}
        >
          {prefix}{current.toLocaleString()}{suffix}
        </p>
        <p
          className="text-sm"
          style={{ color: 'var(--martis-text-muted)' }}
        >
          / {prefix}{target.toLocaleString()}{suffix}
        </p>
      </div>

      {/* Progress bar */}
      <div
        className="w-full rounded-full h-3"
        style={{ backgroundColor: 'var(--martis-hover)' }}
      >
        <div
          className="h-3 rounded-full transition-all duration-500"
          style={{
            width: `${clampedPercentage}%`,
            backgroundColor: barColor,
          }}
        />
      </div>

      <p
        className="mt-1.5 text-xs text-right"
        style={{ color: 'var(--martis-text-muted)' }}
      >
        {clampedPercentage}%
      </p>
    </div>
  )
}
