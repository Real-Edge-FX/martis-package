import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { act, render } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { TimezoneFieldInput } from './TimezoneField'

/*
 * Every option of the timezone input shows the zone's current time, and the
 * input moves that clock on once a minute while it is open. The minute used
 * to be a counter listed as a dependency of a memo that never read it (a
 * lint warning whose "fix" would have frozen the clock); the current time is
 * now the state the interval moves on. This pins the clock moving.
 */

const field = {
  attribute: 'timezone', label: 'Timezone', type: 'timezone',
  options: { Asia: ['Asia/Tokyo'], Europe: ['Europe/Lisbon'] },
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [],
} as unknown as FieldDefinition

describe('TimezoneFieldInput clock', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-01-15T12:00:00Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('moves the current time of the selected zone on every minute', () => {
    const { container } = render(<TimezoneFieldInput field={field} value="Asia/Tokyo" onChange={() => {}} />)
    const label = () => container.querySelector('.p-dropdown-label')?.textContent ?? ''
    expect(label()).toContain('Asia/Tokyo')
    expect(label()).toContain('21:00 (+09:00)')

    act(() => {
      vi.advanceTimersByTime(60_000)
    })
    expect(label()).toContain('21:01 (+09:00)')

    act(() => {
      vi.advanceTimersByTime(60_000)
    })
    expect(label()).toContain('21:02 (+09:00)')
  })
})
