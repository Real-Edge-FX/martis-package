import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, fireEvent, screen } from '@testing-library/react'
import { useState } from 'react'
import type { FieldDefinition } from '@/types'
import { MultiSelectFieldInput } from './MultiSelectField'
import { IconFieldInput } from './IconField'

/*
 * A picker's list renders in document.body with `position: fixed`. It used
 * to be an `absolute` child of the picker, so a container that scrolls (an
 * action modal's body, a drawer) cut it at its bottom edge and the user had
 * to scroll the container to reach the options.
 */

const field = {
  attribute: 'roles', label: 'Roles', type: 'multi_select',
  options: [
    { label: 'Viewer', value: 'viewer' },
    { label: 'Editor', value: 'editor' },
    { label: 'Admin', value: 'admin' },
    { label: 'Full Access', value: 'full' },
  ],
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [],
} as unknown as FieldDefinition

function Harness() {
  const [value, setValue] = useState<unknown>([])
  return (
    <div data-testid="scroller" style={{ overflow: 'auto', height: 120 }}>
      <MultiSelectFieldInput field={field} value={value} onChange={setValue} />
      <span data-testid="value">{JSON.stringify(value)}</span>
    </div>
  )
}

function panel(): HTMLElement | null {
  return document.body.querySelector('[data-martis-floating-panel]')
}

function trigger(): HTMLElement {
  return document.body.querySelector('[data-testid="scroller"] .martis-input') as HTMLElement
}

function placeTrigger(top: number) {
  vi.spyOn(trigger(), 'getBoundingClientRect').mockReturnValue({
    top, bottom: top + 38, left: 40, right: 340, width: 300, height: 38, x: 40, y: top, toJSON: () => ({}),
  } as DOMRect)
}

afterEach(() => {
  vi.restoreAllMocks()
})

describe('MultiSelect list in a scrolling container', () => {
  it('renders the list in document.body, outside the container, and leaves its scroll alone', () => {
    render(<Harness />)
    const scroller = screen.getByTestId('scroller')

    fireEvent.click(trigger())

    const list = panel()
    expect(list).not.toBeNull()
    expect(scroller.contains(list)).toBe(false)
    expect(list!.parentElement).toBe(document.body)
    expect(list!.className).toContain('martis-floating-panel')
    expect(scroller.scrollTop).toBe(0)
    expect(screen.getByText('Full Access')).toBeTruthy()
  })

  it('places the list from the trigger: below it, or above it near the bottom of the viewport', () => {
    render(<Harness />)
    vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockReturnValue(200)

    placeTrigger(100)
    fireEvent.click(trigger())
    expect(panel()!.dataset.side).toBe('below')
    expect(panel()!.style.top).toBe('142px')
    expect(panel()!.style.width).toBe('300px')

    fireEvent.click(trigger())
    expect(panel()).toBeNull()

    placeTrigger(window.innerHeight - 60)
    fireEvent.click(trigger())
    expect(panel()!.dataset.side).toBe('above')
    expect(panel()!.style.bottom).toBe('64px')
    expect(panel()!.style.top).toBe('auto')
  })

  it('follows the trigger when a container scrolls', () => {
    render(<Harness />)
    placeTrigger(100)
    fireEvent.click(trigger())
    expect(panel()!.style.top).toBe('142px')

    placeTrigger(60)
    fireEvent.scroll(screen.getByTestId('scroller'))
    expect(panel()!.style.top).toBe('102px')
  })

  it('hides the list while the container scrolls the trigger out of view, and shows it again', () => {
    render(<Harness />)
    const scroller = screen.getByTestId('scroller')
    vi.spyOn(scroller, 'getBoundingClientRect').mockReturnValue({
      top: 50, bottom: 170, left: 0, right: 400, width: 400, height: 120, x: 0, y: 50, toJSON: () => ({}),
    } as DOMRect)
    placeTrigger(100)
    fireEvent.click(trigger())
    expect(panel()!.style.visibility).toBe('')

    placeTrigger(200)
    fireEvent.scroll(scroller)
    expect(panel()!.style.visibility).toBe('hidden')
    expect(panel()!.style.pointerEvents).toBe('none')

    placeTrigger(100)
    fireEvent.scroll(scroller)
    expect(panel()!.style.visibility).toBe('')
  })

  it('selects an option without closing the list, and an outside click closes it', () => {
    render(<Harness />)
    fireEvent.click(trigger())

    const option = screen.getByText('Full Access')
    fireEvent.mouseDown(option)
    fireEvent.click(option)

    expect(screen.getByTestId('value').textContent).toBe('["full"]')
    expect(panel()).not.toBeNull()

    fireEvent.mouseDown(document.body)
    expect(panel()).toBeNull()
  })

  it('closes on Escape', () => {
    render(<Harness />)
    fireEvent.click(trigger())

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(panel()).toBeNull()
  })
})

describe('Icon picker', () => {
  it('renders its grid in document.body and keeps it open for a click inside', () => {
    const icon = {
      attribute: 'icon', label: 'Icon', type: 'icon', stored: true, palette: ['rocket', 'star'],
      nullable: true, readonly: false,
      required: false, sortable: false, searchable: false, showOnIndex: false,
      showOnDetail: true, showOnForms: true, rules: [],
    } as unknown as FieldDefinition
    const { container } = render(<IconFieldInput field={icon} value={null} onChange={() => {}} />)

    fireEvent.click(screen.getByTestId('icon-input-icon'))
    const grid = screen.getByTestId('icon-picker-icon')
    expect(container.contains(grid)).toBe(false)
    expect(grid.hasAttribute('data-martis-floating-panel')).toBe(true)

    fireEvent.mouseDown(grid.querySelector('input') as HTMLElement)
    expect(screen.queryByTestId('icon-picker-icon')).not.toBeNull()

    fireEvent.mouseDown(document.body)
    expect(screen.queryByTestId('icon-picker-icon')).toBeNull()
  })
})
