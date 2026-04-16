import { describe, it, expect, vi, beforeEach } from 'vitest'
// Mock react-i18next
vi.mock("react-i18next", () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const map: Record<string, string> = {
        choose_files: "Choose files or drag here",
        add_more_files: "Add more files",
        choose_images: "Click or drag images here",
        add_more_images: "Add more images",
      }
      return map[key] ?? key
    },
    i18n: { language: "en" },
  }),
}))
import { render, screen, fireEvent } from '@testing-library/react'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'
import { FieldDisplay, FieldInput } from '@/components/fields/FieldRenderer'
import type { FieldDefinition } from '@/types'
import { hasFileValues } from '@/lib/api'

beforeEach(() => {
  registerDefaultFields()
})

// -------------------------------------------------------------------------
// Fixtures
// -------------------------------------------------------------------------

const fileFieldSingle: FieldDefinition = {
  attribute: 'attachment',
  label: 'Attachment',
  type: 'file',
  nullable: true,
  readonly: false,
  required: false,
  sortable: false,
  searchable: false,
  showOnIndex: true,
  showOnDetail: true,
  showOnForms: true,
  rules: [],
}

const fileFieldMultiple: FieldDefinition = {
  attribute: 'documents',
  label: 'Documents',
  type: 'file',
  nullable: true,
  readonly: false,
  required: false,
  sortable: false,
  searchable: false,
  showOnIndex: true,
  showOnDetail: true,
  showOnForms: true,
  rules: [],
  multiple: true,
}

const imageFieldSingle: FieldDefinition = {
  attribute: 'avatar',
  label: 'Avatar',
  type: 'image',
  nullable: true,
  readonly: false,
  required: false,
  sortable: false,
  searchable: false,
  showOnIndex: true,
  showOnDetail: true,
  showOnForms: true,
  rules: [],
}

const imageFieldMultiple: FieldDefinition = {
  attribute: 'gallery',
  label: 'Gallery',
  type: 'image',
  nullable: true,
  readonly: false,
  required: false,
  sortable: false,
  searchable: false,
  showOnIndex: true,
  showOnDetail: true,
  showOnForms: true,
  rules: [],
  multiple: true,
}

// -------------------------------------------------------------------------
// File Field — Display
// -------------------------------------------------------------------------

describe('FileFieldDisplay', () => {
  it('renders single file link', () => {
    render(
      <FieldDisplay
        field={fileFieldSingle}
        value={{ path: 'uploads/doc.pdf', url: '/storage/uploads/doc.pdf', name: 'doc.pdf' }}
      />,
    )
    expect(screen.getByText('doc.pdf')).toBeTruthy()
  })

  it('renders em dash for null single value', () => {
    render(<FieldDisplay field={fileFieldSingle} value={null} />)
    expect(screen.getByText('—')).toBeTruthy()
  })

  it('renders multiple file links', () => {
    render(
      <FieldDisplay
        field={fileFieldMultiple}
        value={[
          { path: 'uploads/a.pdf', url: '/storage/a.pdf', name: 'a.pdf' },
          { path: 'uploads/b.pdf', url: '/storage/b.pdf', name: 'b.pdf' },
        ]}
      />,
    )
    expect(screen.getByText('a.pdf')).toBeTruthy()
    expect(screen.getByText('b.pdf')).toBeTruthy()
  })

  it('renders em dash for empty multiple array', () => {
    render(<FieldDisplay field={fileFieldMultiple} value={[]} />)
    expect(screen.getByText('—')).toBeTruthy()
  })
})

// -------------------------------------------------------------------------
// Image Field — Display
// -------------------------------------------------------------------------

describe('ImageFieldDisplay', () => {
  it('renders single image', () => {
    render(
      <FieldDisplay
        field={imageFieldSingle}
        value={{ path: 'imgs/photo.jpg', url: '/storage/photo.jpg', name: 'photo.jpg' }}
      />,
    )
    const img = screen.getByAltText('photo.jpg')
    expect(img).toBeTruthy()
  })

  it('renders multiple images as grid', () => {
    render(
      <FieldDisplay
        field={imageFieldMultiple}
        value={[
          { path: 'imgs/a.jpg', url: '/storage/a.jpg', name: 'a.jpg' },
          { path: 'imgs/b.jpg', url: '/storage/b.jpg', name: 'b.jpg' },
        ]}
      />,
    )
    expect(screen.getByAltText('a.jpg')).toBeTruthy()
    expect(screen.getByAltText('b.jpg')).toBeTruthy()
  })

  it('renders em dash for empty multiple images', () => {
    render(<FieldDisplay field={imageFieldMultiple} value={[]} />)
    expect(screen.getByText('—')).toBeTruthy()
  })
})

// -------------------------------------------------------------------------
// File Field — Input (Single)
// -------------------------------------------------------------------------

describe('FileFieldInput — single', () => {
  it('renders upload button when empty', () => {
    render(<FieldInput field={fileFieldSingle} value={null} onChange={vi.fn()} />)
    expect(screen.getByText('Choose file or drag here')).toBeTruthy()
  })

  it('renders existing file name', () => {
    render(
      <FieldInput
        field={fileFieldSingle}
        value={{ path: 'uploads/doc.pdf', url: '/storage/doc.pdf', name: 'doc.pdf' }}
        onChange={vi.fn()}
      />,
    )
    expect(screen.getByText('doc.pdf')).toBeTruthy()
  })
})

// -------------------------------------------------------------------------
// File Field — Input (Multiple)
// -------------------------------------------------------------------------

describe('FileFieldInput — multiple', () => {
  it('renders upload button when empty', () => {
    render(<FieldInput field={fileFieldMultiple} value={null} onChange={vi.fn()} />)
    expect(screen.getByText('Choose files or drag here')).toBeTruthy()
  })

  it('renders existing files list', () => {
    const existingFiles = [
      { path: 'uploads/a.pdf', url: '/storage/a.pdf', name: 'a.pdf' },
      { path: 'uploads/b.pdf', url: '/storage/b.pdf', name: 'b.pdf' },
    ]
    render(
      <FieldInput field={fileFieldMultiple} value={existingFiles} onChange={vi.fn()} />,
    )
    expect(screen.getByText('a.pdf')).toBeTruthy()
    expect(screen.getByText('b.pdf')).toBeTruthy()
    expect(screen.getByText('Add more files')).toBeTruthy()
  })

  it('calls onChange with __multiple marker when removing a file', () => {
    const onChange = vi.fn()
    const existingFiles = [
      { path: 'uploads/a.pdf', url: '/storage/a.pdf', name: 'a.pdf' },
    ]
    render(
      <FieldInput field={fileFieldMultiple} value={existingFiles} onChange={onChange} />,
    )

    // Find and click the trash button
    const removeButtons = screen.getAllByRole('button')
    const trashButton = removeButtons.find((b) => b.querySelector('svg'))
    if (trashButton) fireEvent.click(trashButton)

    expect(onChange).toHaveBeenCalled()
    const callArg = onChange.mock.calls[0][0] as { __multiple: boolean; items: unknown[] }
    expect(callArg.__multiple).toBe(true)
    expect(callArg.items).toHaveLength(0)
  })
})

// -------------------------------------------------------------------------
// Image Field — Input (Multiple)
// -------------------------------------------------------------------------

describe('ImageFieldInput — multiple', () => {
  it('renders upload area when empty', () => {
    render(<FieldInput field={imageFieldMultiple} value={null} onChange={vi.fn()} />)
    expect(screen.getByText('Click or drag images here')).toBeTruthy()
  })

  it('renders "Add more images" when items exist', () => {
    const existingImages = [
      { path: 'imgs/a.jpg', url: '/storage/a.jpg', name: 'a.jpg', thumbnailUrl: '/storage/a_thumb.jpg' },
    ]
    render(
      <FieldInput field={imageFieldMultiple} value={existingImages} onChange={vi.fn()} />,
    )
    expect(screen.getByText('Add more images')).toBeTruthy()
  })
})

// -------------------------------------------------------------------------
// hasFileValues utility
// -------------------------------------------------------------------------

describe('hasFileValues', () => {
  it('returns true for direct File object', () => {
    const file = new File(['content'], 'test.txt', { type: 'text/plain' })
    expect(hasFileValues({ doc: file })).toBe(true)
  })

  it('returns false for string values', () => {
    expect(hasFileValues({ name: 'test', status: 'active' })).toBe(false)
  })

  it('returns true for multiple file value with File items', () => {
    const file = new File(['content'], 'test.txt', { type: 'text/plain' })
    expect(
      hasFileValues({
        documents: {
          __multiple: true,
          items: [{ id: '1', file }],
        },
      }),
    ).toBe(true)
  })

  it('returns false for multiple file value with only existing paths', () => {
    expect(
      hasFileValues({
        documents: {
          __multiple: true,
          items: [{ id: '1', existing: { path: 'a.pdf', url: '/a.pdf', name: 'a.pdf' } }],
        },
      }),
    ).toBe(false)
  })
})
