import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import { FieldLabelTooltip } from './FieldLabelTooltip'

describe('FieldLabelTooltip', () => {
  it('names the icon with the tooltip as plain text, not its markup', () => {
    const { container } = render(
      <FieldLabelTooltip text={'<strong>Full name</strong> of the client.<br>Example:<br>• Ana <em>Ferreira</em>'} />,
    )
    const trigger = container.querySelector('[data-pr-tooltip]') as HTMLElement

    expect(trigger.getAttribute('aria-label')).toBe('Full name of the client. Example: • Ana Ferreira')
    expect(trigger.getAttribute('data-pr-tooltip')).toContain('<strong>Full name</strong>')
  })

  it('keeps a plain-text tooltip as it is', () => {
    const { container } = render(<FieldLabelTooltip text="The full name." />)

    expect(container.querySelector('[data-pr-tooltip]')?.getAttribute('aria-label')).toBe('The full name.')
  })

  it('renders nothing without text', () => {
    const { container } = render(<FieldLabelTooltip text={null} />)

    expect(container.firstChild).toBeNull()
  })
})
