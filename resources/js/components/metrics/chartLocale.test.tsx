import { describe, it, expect, afterEach, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import i18n from 'i18next'

/*
 * Chart.js formats axis ticks and tooltip values itself, through
 * `Intl.NumberFormat(chart.options.locale)`. Without `locale` in the options
 * they follow the browser, so a pt_PT user read `1,234.5` in the chart under
 * a `1234,5` headline (PR #276 review).
 */

const charts: Array<{ type?: string; options?: Record<string, unknown> }> = []

vi.mock('primereact/chart', () => ({
  Chart: (props: { type?: string; options?: Record<string, unknown> }) => {
    charts.push(props)
    return null
  },
}))

function last() {
  return charts[charts.length - 1]
}

import { TrendCard } from './TrendCard'
import { PartitionCard } from './PartitionCard'

afterEach(async () => {
  charts.length = 0
  await i18n.changeLanguage('en')
})

const LOCALES: Array<[string, string]> = [['pt_PT', 'pt-PT'], ['en_US', 'en-US'], ['en_GB', 'en-GB']]

describe.each(LOCALES)('with the Martis locale %s', (martisLocale, tag) => {
  it('gives the trend chart the Martis locale, as its headline', async () => {
    await i18n.changeLanguage(martisLocale)
    render(<TrendCard data={{ labels: ['Aug', 'Sep'], values: [1000, 1234.5], latestValue: 1234.5 }} />)

    expect(screen.getByText(new Intl.NumberFormat(tag).format(1234.5))).toBeTruthy()
    expect(last()?.type).toBe('line')
    expect(last()?.options).toMatchObject({ locale: tag, responsive: true })
  })

  it('gives the partition chart the Martis locale', async () => {
    await i18n.changeLanguage(martisLocale)
    render(<PartitionCard data={{ labels: ['A', 'B'], values: [1234.5, 10] }} />)

    expect(last()?.type).toBe('doughnut')
    expect(last()?.options).toMatchObject({ locale: tag, cutout: '60%' })
  })
})
