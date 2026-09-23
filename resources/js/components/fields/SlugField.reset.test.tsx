import { describe, it, expect } from 'vitest'
import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { SlugFieldInput } from './SlugField'

/*
 * A slug follows its source until the user edits it by hand. "Create & add
 * another" clears the form for the next record, and a slug typed by hand for
 * the previous record kept the input in its hand-edited state, so the next
 * record's slug stayed empty while its title was typed. A slug the form
 * clears from outside follows its source again; a slug the user clears by
 * deleting its text stays hand-edited.
 */

const slugField = {
  attribute: 'slug', label: 'Slug', type: 'slug', sourceAttribute: 'title', separator: '-',
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [], reserved: [],
} as unknown as FieldDefinition

// A create form: one values object, the slug reading the title from it, and
// the reset "Create & add another" performs.
function CreateForm() {
  const [values, setValues] = useState<Record<string, unknown>>({})
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
        onChange={(v) => setValues((prev) => ({ ...prev, slug: v }))}
        formValues={values}
      />
      <button type="button" onClick={() => setValues({})}>Create &amp; add another</button>
    </div>
  )
}

const title = () => screen.getByLabelText('Title') as HTMLInputElement
const slug = () => screen.getByTestId('slug-input-slug') as HTMLInputElement

describe('SlugFieldInput after the form is cleared', () => {
  it('follows the source again after the form clears a slug typed by hand', () => {
    render(<CreateForm />)
    fireEvent.change(title(), { target: { value: 'First post' } })
    expect(slug().value).toBe('first-post')
    fireEvent.change(slug(), { target: { value: 'custom' } })

    fireEvent.click(screen.getByRole('button', { name: 'Create & add another' }))
    fireEvent.change(title(), { target: { value: 'Second post' } })

    expect(slug().value).toBe('second-post')
  })

  it('stays hand-edited when the user deletes the slug text', () => {
    render(<CreateForm />)
    fireEvent.change(title(), { target: { value: 'First post' } })
    fireEvent.change(slug(), { target: { value: '' } })

    fireEvent.change(title(), { target: { value: 'First post, edited' } })

    expect(slug().value).toBe('')
  })
})
