import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { DemoCustomAction } from './DemoCustomAction'
import type { CustomActionComponentProps } from './ActionModal'

// The dev-tools Component Inspector seeds any key it does not recognise
// (custom action keys included) with `{}`. The demo action must render its
// fallbacks from that payload instead of throwing on `componentProps.greeting`.
describe('DemoCustomAction', () => {
  it('renders its fallbacks from an empty payload (Component Inspector sample render)', () => {
    expect(() => render(<DemoCustomAction {...({} as CustomActionComponentProps)} />)).not.toThrow()

    expect(screen.getByText('Select an option:')).toBeTruthy()
    // No options → no option buttons, Confirm stays disabled, nothing crashes.
    expect((screen.getByText('Confirm Choice').closest('button') as HTMLButtonElement).disabled).toBe(true)
  })

  it('uses the payload typed by the consumer unchanged', () => {
    const onFieldsChange = vi.fn()
    const onExecute = vi.fn()

    render(
      <DemoCustomAction
        action={{ name: 'Triage', uriKey: 'triage' } as CustomActionComponentProps['action']}
        resource="tickets"
        selectedIds={[1, 2]}
        componentProps={{ greeting: 'Pick a lane:', options: ['A', 'B'] }}
        onFieldsChange={onFieldsChange}
        onExecute={onExecute}
        onClose={() => undefined}
        isExecuting={false}
      />,
    )

    expect(screen.getByText('Triage')).toBeTruthy()
    expect(screen.getByText('Pick a lane:')).toBeTruthy()
    expect(screen.getByText('Applying to 2 records')).toBeTruthy()

    fireEvent.click(screen.getByText('B'))
    expect(onFieldsChange).toHaveBeenCalledWith({ selectedOption: 'B', note: '' })

    fireEvent.click(screen.getByText('Confirm Choice'))
    expect(onExecute).toHaveBeenCalledWith({ selectedOption: 'B', note: '' })
  })
})
