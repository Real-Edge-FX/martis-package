import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { AudioFieldInput } from './AudioField'

/*
 * `Audio` inherits `File::fill()`, which skips a `readonly()` field, and an
 * `immutable()` one on update, which the update forms hand to the input as
 * `readonly`. The input already hid its Replace / Remove buttons and disabled
 * its picker, and its `dragover` handler always cancels the event, so the
 * browser lets a file be dropped on it. On a readonly field the `drop` handler
 * returned before cancelling the drop: the browser then ran its default action,
 * opened the file in the tab and left the form, unsaved changes included. A
 * drop is cancelled whatever the state (`fireEvent` answers `false` once a
 * handler calls `preventDefault()`) and a readonly field ignores the file.
 */

function makeField(readonly: boolean): FieldDefinition {
  return {
    attribute: 'intro_audio_path', label: 'Intro audio', type: 'audio',
    nullable: true, readonly, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    // The canvas waveform decodes the clip through Web Audio, which jsdom lacks.
    rules: [], showWaveform: false, downloadable: true,
  } as unknown as FieldDefinition
}

const stored = { path: 'team-audio/intro.mp3', url: '/storage/team-audio/intro.mp3', name: 'intro.mp3' }
const clip = () => new File(['ID3'], 'new.mp3', { type: 'audio/mpeg' })

function renderInput(readonly: boolean, value: unknown) {
  const onChange = vi.fn()
  const utils = render(<AudioFieldInput field={makeField(readonly)} value={value} onChange={onChange} />)
  const zone = utils.container.querySelector('.martis-audio-input') as HTMLElement
  return { ...utils, onChange, zone }
}

/** `false` when a handler cancelled the drop, as the browser needs to skip opening the file. */
const dropOn = (zone: HTMLElement, file: File) => fireEvent.drop(zone, { dataTransfer: { files: [file] } })

describe('AudioFieldInput readonly', () => {
  it('keeps the player and offers no Replace or Remove button', () => {
    const { container, zone } = renderInput(true, stored)

    expect(container.querySelector('.martis-audio-player')).not.toBeNull()
    expect(container.querySelector('a.martis-audio-download')?.getAttribute('href')).toBe('/storage/team-audio/intro.mp3')
    expect(zone.querySelector('.martis-audio-input-controls')).toBeNull()
  })

  it('cancels a file dropped on the stored clip and ignores it, without lighting up as a drop target', () => {
    const { zone, onChange } = renderInput(true, stored)

    fireEvent.dragOver(zone)
    expect(zone.classList.contains('is-drag-over')).toBe(false)

    expect(dropOn(zone, clip())).toBe(false)
    expect(onChange).not.toHaveBeenCalled()
  })

  it('disables the file picker when there is no clip, and cancels and ignores a dropped file', () => {
    const { zone, onChange } = renderInput(true, null)

    expect((zone.querySelector('.martis-audio-dropzone') as HTMLButtonElement).disabled).toBe(true)
    expect(dropOn(zone, clip())).toBe(false)
    expect(onChange).not.toHaveBeenCalled()
  })
})

describe('AudioFieldInput editable', () => {
  it('takes a dropped file, cancelling the drop', () => {
    const { zone, onChange } = renderInput(false, null)
    const file = clip()

    expect((zone.querySelector('.martis-audio-dropzone') as HTMLButtonElement).disabled).toBe(false)
    fireEvent.dragOver(zone)
    expect(zone.classList.contains('is-drag-over')).toBe(true)

    expect(dropOn(zone, file)).toBe(false)
    expect(onChange).toHaveBeenCalledWith(file)
  })
})
