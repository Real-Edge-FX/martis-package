import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, fireEvent, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { ImageFieldInput } from './ImageField'

/*
 * `Image::fill()` skips a `readonly()` field, and an `immutable()` one on
 * update, which the update forms hand to the input as `readonly`. The input
 * only disabled its hidden `<input type="file">`: the Change and Remove
 * buttons, the remove button of each listed image and the drop zone kept
 * changing the value, so the form took a change the save then dropped (200,
 * image unchanged). A readonly Image keeps showing its images and offers no
 * control that changes the value, in single and multiple mode; an editable
 * one works as before.
 */

function makeField(readonly: boolean, multiple: boolean): FieldDefinition {
  return {
    attribute: 'cover', label: 'Cover', type: 'image',
    nullable: true, readonly, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [], multiple,
  } as unknown as FieldDefinition
}

const stored = { path: 'covers/ana.png', url: '/storage/covers/ana.png', name: 'ana.png' }
const storedList = [stored, { path: 'covers/rui.png', url: '/storage/covers/rui.png', name: 'rui.png' }]
const png = () => new File(['x'], 'new.png', { type: 'image/png' })

function renderInput(readonly: boolean, value: unknown, multiple = false) {
  const onChange = vi.fn()
  const utils = render(<ImageFieldInput field={makeField(readonly, multiple)} value={value} onChange={onChange} />)
  const zone = utils.container.querySelector('.martis-dropzone') as HTMLElement
  const fileInput = utils.container.querySelector('input[type="file"]') as HTMLInputElement
  // jsdom opens no file dialog; the spy records every attempt to open it.
  const openPicker = vi.spyOn(fileInput, 'click').mockImplementation(() => {})
  return { ...utils, onChange, zone, openPicker }
}

const dropOn = (zone: HTMLElement, file: File) => fireEvent.drop(zone, { dataTransfer: { files: [file] } })

const imageSources = (container: HTMLElement) =>
  [...container.querySelectorAll('img')].map((img) => img.getAttribute('src'))

/** Multiple mode puts one remove button on each image, outside the drop zone. */
const gridRemoveButtons = (container: HTMLElement, zone: HTMLElement) =>
  [...container.querySelectorAll('button')].filter((button) => !zone.contains(button))

beforeEach(() => {
  vi.stubGlobal('URL', Object.assign(Object.create(URL), {
    createObjectURL: vi.fn(() => 'blob:dropped'),
    revokeObjectURL: vi.fn(),
  }))
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('ImageFieldInput readonly, single', () => {
  it('shows the stored image with no Change or Remove button', () => {
    const { container, zone } = renderInput(true, stored)

    expect(imageSources(container)).toEqual(['/storage/covers/ana.png'])
    expect(screen.queryByRole('button', { name: /change/i })).toBeNull()
    expect(screen.queryByRole('button', { name: /remove/i })).toBeNull()
    expect(zone.classList.contains('is-readonly')).toBe(true)
  })

  it('ignores a dropped image, and does not light up as a drop target', () => {
    const { zone, onChange } = renderInput(true, stored)

    fireEvent.dragOver(zone)
    expect(zone.classList.contains('is-drag-over')).toBe(false)
    dropOn(zone, png())

    expect(onChange).not.toHaveBeenCalled()
  })

  it('disables the file picker when there is no image', () => {
    const { zone, onChange, openPicker } = renderInput(true, null)

    const choose = zone.querySelector('button') as HTMLButtonElement
    expect(choose.disabled).toBe(true)
    fireEvent.click(choose)
    dropOn(zone, png())

    expect(openPicker).not.toHaveBeenCalled()
    expect(onChange).not.toHaveBeenCalled()
  })
})

describe('ImageFieldInput editable, single', () => {
  it('changes and removes the stored image', () => {
    const { onChange, openPicker } = renderInput(false, stored)

    fireEvent.click(screen.getByRole('button', { name: /change/i }))
    expect(openPicker).toHaveBeenCalledTimes(1)

    fireEvent.click(screen.getByRole('button', { name: /remove/i }))
    expect(onChange).toHaveBeenCalledWith(null)
  })

  it('takes a dropped image', () => {
    const { zone, onChange } = renderInput(false, null)
    const file = png()

    fireEvent.dragOver(zone)
    expect(zone.classList.contains('is-drag-over')).toBe(true)
    dropOn(zone, file)

    expect(onChange).toHaveBeenCalledWith(file)
  })
})

describe('ImageFieldInput readonly, multiple', () => {
  it('shows the stored images with no remove button', () => {
    const { container, zone } = renderInput(true, storedList, true)

    expect(imageSources(container)).toEqual(['/storage/covers/ana.png', '/storage/covers/rui.png'])
    expect(gridRemoveButtons(container, zone)).toHaveLength(0)
    expect((zone.querySelector('button') as HTMLButtonElement).disabled).toBe(true)
    expect(zone.classList.contains('is-readonly')).toBe(true)
  })

  it('ignores dropped images, and does not light up as a drop target', () => {
    const { zone, onChange } = renderInput(true, storedList, true)

    fireEvent.dragOver(zone)
    expect(zone.classList.contains('is-drag-over')).toBe(false)
    dropOn(zone, png())

    expect(onChange).not.toHaveBeenCalled()
  })
})

describe('ImageFieldInput editable, multiple', () => {
  it('removes a listed image', () => {
    const { container, zone, onChange } = renderInput(false, storedList, true)

    fireEvent.click(gridRemoveButtons(container, zone)[0])

    expect(onChange).toHaveBeenCalledWith({
      items: [{ id: 'existing-1', existing: storedList[1], previewUrl: '/storage/covers/rui.png' }],
      __multiple: true,
    })
  })

  it('adds dropped images to the grid', () => {
    const { zone, onChange } = renderInput(false, storedList, true)
    const file = png()

    dropOn(zone, file)

    expect(onChange).toHaveBeenCalledWith({
      items: [
        { id: 'existing-0', existing: storedList[0], previewUrl: '/storage/covers/ana.png' },
        { id: 'existing-1', existing: storedList[1], previewUrl: '/storage/covers/rui.png' },
        expect.objectContaining({ file, previewUrl: 'blob:dropped' }),
      ],
      __multiple: true,
    })
  })
})
