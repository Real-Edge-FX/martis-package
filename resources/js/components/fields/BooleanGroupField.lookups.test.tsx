import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { BooleanGroupFieldDisplay } from './BooleanGroupField'

/*
 * F035 root cause: the stored value of a boolean group is a plain object, and
 * `value[key]` also finds what every object inherits. An option keyed
 * `constructor` read `Object` (truthy) and showed as ticked for a record that
 * never set it.
 */

const field = (options: Record<string, string>): FieldDefinition =>
  ({ attribute: 'flags', label: 'Flags', type: 'boolean_group', options, nullable: true, rules: [] }) as unknown as FieldDefinition

describe('BooleanGroupFieldDisplay with an option keyed like an inherited member', () => {
  it.each(['constructor', 'toString', 'valueOf', 'hasOwnProperty'])('shows %s as off when the value holds no entry of its own', (key) => {
    const { container } = render(<BooleanGroupFieldDisplay field={field(JSON.parse(`{"${key}": "Flag", "on": "On"}`))} value={{ on: true }} />)

    const pills = Array.from(container.querySelectorAll('.martis-boolgroup-pill'))
    expect(pills.map((p) => p.classList.contains('is-on'))).toEqual([false, true])
  })

  it('shows an own entry of the value, whatever the key is spelled like', () => {
    const { container } = render(
      <BooleanGroupFieldDisplay field={field(JSON.parse('{"constructor": "Flag"}'))} value={JSON.parse('{"constructor": true}')} />,
    )

    expect(container.querySelector('.martis-boolgroup-pill')?.classList.contains('is-on')).toBe(true)
  })
})
