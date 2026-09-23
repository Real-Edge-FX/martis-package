import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, fireEvent, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { AvatarFieldInput } from './AvatarField'

/*
 * The Avatar input previews its `value`: the stored image of the record, or
 * the file just picked. `value` can change after the input mounted (a form
 * seeding the stored avatar after rendering its fields, "Create & add
 * another" clearing the form), and the preview used to be read once at
 * mount, so an edit form showed no avatar and no Remove button.
 */

const field = {
  attribute: 'avatar', label: 'Avatar', type: 'avatar',
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [],
} as unknown as FieldDefinition

const stored = { url: '/storage/avatars/ana.png', path: 'avatars/ana.png', name: 'ana.png' }

function previewSrc(container: HTMLElement): string | null {
  return container.querySelector('.martis-avatar img')?.getAttribute('src') ?? null
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('AvatarFieldInput value from outside', () => {
  it('previews the stored avatar handed in after mount (the edit form hydrating the record)', () => {
    const { container, rerender } = render(<AvatarFieldInput field={field} value={null} onChange={() => {}} />)
    expect(previewSrc(container)).toBeNull()

    rerender(<AvatarFieldInput field={field} value={stored} onChange={() => {}} />)

    expect(previewSrc(container)).toBe('/storage/avatars/ana.png')
    expect(screen.getByRole('button', { name: 'Remove' })).toBeTruthy()
  })

  it('drops the preview when the form is cleared', () => {
    const { container, rerender } = render(<AvatarFieldInput field={field} value={stored} onChange={() => {}} />)
    expect(previewSrc(container)).toBe('/storage/avatars/ana.png')

    rerender(<AvatarFieldInput field={field} value={null} onChange={() => {}} />)

    expect(previewSrc(container)).toBeNull()
    expect(screen.queryByRole('button', { name: 'Remove' })).toBeNull()
  })

  it('keeps the picked file preview when the form hands back the file it just emitted', () => {
    let urls = 0
    const createObjectURL = vi.fn(() => `blob:picked-${++urls}`)
    vi.stubGlobal('URL', Object.assign(Object.create(URL), { createObjectURL, revokeObjectURL: vi.fn() }))
    let current: unknown = stored
    const onChange = vi.fn((next: unknown) => {
      current = next
    })
    const { container, rerender } = render(<AvatarFieldInput field={field} value={current} onChange={onChange} />)

    const file = new File(['x'], 'new.png', { type: 'image/png' })
    fireEvent.change(container.querySelector('input[type="file"]') as HTMLInputElement, { target: { files: [file] } })
    rerender(<AvatarFieldInput field={field} value={current} onChange={onChange} />)

    expect(onChange).toHaveBeenLastCalledWith(file)
    expect(previewSrc(container)).toBe('blob:picked-1')
    expect(createObjectURL).toHaveBeenCalledTimes(1)
  })

  it('re-arms the file picker after a pick, so the same file can be picked again', () => {
    vi.stubGlobal('URL', Object.assign(Object.create(URL), {
      createObjectURL: vi.fn(() => 'blob:picked'),
      revokeObjectURL: vi.fn(),
    }))
    const { container } = render(<AvatarFieldInput field={field} value={null} onChange={() => {}} />)
    const input = container.querySelector('input[type="file"]') as HTMLInputElement
    // A browser fires no `change` for the file the input already holds.
    const assigned: string[] = []
    Object.defineProperty(input, 'value', {
      configurable: true,
      get: () => '',
      set: (v: string) => {
        assigned.push(v)
      },
    })

    fireEvent.change(input, { target: { files: [new File(['x'], 'same.png', { type: 'image/png' })] } })

    expect(assigned).toEqual([''])
  })
})
