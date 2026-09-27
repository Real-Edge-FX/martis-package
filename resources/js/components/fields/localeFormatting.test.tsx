import { describe, it, expect, afterEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import i18n from 'i18next'
import { DateFieldDisplay } from './DateField'
import { DateTimeFieldDisplay } from './DateTimeField'
import { CurrencyFieldDisplay, CurrencyFieldInput } from './CurrencyField'
import { formatAggregate } from './HasOneField'
import type { FieldDefinition } from '@/types'

const field = (extra: Record<string, unknown> = {}) =>
  ({ attribute: 'value', ...extra }) as unknown as FieldDefinition

afterEach(async () => {
  await i18n.changeLanguage('en')
})

// Each case runs in two locales: whichever one the test runtime defaults
// to, the other proves the output follows the Martis locale.
const LOCALES: Array<[string, string]> = [['pt_PT', 'pt-PT'], ['en_US', 'en-US']]

describe.each(LOCALES)('with the Martis locale %s', (martisLocale, tag) => {
  it('formats a Date field value', async () => {
    await i18n.changeLanguage(martisLocale)
    render(<DateFieldDisplay field={field()} value="2026-09-27" />)

    expect(screen.getByText(new Date(2026, 8, 27).toLocaleDateString(tag))).toBeTruthy()
  })

  it('formats a DateTime field value', async () => {
    await i18n.changeLanguage(martisLocale)
    const iso = '2026-09-27T14:05:00Z'
    render(<DateTimeFieldDisplay field={field()} value={iso} />)

    expect(screen.getByText(new Date(iso).toLocaleString(tag))).toBeTruthy()
  })

  it('formats a Currency field value', async () => {
    await i18n.changeLanguage(martisLocale)
    render(<CurrencyFieldDisplay field={field({ currencySymbol: '€', currencyDecimals: 2 })} value={1234.5} />)
    const amount = new Intl.NumberFormat(tag, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(1234.5)

    expect(screen.getByText(`€ ${amount}`)).toBeTruthy()
  })

  it('displays a Currency field in the locale its locale() sets', async () => {
    await i18n.changeLanguage(martisLocale)
    render(<CurrencyFieldDisplay field={field({ currencySymbol: '€', currencyDecimals: 2, locale: 'de_DE' })} value={1234.5} />)

    expect(screen.getByText('€ 1.234,50')).toBeTruthy()
  })

  it('edits a Currency field in the locale its locale() sets', async () => {
    await i18n.changeLanguage(martisLocale)
    render(<CurrencyFieldInput field={field({ currencySymbol: '€', currencyDecimals: 2, locale: 'de_DE' })} value={1234.5} onChange={() => {}} />)

    expect((document.getElementById('value') as HTMLInputElement).value).toBe('€ 1.234,50')
  })
})

describe('formatAggregate', () => {
  it('formats a money-like column as a plain number, with no currency guessed', () => {
    const out = formatAggregate({ fn: 'sum', column: 'amount', value: 1234.5 }, 'pt-PT')

    expect(out).toBe(new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 2 }).format(1234.5))
    expect(out).not.toContain('€')
  })

  it('keeps a count as a rounded integer', () => {
    expect(formatAggregate({ fn: 'count', column: '*', value: 12.4 }, 'pt-PT')).toBe('12')
  })

  it('shows a dash for a null value', () => {
    expect(formatAggregate({ fn: 'avg', column: 'total', value: null }, 'pt-PT')).toBe('—')
  })
})
