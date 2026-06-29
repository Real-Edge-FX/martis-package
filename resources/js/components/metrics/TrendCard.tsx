import { Chart } from 'primereact/chart'
import { accentColor as getAccent, mutedTextColor, resolveColor } from '@/lib/themeColors'
import { Sparkline } from './Sparkline'

interface TrendCardProps {
  data: Record<string, unknown>
  color?: string | null
}

export function TrendCard({ data, color }: TrendCardProps) {
  const labels = (data.labels as string[]) ?? []
  const values = (data.values as number[]) ?? []
  const latestValue = data.latestValue as number | undefined
  const sumValue = data.sumValue as number | undefined
  const prefix = (data.prefix as string) ?? ''
  const suffix = (data.suffix as string) ?? ''
  // F7-17 — backend opt-in: `sparkline: true` on a TrendMetric renders a
  // compact SVG line next to the value instead of the full Chart.js panel.
  const sparkline = data.sparkline === true
  const change = data.change as number | undefined

  const displayValue = sumValue ?? latestValue
  const formattedDisplay = displayValue !== undefined
    ? `${prefix}${displayValue.toLocaleString()}${suffix}`
    : null

  // Use developer-provided color or fall back to theme accent
  const lineColor = color ? resolveColor(color, getAccent()) : getAccent()
  const tickColor = mutedTextColor()

  const chartData = {
    labels,
    datasets: [
      {
        data: values,
        fill: true,
        borderColor: lineColor,
        backgroundColor: lineColor + '33',
        tension: 0.4,
        pointRadius: 2,
        pointHoverRadius: 5,
        pointBackgroundColor: lineColor,
        borderWidth: 2.5,
      },
    ],
  }

  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
    },
    scales: {
      x: {
        display: true,
        grid: { display: false },
        ticks: {
          color: tickColor,
          font: { size: 10 },
          maxRotation: 0,
          maxTicksLimit: 7,
        },
        border: { color: 'rgba(128, 128, 128, 0.2)' },
      },
      y: {
        display: true,
        grid: {
          color: 'rgba(128, 128, 128, 0.15)',
        },
        ticks: {
          color: tickColor,
          font: { size: 10 },
        },
        border: { color: 'rgba(128, 128, 128, 0.2)' },
        beginAtZero: true,
      },
    },
  }

  // F7-17 — sparkline mode: inline KPI value + trend delta + 28px chart.
  // No axis, no legend, no ticks. Used when the metric is shown on a
  // compact dashboard row (`.martis-dash-kpis`).
  if (sparkline) {
    const trend = (change ?? 0) > 0 ? 'up' : (change ?? 0) < 0 ? 'down' : 'flat'
    const deltaClass =
      trend === 'up' ? 'martis-kpi-delta is-up'
      : trend === 'down' ? 'martis-kpi-delta is-down'
      : 'martis-kpi-delta'

    return (
      <div>
        {formattedDisplay && <p className="martis-kpi-value">{formattedDisplay}</p>}
        <div className="mt-2 flex items-end justify-between gap-3">
          {change != null ? (
            <span className={deltaClass}>
              {change > 0 ? '+' : ''}{change}%
            </span>
          ) : (
            <span />
          )}
          <Sparkline values={values} color={lineColor} variant="inline" />
        </div>
      </div>
    )
  }

  return (
    <div>
      {formattedDisplay && (
        <p className="martis-kpi-value mb-3">{formattedDisplay}</p>
      )}
      <div style={{ height: 160 }}>
        <Chart type="line" data={chartData} options={chartOptions} />
      </div>
    </div>
  )
}
