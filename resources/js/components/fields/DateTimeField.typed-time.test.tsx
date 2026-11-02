import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent, screen } from '@testing-library/react'
import { useState } from 'react'
import type { FieldDefinition } from '@/types'
import { DateTimeFieldInput } from './DateTimeField'

/*
 * PrimeReact's time picker shows the hour and minute as plain text with
 * up/down buttons only, so a time far from the current one took dozens of
 * clicks. The picker is replaced by one whose segments are inputs: typed
 * digits, arrow keys and the buttons all set the time on the field's value.
 */

function makeField(readonly = false): FieldDefinition {
  return {
    attribute: 'starts_at', label: 'Starts at', type: 'datetime',
    nullable: true, readonly, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
  } as unknown as FieldDefinition
}

function Harness({ initial, onChange, readonly }: { initial: string | null; onChange: (v: unknown) => void; readonly?: boolean }) {
  const [value, setValue] = useState<unknown>(initial)
  return (
    <DateTimeFieldInput
      field={makeField(readonly)}
      value={value}
      onChange={(v) => { setValue(v); onChange(v) }}
    />
  )
}

function openPanel() {
  fireEvent.click(document.querySelector('.p-datepicker-trigger') as HTMLElement)
}

const local = (iso: unknown) => {
  const d = new Date(iso as string)
  return [d.getFullYear(), d.getMonth(), d.getDate(), d.getHours(), d.getMinutes()]
}

describe('DateTimeFieldInput typed time', () => {
  const start = new Date(2026, 10, 2, 9, 5).toISOString()

  it('sets the hour and minute from typed digits, keeping the date', () => {
    const onChange = vi.fn()
    render(<Harness initial={start} onChange={onChange} />)
    openPanel()

    const hour = screen.getByLabelText('Hour') as HTMLInputElement
    const minute = screen.getByLabelText('Minute') as HTMLInputElement
    expect(hour.value).toBe('09')
    expect(minute.value).toBe('05')

    fireEvent.change(hour, { target: { value: '16' } })
    fireEvent.change(minute, { target: { value: '58' } })

    expect(local(onChange.mock.lastCall![0])).toEqual([2026, 10, 2, 16, 58])
  })

  it('ignores an out-of-range number and shows the stored time on blur', () => {
    const onChange = vi.fn()
    render(<Harness initial={start} onChange={onChange} />)
    openPanel()

    const hour = screen.getByLabelText('Hour') as HTMLInputElement
    fireEvent.change(hour, { target: { value: '27' } })
    expect(onChange).not.toHaveBeenCalled()
    fireEvent.blur(hour)
    expect(hour.value).toBe('09')
  })

  it('steps with the arrow keys and the buttons, wrapping around', () => {
    const onChange = vi.fn()
    render(<Harness initial={new Date(2026, 10, 2, 23, 0).toISOString()} onChange={onChange} />)
    openPanel()

    fireEvent.keyDown(screen.getByLabelText('Hour'), { key: 'ArrowUp' })
    expect(local(onChange.mock.lastCall![0]).slice(3)).toEqual([0, 0])

    fireEvent.click(screen.getByLabelText('Minute -1'))
    expect(local(onChange.mock.lastCall![0]).slice(3)).toEqual([0, 59])
  })

  it('hides the built-in picker that only has buttons', () => {
    render(<Harness initial={start} onChange={vi.fn()} />)
    openPanel()

    const pickers = document.querySelectorAll('.p-datepicker .p-timepicker')
    expect(pickers).toHaveLength(2)
    expect((pickers[0] as HTMLElement).style.display).toBe('none')
  })
})
