import { describe, expect, it } from 'vitest'
import { EMAIL_CHANGE_FALLBACKS, emailChangeOutcome } from './emailChangeOutcome'

describe('emailChangeOutcome', () => {
  it.each(['changed', 'invalid', 'rejected'])('reads ?email_change=%s', (outcome) => {
    expect(emailChangeOutcome(`?email_change=${outcome}`)).toBe(outcome)
    expect(emailChangeOutcome(`?a=1&email_change=${outcome}`)).toBe(outcome)
  })

  it('reads nothing from another value, none or an empty string', () => {
    expect(emailChangeOutcome('?email_change=whatever')).toBeNull()
    expect(emailChangeOutcome('?email_change=')).toBeNull()
    expect(emailChangeOutcome('?other=changed')).toBeNull()
    expect(emailChangeOutcome('')).toBeNull()
  })

  it('has a wording for every outcome', () => {
    expect(Object.keys(EMAIL_CHANGE_FALLBACKS).sort()).toEqual(['changed', 'invalid', 'rejected'])
  })
})
