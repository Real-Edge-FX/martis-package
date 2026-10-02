import { describe, expect, it, vi } from 'vitest'
import { render } from '@testing-library/react'
import { chartPalette } from '@/lib/themeColors'
import { PartitionCard } from './PartitionCard'

/*
 * Same root cause as the icon registry finding (F035): the group values of a
 * Partition metric are record data, and the label-keyed `colors()` map is a
 * plain object. `rawColors[label]` also finds what every object inherits, so
 * a group named `constructor` resolved to `Object`, `resolveColor` called
 * `.trim()` on it and the render threw, replacing the whole dashboard with the
 * error boundary for everyone who opened it.
 */

const charts: Array<{ data: { labels: string[]; datasets: Array<{ backgroundColor: string[] }> } }> = []

vi.mock('primereact/chart', () => ({
  Chart: (props: (typeof charts)[number]) => {
    charts.push(props)
    return null
  },
}))

const INHERITED = ['constructor', '__proto__', 'toString', 'valueOf', 'hasOwnProperty']

function renderedColors(labels: string[], colors: Record<string, string>): string[] {
  charts.length = 0
  render(<PartitionCard data={{ labels, values: labels.map(() => 1), colors }} />)
  return charts[charts.length - 1].data.datasets[0].backgroundColor
}

describe('PartitionCard with a group value spelled like an inherited member', () => {
  it.each(INHERITED)('renders a group named %s, with a palette color', (label) => {
    const colors = renderedColors([label, 'Paused'], { Active: '#22c55e', Paused: '#f59e0b' })

    expect(colors).toHaveLength(2)
    expect(colors[0]).toBe(chartPalette()[0])
    // The neighbour with an entry of its own keeps it.
    expect(colors[1]).toBe('#f59e0b')
  })

  it('keeps the color the map holds for a group of its own', () => {
    const colors = renderedColors(['Active', 'Paused'], { Active: '#22c55e', Paused: '#f59e0b' })

    expect(colors).toEqual(['#22c55e', '#f59e0b'])
  })

  it('uses the color of a map entry that is itself spelled like an inherited member', () => {
    const own = JSON.parse('{"constructor": "#123456"}') as Record<string, string>

    expect(renderedColors(['constructor'], own)).toEqual(['#123456'])
  })
})
