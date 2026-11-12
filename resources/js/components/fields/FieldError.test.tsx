import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { FieldError, useFieldError } from './FieldError'
import { FieldWrapper } from './FieldWrapper'

function Probe({ error }: { error?: string | null }) {
  const { hasError, message, errorId, invalidProps } = useFieldError(error)
  return (
    <div>
      <input data-testid="input" {...invalidProps} />
      <FieldError error={message} id={errorId} />
      <span data-testid="flag">{String(hasError)}</span>
    </div>
  )
}

describe('FieldError', () => {
  it('renders the message as an alert with the shared class', () => {
    render(<FieldError error="Required." id="e1" />)
    const alert = screen.getByRole('alert')
    expect(alert.textContent).toBe('Required.')
    expect(alert.className).toBe('martis-input-error')
    expect(alert.id).toBe('e1')
  })

  it('renders nothing without an error', () => {
    const { container } = render(<FieldError error={null} />)
    expect(container.innerHTML).toBe('')
  })
})

describe('useFieldError', () => {
  it('links the input to the message and flags it invalid', () => {
    render(<Probe error="Too short." />)
    const input = screen.getByTestId('input')
    const alert = screen.getByRole('alert')
    expect(input.getAttribute('aria-invalid')).toBe('true')
    expect(alert.id).not.toBe('')
    expect(input.getAttribute('aria-describedby')).toBe(alert.id)
    expect(screen.getByTestId('flag').textContent).toBe('true')
  })

  it('adds no attributes and no message when there is no error', () => {
    render(<Probe error={undefined} />)
    const input = screen.getByTestId('input')
    expect(input.hasAttribute('aria-invalid')).toBe(false)
    expect(input.hasAttribute('aria-describedby')).toBe(false)
    expect(screen.queryByRole('alert')).toBeNull()
    expect(screen.getByTestId('flag').textContent).toBe('false')
  })
})

describe('FieldWrapper error markup', () => {
  it('keeps the markup it drew before FieldError existed', () => {
    const { container } = render(
      <FieldWrapper error="Bad.">
        <input />
      </FieldWrapper>,
    )
    const alert = container.querySelector('span.martis-input-error')
    expect(alert?.getAttribute('role')).toBe('alert')
    expect(alert?.hasAttribute('id')).toBe(false)
    expect(alert?.textContent).toBe('Bad.')
  })
})
