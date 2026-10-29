import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import { FieldWrapper } from './FieldWrapper'

describe('FieldWrapper help wiring', () => {
  it('points the control at the help line through aria-describedby', () => {
    const { container } = render(
      <FieldWrapper htmlFor="name" label="Name" help="Your full name">
        <input id="name" />
      </FieldWrapper>,
    )
    const help = container.querySelector('.martis-input-help') as HTMLElement
    expect(help.id).not.toBe('')
    expect(container.querySelector('#name')?.getAttribute('aria-describedby')).toBe(help.id)
  })

  it('keeps the tokens the control already has and gives each wrapper its own id', () => {
    const { container } = render(
      <>
        <FieldWrapper htmlFor="a" label="A" help="one">
          <input id="a" aria-describedby="extra" />
        </FieldWrapper>
        <FieldWrapper htmlFor="b" label="B" help="two">
          <input id="b" />
        </FieldWrapper>
      </>,
    )
    const [h1, h2] = Array.from(container.querySelectorAll('.martis-input-help')) as HTMLElement[]
    expect(h1.id).not.toBe(h2.id)
    expect(container.querySelector('#a')?.getAttribute('aria-describedby')).toBe(`extra ${h1.id}`)
  })

  it('falls back to the first control when no id matches and drops the token with the help', () => {
    const { container, rerender } = render(
      <FieldWrapper label="Bio" help="Short">
        <textarea />
      </FieldWrapper>,
    )
    const area = container.querySelector('textarea') as HTMLElement
    expect(area.getAttribute('aria-describedby')).toBe((container.querySelector('.martis-input-help') as HTMLElement).id)

    rerender(
      <FieldWrapper label="Bio">
        <textarea />
      </FieldWrapper>,
    )
    expect(area.hasAttribute('aria-describedby')).toBe(false)
  })
})
