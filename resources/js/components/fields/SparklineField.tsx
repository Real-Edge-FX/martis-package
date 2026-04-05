import React, { useMemo, useState, useEffect } from "react"
import type { FieldDisplayProps, FieldInputProps } from "./types"

interface SparklineExt {
  chartType?: "line" | "bar"
  chartHeight?: number
  chartWidth?: number
  chartColor?: string
}

function getExt(field: Record<string, unknown>): SparklineExt {
  return field as unknown as SparklineExt
}

function SparklineChart({ data, ext }: { data: number[]; ext: SparklineExt }) {
  const chartType = ext.chartType ?? "line"
  const height = ext.chartHeight ?? 30
  const width = ext.chartWidth ?? 120
  const color = ext.chartColor ?? "#6366f1"

  const normalized = useMemo(() => {
    if (data.length === 0) return []
    const min = Math.min(...data)
    const max = Math.max(...data)
    const range = max - min || 1
    return data.map((v) => ((v - min) / range) * (height - 4) + 2)
  }, [data, height])

  if (data.length === 0) {
    return <span className="text-gray-400 dark:text-gray-500">{"\u2014"}</span>
  }

  if (chartType === "bar") {
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

  const stepX = data.length > 1 ? (width - 4) / (data.length - 1) : 0
  const points = normalized.map((y, i) => `${2 + i * stepX},${height - y}`).join(" ")

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

  // Use local state for the raw text so the user can freely type/edit
  const [rawText, setRawText] = useState(() => JSON.stringify(data))
  const [parseError, setParseError] = useState<string | null>(null)

  // Sync raw text when external value changes (e.g. initial load)
  useEffect(() => {
    setRawText(JSON.stringify(Array.isArray(value) ? value : []))
  }, [value])

  const handleChange = (e: React.ChangeEvent<HTMLTextAreaElement>) => {
    const text = e.target.value
    setRawText(text)

    const trimmed = text.trim()
    if (!trimmed) {
      onChange([])
      setParseError(null)
      return
    }
    try {
      const parsed = JSON.parse(trimmed)
      if (Array.isArray(parsed) && parsed.every((v: unknown) => typeof v === "number")) {
        onChange(parsed)
        setParseError(null)
      } else {
        setParseError("Must be a JSON array of numbers")
      }
    } catch {
      setParseError("Invalid JSON")
    }
  }

  return (
    <div className="flex flex-col gap-2">
      <div className="flex items-center py-1">
        <SparklineChart data={data} ext={ext} />
        <span className="ml-3 text-xs martis-text-muted">
          {data.length} values
        </span>
      </div>
      <textarea
        name={field.attribute}
        value={rawText}
        onChange={handleChange}
        disabled={field.readonly}
        rows={3}
        placeholder="[1, 2, 3, 4, 5]"
        className="w-full rounded-md text-sm font-mono"
        style={{
          backgroundColor: "var(--martis-input-bg)",
          color: "var(--martis-text)",
          border: `1px solid ${parseError ? "#ef4444" : "var(--martis-border)"}`,
          padding: "0.5rem 0.75rem",
        }}
      />
      {parseError && <small className="text-amber-500">{parseError}</small>}
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
