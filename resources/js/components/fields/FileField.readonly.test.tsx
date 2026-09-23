import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { FileFieldInput } from './FileField'

/*
 * `File::fill()` skips a `readonly()` field, and an `immutable()` one on
 * update, which the update forms hand to the input as `readonly`. The input
 * only disabled its hidden `<input type="file">`: the remove button and the
 * drop zone kept changing the value, so the form took a change the save then
 * dropped (200, file unchanged). A readonly File keeps its download links and
 * offers no control that changes the value, in single and multiple mode; an
 * editable one works as before.
 */

function makeField(readonly: boolean, multiple: boolean): FieldDefinition {
  return {
    attribute: 'contract', label: 'Contract', type: 'file',
    nullable: true, readonly, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [], multiple,
  } as unknown as FieldDefinition
}

const stored = { path: 'contracts/ana.pdf', url: '/storage/contracts/ana.pdf', name: 'ana.pdf' }
const storedList = [stored, { path: 'contracts/rui.pdf', url: '/storage/contracts/rui.pdf', name: 'rui.pdf' }]
const pdf = () => new File(['%PDF'], 'new.pdf', { type: 'application/pdf' })

function renderInput(readonly: boolean, value: unknown, multiple = false) {
  const onChange = vi.fn()
  const utils = render(<FileFieldInput field={makeField(readonly, multiple)} value={value} onChange={onChange} />)
  const zone = utils.container.querySelector('.martis-dropzone') as HTMLElement
  return { ...utils, onChange, zone }
}

const dropOn = (zone: HTMLElement, file: File) => fireEvent.drop(zone, { dataTransfer: { files: [file] } })

const downloadLinks = (container: HTMLElement) =>
  [...container.querySelectorAll('a[href]')].map((link) => link.getAttribute('href'))

/** Multiple mode lists one remove button per file, outside the drop zone. */
const listRemoveButtons = (container: HTMLElement, zone: HTMLElement) =>
  [...container.querySelectorAll('button')].filter((button) => !zone.contains(button))

describe('FileFieldInput readonly, single', () => {
  it('keeps the download link and offers no remove button', () => {
    const { container, zone } = renderInput(true, stored)

    expect(downloadLinks(container)).toEqual(['/storage/contracts/ana.pdf'])
    expect(zone.querySelectorAll('button')).toHaveLength(0)
    expect(zone.classList.contains('is-readonly')).toBe(true)
  })

  it('ignores a dropped file, and does not light up as a drop target', () => {
    const { zone, onChange } = renderInput(true, stored)

    fireEvent.dragOver(zone)
    expect(zone.classList.contains('is-drag-over')).toBe(false)
    dropOn(zone, pdf())

    expect(onChange).not.toHaveBeenCalled()
  })

  it('disables the file picker when there is no file', () => {
    const { zone, onChange } = renderInput(true, null)

    expect((zone.querySelector('button') as HTMLButtonElement).disabled).toBe(true)
    dropOn(zone, pdf())
    expect(onChange).not.toHaveBeenCalled()
  })
})

describe('FileFieldInput editable, single', () => {
  it('removes the stored file', () => {
    const { zone, onChange } = renderInput(false, stored)

    expect(zone.classList.contains('is-readonly')).toBe(false)
    fireEvent.click(zone.querySelector('button') as HTMLButtonElement)

    expect(onChange).toHaveBeenCalledWith(null)
  })

  it('takes a dropped file', () => {
    const { zone, onChange } = renderInput(false, null)
    const file = pdf()

    expect((zone.querySelector('button') as HTMLButtonElement).disabled).toBe(false)
    fireEvent.dragOver(zone)
    expect(zone.classList.contains('is-drag-over')).toBe(true)
    dropOn(zone, file)

    expect(onChange).toHaveBeenCalledWith(file)
  })
})

describe('FileFieldInput readonly, multiple', () => {
  it('keeps the download links and offers no remove button', () => {
    const { container, zone } = renderInput(true, storedList, true)

    expect(downloadLinks(container)).toEqual(['/storage/contracts/ana.pdf', '/storage/contracts/rui.pdf'])
    expect(listRemoveButtons(container, zone)).toHaveLength(0)
    expect((zone.querySelector('button') as HTMLButtonElement).disabled).toBe(true)
    expect(zone.classList.contains('is-readonly')).toBe(true)
  })

  it('ignores dropped files, and does not light up as a drop target', () => {
    const { zone, onChange } = renderInput(true, storedList, true)

    fireEvent.dragOver(zone)
    expect(zone.classList.contains('is-drag-over')).toBe(false)
    dropOn(zone, pdf())

    expect(onChange).not.toHaveBeenCalled()
  })
})

describe('FileFieldInput editable, multiple', () => {
  it('removes a listed file', () => {
    const { container, zone, onChange } = renderInput(false, storedList, true)

    fireEvent.click(listRemoveButtons(container, zone)[0])

    expect(onChange).toHaveBeenCalledWith({ items: [{ id: 'existing-1', existing: storedList[1] }], __multiple: true })
  })

  it('adds dropped files to the list', () => {
    const { zone, onChange } = renderInput(false, storedList, true)
    const file = pdf()

    expect((zone.querySelector('button') as HTMLButtonElement).disabled).toBe(false)
    dropOn(zone, file)

    expect(onChange).toHaveBeenCalledWith({
      items: [
        { id: 'existing-0', existing: storedList[0] },
        { id: 'existing-1', existing: storedList[1] },
        expect.objectContaining({ file }),
      ],
      __multiple: true,
    })
  })
})
