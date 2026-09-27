import { describe, it, expect, afterEach } from 'vitest'
import { act, render, screen } from '@testing-library/react'
import i18n from 'i18next'
import { ValueCard } from './ValueCard'
import { TrendCard } from './TrendCard'
import { ProgressCard } from './ProgressCard'

afterEach(async () => {
  await i18n.changeLanguage('en')
})

const LOCALES: Array<[string, string]> = [['pt_PT', 'pt-PT'], ['en_US', 'en-US']]

describe.each(LOCALES)('with the Martis locale %s', (martisLocale, tag) => {
  it('formats a value metric and its previous value', async () => {
    await i18n.changeLanguage(martisLocale)
    render(<ValueCard data={{ value: 1234.5, previous: 1000.25, change: 23 }} />)

    expect(screen.getByText(new Intl.NumberFormat(tag).format(1234.5))).toBeTruthy()
    expect(screen.getByText((content) => content.includes(new Intl.NumberFormat(tag).format(1000.25)))).toBeTruthy()
  })

  it('formats a trend metric total', async () => {
    await i18n.changeLanguage(martisLocale)
    render(<TrendCard data={{ values: [1, 2], sumValue: 1234.5, sparkline: true }} />)

    expect(screen.getByText(new Intl.NumberFormat(tag).format(1234.5))).toBeTruthy()
  })

  it('formats a progress metric', async () => {
    await i18n.changeLanguage(martisLocale)
    render(<ProgressCard data={{ current: 1234.5, target: 5000, percentage: 25 }} />)

    expect(screen.getByText(new Intl.NumberFormat(tag).format(1234.5))).toBeTruthy()
  })
})

describe('a language switch', () => {
  it('re-formats a rendered metric without a reload', async () => {
    await i18n.changeLanguage('en_US')
    render(<ValueCard data={{ value: 1234.5 }} />)
    expect(screen.getByText(new Intl.NumberFormat('en-US').format(1234.5))).toBeTruthy()

    await act(async () => {
      await i18n.changeLanguage('pt_PT')
    })

    expect(screen.getByText(new Intl.NumberFormat('pt-PT').format(1234.5))).toBeTruthy()
  })
})
