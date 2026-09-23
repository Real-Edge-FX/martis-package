import { describe, it, expect, vi } from 'vitest'
import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { SlugFieldInput } from './SlugField'

/*
 * When a slug follows its source. A create form, a replicated copy or a
 * slug default included, follows the source from the source's first change,
 * as Nova's Slug does: the slug the form mounts with stays until then, and
 * from then on the slug follows the source until the user edits it by hand.
 * An edit form counts a stored slug as set, so renaming a record does not
 * change its URL; an empty stored slug follows the source at once.
 */

const slugField = {
  attribute: 'slug', label: 'Slug', type: 'slug', sourceAttribute: 'title', separator: '-',
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [], reserved: [],
} as unknown as FieldDefinition

function Form({
  context,
  initial,
  onSlugChange,
}: {
  context: 'create' | 'update'
  initial: Record<string, unknown>
  onSlugChange?: (value: unknown) => void
}) {
  const [values, setValues] = useState<Record<string, unknown>>(initial)
  return (
    <div>
      <input
        aria-label="Title"
        value={String(values.title ?? '')}
        onChange={(e) => setValues((prev) => ({ ...prev, title: e.target.value }))}
      />
      <SlugFieldInput
        field={slugField}
        value={values.slug ?? null}
        onChange={(v) => {
          onSlugChange?.(v)
          setValues((prev) => ({ ...prev, slug: v }))
        }}
        formValues={values}
        context={context}
      />
    </div>
  )
}

const title = () => screen.getByLabelText('Title') as HTMLInputElement
const slug = () => screen.getByTestId('slug-input-slug') as HTMLInputElement

describe('SlugFieldInput on a create form', () => {
  it('keeps the slug it mounts with until the source changes, then follows the source', () => {
    render(<Form context="create" initial={{ title: 'First post (copy)', slug: 'first-post' }} />)
    expect(slug().value).toBe('first-post')

    fireEvent.change(title(), { target: { value: 'Brand new post' } })

    expect(slug().value).toBe('brand-new-post')
  })

  it('does not rewrite a custom slug it mounts with', () => {
    const onSlugChange = vi.fn()
    render(<Form context="create" initial={{ title: 'First post', slug: 'my-custom-slug' }} onSlugChange={onSlugChange} />)

    expect(slug().value).toBe('my-custom-slug')
    expect(onSlugChange).not.toHaveBeenCalled()
  })

  it('leaves an empty slug empty until the source changes', () => {
    render(<Form context="create" initial={{ title: 'Draft title', slug: null }} />)
    expect(slug().value).toBe('')

    fireEvent.change(title(), { target: { value: 'Final title' } })

    expect(slug().value).toBe('final-title')
  })

  it('stops following the source once the user edits the slug', () => {
    render(<Form context="create" initial={{ title: 'First post', slug: 'first-post' }} />)
    fireEvent.change(title(), { target: { value: 'Second post' } })
    expect(slug().value).toBe('second-post')

    fireEvent.change(slug(), { target: { value: 'custom' } })
    fireEvent.change(title(), { target: { value: 'Third post' } })

    expect(slug().value).toBe('custom')
  })
})

describe('SlugFieldInput on an edit form', () => {
  it('keeps the stored slug when the source changes', () => {
    render(<Form context="update" initial={{ title: 'Old title', slug: 'old-title' }} />)

    fireEvent.change(title(), { target: { value: 'New title' } })

    expect(slug().value).toBe('old-title')
  })

  it('fills an empty stored slug from the source at once', () => {
    render(<Form context="update" initial={{ title: 'Old title', slug: null }} />)

    expect(slug().value).toBe('old-title')
  })
})
