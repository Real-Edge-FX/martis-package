import { Chart } from 'primereact/chart'

interface TrendCardProps {
  data: Record<string, unknown>
}

export function TrendCard({ data }: TrendCardProps) {
  const labels = (data.labels as string[]) ?? []
  const values = (data.values as number[]) ?? []
  const latestValue = data.latestValue as number | undefined
  const sumValue = data.sumValue as number | undefined
  const prefix = (data.prefix as string) ?? ''
  const suffix = (data.suffix as string) ?? ''

  const displayValue = sumValue ?? latestValue
  const formattedDisplay = displayValue !== undefined
    ? `${prefix}${displayValue.toLocaleString()}${suffix}`
    : null

  const chartData = {
    labels,
    datasets: [
      {
        data: values,
        fill: true,
        borderColor: '#818cf8',
        backgroundColor: 'rgba(129, 140, 248, 0.2)',
        tension: 0.4,
        pointRadius: 2,
        pointHoverRadius: 5,
        pointBackgroundColor: '#818cf8',
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
          color: '#9ca3af',
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
          color: '#9ca3af',
          font: { size: 10 },
        },
        border: { color: 'rgba(128, 128, 128, 0.2)' },
        beginAtZero: true,
      },
    },
  }

  return (
    <div>
      {formattedDisplay && (
        <p
          className="text-2xl font-bold mb-3"
          style={{ color: 'var(--martis-text)' }}
        >
          {formattedDisplay}
        </p>
      )}
      <div style={{ height: 160 }}>
        <Chart type="line" data={chartData} options={chartOptions} />
      </div>
    </div>
  )
}
