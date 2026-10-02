import { beforeEach, describe, expect, it, vi } from 'vitest'

/*
 * F035 root cause: `PRIME_LOCALES[lang]` also finds what every object
 * inherits, and the locale is read from the browser's stored preferences. A
 * stored locale of `constructor` registered `Object` as a calendar locale and
 * became the active one.
 */

const addLocale = vi.fn()
const getLocale = vi.fn()

vi.mock('primereact/api', () => ({ addLocale: (...args: unknown[]) => addLocale(...args) }))
vi.mock('@/lib/i18n', () => ({ getLocale: () => getLocale() }))

async function calendarLocale(): Promise<string> {
  vi.resetModules()
  const mod = await import('./calendarLocale')
  return mod.getCalendarLocale()
}

describe('getCalendarLocale', () => {
  beforeEach(() => {
    addLocale.mockClear()
  })

  it.each(['constructor', '__proto__', 'toString', 'hasOwnProperty'])('stays on en for a stored locale spelled %s', async (stored) => {
    getLocale.mockReturnValue(stored)

    expect(await calendarLocale()).toBe('en')
    expect(addLocale).not.toHaveBeenCalled()
  })

  it('registers the calendar locale of a language the panel ships', async () => {
    getLocale.mockReturnValue('pt_PT')

    expect(await calendarLocale()).toBe('pt_PT')
    expect(addLocale).toHaveBeenCalledWith('pt_PT', expect.objectContaining({ firstDayOfWeek: 1 }))
  })
})
