import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, fireEvent, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { AvatarFieldInput } from './AvatarField'

/*
 * `Image::fill()` (which `Avatar` inherits) skips a `readonly()` field, and
 * an `immutable()` one on update, which the update forms hand to the input as
 * `readonly`. The Avatar input never read `field.readonly`: its pick and
 * remove buttons stayed live, so the form took a change the save then dropped
 * (200, column unchanged). A readonly Avatar shows the stored image with no
 * control that changes it; an editable one picks and removes as before.
 */

function makeField(readonly: boolean): FieldDefinition {
  return {
    attribute: 'avatar', label: 'Avatar', type: 'avatar',
    nullable: true, readonly, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [],
  } as unknown as FieldDefinition
}

const stored = { url: '/storage/avatars/ana.png', path: 'avatars/ana.png', name: 'ana.png' }

function renderInput(readonly: boolean) {
  const onChange = vi.fn()
  const utils = render(<AvatarFieldInput field={makeField(readonly)} value={stored} onChange={onChange} />)
  const fileInput = utils.container.querySelector('input[type="file"]') as HTMLInputElement
  // jsdom opens no file dialog; the spy records every attempt to open it.
  const openPicker = vi.spyOn(fileInput, 'click').mockImplementation(() => {})
  return { ...utils, onChange, fileInput, openPicker }
}

beforeEach(() => {
  vi.stubGlobal('URL', Object.assign(Object.create(URL), {
    createObjectURL: vi.fn(() => 'blob:picked'),
    revokeObjectURL: vi.fn(),
  }))
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('AvatarFieldInput readonly', () => {
  it('shows the stored avatar with no pick or remove button', () => {
    const { container } = renderInput(true)

    expect(container.querySelector('.martis-avatar img')?.getAttribute('src')).toBe('/storage/avatars/ana.png')
    expect(screen.queryByRole('button', { name: 'Choose file' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Remove' })).toBeNull()
    expect((screen.getByRole('button', { name: 'Upload avatar' }) as HTMLButtonElement).disabled).toBe(true)
  })

  it('does not open the file picker from the avatar', () => {
    const { openPicker } = renderInput(true)

    fireEvent.click(screen.getByRole('button', { name: 'Upload avatar' }))

    expect(openPicker).not.toHaveBeenCalled()
  })

  it('ignores a file handed to its file input', () => {
    const { fileInput, onChange, container } = renderInput(true)

    expect(fileInput.disabled).toBe(true)
    fireEvent.change(fileInput, { target: { files: [new File(['x'], 'new.png', { type: 'image/png' })] } })

    expect(onChange).not.toHaveBeenCalled()
    expect(container.querySelector('.martis-avatar img')?.getAttribute('src')).toBe('/storage/avatars/ana.png')
  })
})

describe('AvatarFieldInput editable', () => {
  it('opens the file picker from the avatar and the Choose file button', () => {
    const { openPicker } = renderInput(false)

    fireEvent.click(screen.getByRole('button', { name: 'Upload avatar' }))
    fireEvent.click(screen.getByRole('button', { name: 'Choose file' }))

    expect(openPicker).toHaveBeenCalledTimes(2)
  })

  it('emits a picked file and a removal', () => {
    const { fileInput, onChange } = renderInput(false)
    const file = new File(['x'], 'new.png', { type: 'image/png' })

    fireEvent.change(fileInput, { target: { files: [file] } })
    expect(onChange).toHaveBeenLastCalledWith(file)

    fireEvent.click(screen.getByRole('button', { name: 'Remove' }))
    expect(onChange).toHaveBeenLastCalledWith(null)
  })
})
