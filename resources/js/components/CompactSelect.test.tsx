import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { CompactSelect, PerPageSelect, TrashedSelect } from '@/components/CompactSelect'

/*
 * The per-page and trashed selects of the list toolbars are PrimeReact
 * Dropdowns that keep the `martis-perpage-select` class on their root.
 */

describe('PerPageSelect', () => {
  it('renders the current value on a root that keeps the martis-perpage-select class', () => {
    const { container } = render(<PerPageSelect value={25} options={[10, 25, 50]} onChange={vi.fn()} />)
    const root = container.querySelector('.p-dropdown.martis-perpage-select')
    expect(root).toBeTruthy()
    expect(root!.textContent).toContain('25')
  })

  it('exposes an accessible name', () => {
    render(<PerPageSelect value={25} options={[10, 25, 50]} onChange={vi.fn()} />)
    expect(screen.getAllByLabelText('Per page').length).toBeGreaterThan(0)
  })

  it('reports the chosen number', () => {
    const onChange = vi.fn()
    const { container } = render(<PerPageSelect value={25} options={[10, 25, 50]} onChange={onChange} />)
    fireEvent.click(container.querySelector('.p-dropdown')!)
    fireEvent.click(screen.getByText('50'))
    expect(onChange).toHaveBeenCalledWith(50)
  })
})

describe('TrashedSelect', () => {
  const labels = { active: 'Active', with: 'With trashed', only: 'Only trashed' }

  it('shows the label of the selected state and reports changes', () => {
    const onChange = vi.fn()
    const { container } = render(<TrashedSelect value="" onChange={onChange} labels={labels} ariaLabel="Soft-delete filter" />)
    expect(container.querySelector('.martis-perpage-select')!.textContent).toContain('Active')
    fireEvent.click(container.querySelector('.p-dropdown')!)
    fireEvent.click(screen.getByText('Only trashed'))
    expect(onChange).toHaveBeenCalledWith('only')
  })
})

describe('CompactSelect tooltip', () => {
  it('wraps the control in a tooltip target only when a tooltip is given', () => {
    const base = { value: 1, options: [{ label: 'One', value: 1 }], onChange: vi.fn(), ariaLabel: 'x' }
    const plain = render(<CompactSelect {...base} />)
    expect(plain.container.querySelector('[data-pr-tooltip]')).toBeNull()
    plain.unmount()
    const withTip = render(<CompactSelect {...base} tooltip="Hint" />)
    expect(withTip.container.querySelector('[data-pr-tooltip="Hint"]')).toBeTruthy()
  })
})

describe('CompactSelect focusable element', () => {
  it('names the focusable input, not only the trigger', () => {
    const { container } = render(<PerPageSelect value={25} options={[10, 25, 50]} onChange={vi.fn()} />)
    const input = container.querySelector('input[aria-haspopup="listbox"]')!
    expect(input.getAttribute('aria-label')).toBe('Per page')
  })
})
