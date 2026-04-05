import React, { useMemo } from 'react'
import type { FieldDisplayProps, FieldInputProps } from './types'

interface SparklineExt {
  chartType?: 'line' | 'bar'
  chartHeight?: number
  chartWidth?: number
  chartColor?: string
}

function getExt(field: Record<string, unknown>): SparklineExt {
  return field as unknown as SparklineExt
}

function SparklineChart({ data, ext }: { data: number[]; ext: SparklineExt }) {
  const chartType = ext.chartType ?? 'line'
  const height = ext.chartHeight ?? 30
  const width = ext.chartWidth ?? 120
  const color = ext.chartColor ?? '#6366f1'

  const normalized = useMemo(() => {
    if (data.length === 0) return []
    const min = Math.min(...data)
    const max = Math.max(...data)
    const range = max - min || 1
    return data.map((v) => ((v - min) / range) * (height - 4) + 2)
  }, [data, height])

  if (data.length === 0) {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }

  if (chartType === 'bar') {
    const barWidth = Math.max(2, (width - data.length + 1) / data.length)
    const gap = 1

    return (
      <svg width={width} height={height} className="inline-block align-middle">
        {normalized.map((h, i) => (
          <rect
            key={i}
            x={i * (barWidth + gap)}
            y={height - h}
            width={barWidth}
            height={h}
            fill={color}
            rx={1}
          />
        ))}
      </svg>
    )
  }

  // Line chart
  const stepX = data.length > 1 ? (width - 4) / (data.length - 1) : 0
  const points = normalized.map((y, i) => `${2 + i * stepX},${height - y}`).join(' ')

  return (
    <svg width={width} height={height} className="inline-block align-middle">
      <polyline
        points={points}
        fill="none"
        stroke={color}
        strokeWidth={1.5}
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  )
}

export function SparklineFieldDisplay({ field, value }: FieldDisplayProps) {
  const ext = getExt(field as unknown as Record<string, unknown>)
  const data = Array.isArray(value) ? (value as number[]) : []

  return <SparklineChart data={data} ext={ext} />
}

export function SparklineFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const ext = getExt(field as unknown as Record<string, unknown>)
  const data = Array.isArray(value) ? (value as number[]) : []

  const textValue = useMemo(() => {
    return JSON.stringify(data)
  }, [data])

  const handleChange = (e: React.ChangeEvent<HTMLTextAreaElement>) => {
    const raw = e.target.value.trim()
    if (!raw) {
      onChange([])
      return
    }
    try {
      const parsed = JSON.parse(raw)
      if (Array.isArray(parsed)) {
        onChange(parsed)
      }
    } catch {
      // invalid JSON - ignore
    }
  }

  return (
    <div className="flex flex-col gap-2">
      <div className="flex items-center py-1">
        <SparklineChart data={data} ext={ext} />
      </div>
      <textarea
        name={field.attribute}
        value={textValue}
        onChange={handleChange}
        disabled={field.readonly}
        rows={2}
        placeholder="[1, 2, 3, 4, 5]"
        className="w-full rounded-md text-sm font-mono"
        style={{
          backgroundColor: "var(--martis-input-bg)",
          color: "var(--martis-text)",
          border: "1px solid var(--martis-border)",
          padding: "0.5rem 0.75rem",
        }}
      />
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
