import { describe, it, expect, afterEach } from 'vitest'
import { act, render, screen } from '@testing-library/react'
import i18n from 'i18next'
import { toBcp47, currentFormatLocale, useFormatLocale } from './formatLocale'

afterEach(async () => {
  await i18n.changeLanguage('en')
})

describe('toBcp47', () => {
  it.each([
    ['pt_PT', 'pt-PT'],
    ['pt_BR', 'pt-BR'],
    ['en_GB', 'en-GB'],
    ['en', 'en'],
    [' pt_PT ', 'pt-PT'],
  ])('maps %s to %s', (input, expected) => {
    expect(toBcp47(input)).toBe(expected)
  })

  it.each([[''], [null], [undefined], ['not a locale!']])('returns undefined for %s', (input) => {
    expect(toBcp47(input as string | null | undefined)).toBeUndefined()
  })
})

describe('currentFormatLocale', () => {
  it('follows the i18next language', async () => {
    await i18n.changeLanguage('pt_PT')

    expect(currentFormatLocale()).toBe('pt-PT')
  })
})

function Probe() {
  return <span data-testid="locale">{useFormatLocale() ?? 'default'}</span>
}

describe('useFormatLocale', () => {
  it('re-renders with the new locale when the language changes', async () => {
    await i18n.changeLanguage('en_US')
    render(<Probe />)
    expect(screen.getByTestId('locale').textContent).toBe('en-US')

    await act(async () => {
      await i18n.changeLanguage('pt_PT')
    })

    expect(screen.getByTestId('locale').textContent).toBe('pt-PT')
  })
})
