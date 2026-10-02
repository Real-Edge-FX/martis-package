import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { SparklineFieldDisplay, SparklineFieldInput } from './SparklineField'

/*
 * Security regression (F081): the chart spread the whole stored array into
 * `Math.min(...data)` / `Math.max(...data)`, which throws a RangeError once
 * the array passes about 125,000 elements. A user who could save that many
 * numbers made every page that renders the record fail for everyone who
 * opened it. The extremes come from a loop now, non-numeric and non-finite
 * entries are skipped, and a series above a few hundred points is
 * downsampled to what the chart can draw.
 */

const MAX_POINTS = 300

function field(extra: Record<string, unknown> = {}): FieldDefinition {
  return {
    attribute: 'trend', label: 'Trend', type: 'sparkline',
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: true, showOnDetail: true, showOnForms: true,
    rules: [], ...extra,
  } as unknown as FieldDefinition
}

const polylinePoints = (container: HTMLElement): string[] =>
  (container.querySelector('polyline')?.getAttribute('points') ?? '').split(' ').filter((point) => point !== '')

const ramp = (length: number): number[] => Array.from({ length }, (_, index) => index)

describe('SparklineFieldDisplay with a huge series', () => {
  it('renders 200,000 points without throwing, and draws at most a few hundred', () => {
    const { container } = render(<SparklineFieldDisplay field={field()} value={ramp(200_000)} />)

    const points = polylinePoints(container)
    expect(points.length).toBeGreaterThan(1)
    expect(points.length).toBeLessThanOrEqual(MAX_POINTS)
    expect(container.innerHTML).not.toContain('NaN')
  })

  it('draws at most a few hundred bars of a huge bar chart', () => {
    const { container } = render(<SparklineFieldDisplay field={field({ chartType: 'bar' })} value={ramp(200_000)} />)

    const bars = container.querySelectorAll('rect')
    expect(bars.length).toBeGreaterThan(1)
    expect(bars.length).toBeLessThanOrEqual(MAX_POINTS)
    expect(container.innerHTML).not.toContain('NaN')
  })

  it('keeps the shape of the series when it downsamples: the trend still rises', () => {
    const { container } = render(<SparklineFieldDisplay field={field({ chartHeight: 30 })} value={ramp(50_000)} />)

    const ys = polylinePoints(container).map((point) => Number(point.split(',')[1]))
    // SVG y grows downwards: a rising series draws a falling y.
    expect(ys[0]).toBeGreaterThan(ys[ys.length - 1])
    expect(ys.every((y) => Number.isFinite(y))).toBe(true)
  })
})

describe('SparklineFieldDisplay entries that are not numbers', () => {
  it('skips null, strings, NaN and Infinity instead of drawing NaN', () => {
    const { container } = render(<SparklineFieldDisplay field={field()} value={[1, null, 'x', Number.NaN, Number.POSITIVE_INFINITY, 3, undefined, 5]} />)

    expect(polylinePoints(container)).toHaveLength(3)
    expect(container.innerHTML).not.toContain('NaN')
    expect(container.innerHTML).not.toContain('Infinity')
  })

  it('shows the dash when no entry is a finite number', () => {
    const { container } = render(<SparklineFieldDisplay field={field()} value={[null, 'x', Number.NaN]} />)

    expect(container.querySelector('svg')).toBeNull()
    expect(container.textContent).toBe('—')
  })
})

describe('SparklineFieldDisplay small series are unchanged', () => {
  it('draws every point of a short line series, spread over the width', () => {
    const { container } = render(<SparklineFieldDisplay field={field({ chartWidth: 104, chartHeight: 24 })} value={[0, 10, 20]} />)

    expect(polylinePoints(container)).toEqual(['2,22', '52,12', '102,2'])
  })

  it('draws one bar per value of a short bar series', () => {
    const { container } = render(<SparklineFieldDisplay field={field({ chartType: 'bar' })} value={[1, 2, 3, 4]} />)

    expect(container.querySelectorAll('rect')).toHaveLength(4)
  })

  it('draws a single flat point without dividing by zero', () => {
    const { container } = render(<SparklineFieldDisplay field={field()} value={[7]} />)

    expect(container.innerHTML).not.toContain('NaN')
    expect(polylinePoints(container)).toHaveLength(1)
  })
})

describe('SparklineFieldInput with a huge series', () => {
  it('renders the edit form of a record that stores 200,000 points', () => {
    const { container } = render(<SparklineFieldInput field={field()} value={ramp(200_000)} onChange={() => {}} />)

    expect(container.textContent).toContain('200000 values')
    expect(container.querySelector('polyline')).not.toBeNull()
  })
})
