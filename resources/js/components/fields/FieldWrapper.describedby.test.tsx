import { describe, expect, it, vi } from 'vitest'
import { act, render, waitFor } from '@testing-library/react'
import { useState } from 'react'
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

  it('skips a hidden input and describes the control after it', () => {
    const { container } = render(
      <FieldWrapper label="Name" help="Help">
        <input type="hidden" name="name" />
        <input id="visible" />
      </FieldWrapper>,
    )
    expect(container.querySelector('input[type="hidden"]')?.hasAttribute('aria-describedby')).toBe(false)
    expect(container.querySelector('#visible')?.hasAttribute('aria-describedby')).toBe(true)
  })

  it('describes the trigger button of a picker', () => {
    const { container } = render(
      <FieldWrapper htmlFor="owner" label="Owner" help="Pick one">
        <button type="button" className="martis-belongs-to-trigger">Pick</button>
      </FieldWrapper>,
    )
    expect(container.querySelector('button')?.getAttribute('aria-describedby')).toBe((container.querySelector('.martis-input-help') as HTMLElement).id)
  })

  it('binds a control that mounts after the wrapper', async () => {
    function Late() {
      const [ready, setReady] = useState(false)
      return (
        <>
          <button type="button" onClick={() => setReady(true)}>show</button>
          <FieldWrapper label="Late" help="Help">{ready ? <input id="late" /> : <span />}</FieldWrapper>
        </>
      )
    }
    const { container } = render(<Late />)
    act(() => { (container.querySelector('button') as HTMLElement).click() })

    await waitFor(() => expect(container.querySelector('#late')?.hasAttribute('aria-describedby')).toBe(true))
  })

  it('puts the token back when a re-render rewrites the control aria-describedby', async () => {
    function Rewrites() {
      const [extra, setExtra] = useState('a')
      return (
        <>
          <button type="button" onClick={() => setExtra('b')}>change</button>
          <FieldWrapper htmlFor="x" label="X" help="Help"><input id="x" aria-describedby={extra} /></FieldWrapper>
        </>
      )
    }
    const { container } = render(<Rewrites />)
    const help = container.querySelector('.martis-input-help') as HTMLElement
    expect(container.querySelector('#x')?.getAttribute('aria-describedby')).toBe(`a ${help.id}`)

    act(() => { (container.querySelector('button') as HTMLElement).click() })

    await waitFor(() => expect(container.querySelector('#x')?.getAttribute('aria-describedby')).toBe(`b ${help.id}`))
  })

  it('does not look the control up again when only its children re-render', () => {
    const { container, rerender } = render(
      <FieldWrapper htmlFor="k" label="K" help="Help"><input id="k" /></FieldWrapper>,
    )
    const control = container.querySelector('#k') as HTMLElement
    const spy = vi.spyOn(control, 'setAttribute')

    for (let i = 0; i < 3; i++) {
      rerender(<FieldWrapper htmlFor="k" label="K" help="Help"><input id="k" defaultValue={String(i)} /></FieldWrapper>)
    }

    expect(spy).not.toHaveBeenCalled()
  })
})
