import { Chart } from 'primereact/chart'

const DEFAULT_COLORS = [
  '#6366f1', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6',
  '#06b6d4', '#f97316', '#ec4899', '#14b8a6', '#a855f7',
]

interface PartitionCardProps {
  data: Record<string, unknown>
}

export function PartitionCard({ data }: PartitionCardProps) {
  const labels = (data.labels as string[]) ?? []
  const values = (data.values as number[]) ?? []
  const colors = (data.colors as string[]) ?? DEFAULT_COLORS.slice(0, labels.length)

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
          color: '#9ca3af',
          font: { size: 11 },
          padding: 12,
          usePointStyle: true,
          pointStyleWidth: 8,
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
