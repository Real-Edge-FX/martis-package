interface ProgressCardProps {
  data: Record<string, unknown>
}

export function ProgressCard({ data }: ProgressCardProps) {
  const current = (data.current as number) ?? 0
  const target = (data.target as number) ?? 1
  const percentage = (data.percentage as number) ?? 0
  const avoid = (data.avoid as boolean) ?? false
  const prefix = (data.prefix as string) ?? ''
  const suffix = (data.suffix as string) ?? ''

  const clampedPercentage = Math.min(100, Math.max(0, percentage))

  // Color: green when maximizing and close to target, red when avoiding
  const isGood = avoid ? percentage < 50 : percentage >= 50
  const barColor = isGood ? '#22c55e' : avoid ? '#ef4444' : '#f59e0b'

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
