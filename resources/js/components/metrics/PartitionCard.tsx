import { Chart } from 'primereact/chart'
import { chartPalette, mutedTextColor, resolveColor } from '@/lib/themeColors'

interface PartitionCardProps {
  data: Record<string, unknown>
}

export function PartitionCard({ data }: PartitionCardProps) {
  const labels = (data.labels as string[]) ?? []
  const values = (data.values as number[]) ?? []

  // The metric can supply colors in two formats:
  // 1. Array (parallel to labels): ['#fff', '#000', ...]
  // 2. Map (label => color): { 'Active': '#22c55e', 'Paused': '#f59e0b' }
  const rawColors = data.colors as string[] | Record<string, string> | undefined
  const palette = chartPalette()

  let colors: string[]
  if (Array.isArray(rawColors)) {
    colors = rawColors.map((c, i) => resolveColor(c, palette[i % palette.length]))
  } else if (rawColors && typeof rawColors === 'object') {
    colors = labels.map((label, i) => resolveColor(rawColors[label], palette[i % palette.length]))
  } else {
    colors = palette.slice(0, labels.length)
  }

  const chartData = {
    labels,
    datasets: [
      {
        data: values,
        backgroundColor: colors,
        borderWidth: 0,
        hoverOffset: 4,
      },
    ],
  }

  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'bottom' as const,
        labels: {
          color: mutedTextColor(),
          font: { size: 11 },
          padding: 12,
          usePointStyle: true,
          pointStyle: 'circle',
          boxWidth: 8,
          boxHeight: 8,
        },
      },
    },
    cutout: '60%',
  }

  return (
    <div style={{ position: 'relative', width: '100%', maxHeight: 250 }}>
      <Chart type="doughnut" data={chartData} options={chartOptions} style={{ maxHeight: 250 }} />
    </div>
  )
}
