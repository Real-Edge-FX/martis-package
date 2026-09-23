import { describe, it, expect, vi } from 'vitest'
import { render } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import type { MartisForm } from '@/hooks/useMartisForm'

/*
 * FieldsForm defaulted its context to 'create', so a Tool form built with
 * useMartisForm({ context: 'update' }) and rendered with <FieldsForm form />
 * gave every input the create context. It now defaults to the form's.
 */

const seen: Array<string | undefined> = []
vi.mock('./FieldRenderer', () => ({
  FieldInput: ({ context }: { context?: string }) => {
    seen.push(context)
    return null
  },
}))

import { FieldsForm } from './FieldsForm'

const title = { type: 'text', attribute: 'title', label: 'Title' } as unknown as FieldDefinition

function formWith(context: 'create' | 'update'): MartisForm {
  return {
    values: {}, setValue: () => {}, setValues: () => {}, errors: {}, setErrors: () => {},
    resolvedFields: [title], context,
    fieldProps: (field) => ({ field, value: null, onChange: () => {}, context, formValues: {} }),
  }
}

describe('FieldsForm context', () => {
  it("renders a form's inputs in the form's context unless told otherwise", () => {
    seen.length = 0
    render(<FieldsForm form={formWith('update')} />)
    render(<FieldsForm form={formWith('update')} context="create" />)

    expect(seen).toEqual(['update', 'create'])
  })
})
