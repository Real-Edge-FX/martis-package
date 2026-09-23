import { describe, it, expect, vi } from 'vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { FieldDefinition } from '@/types'
import { api, ApiError } from '@/lib/api'

/*
 * The pivot forms show a pivot field's own error below its input and never
 * gave the input the errors inside its value, so the error of a pivot
 * Repeater's row field (`steps.1.fields.key`) never showed. They now pass it.
 */

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }
})

import { EditPivotModal } from './BelongsToManyField'
import { registerDefaultFields } from './FieldRenderer'

registerDefaultFields()

const stepsField = {
  attribute: 'steps', label: 'Steps', type: 'repeater',
  nullable: true, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [], storage: 'json',
  repeatables: [{
    shortName: 'step', uniqueKey: 'step', label: 'Step',
    fields: [{ attribute: 'key', label: 'Key', type: 'text', nullable: true, readonly: false, required: false, rules: [] }],
  }],
} as unknown as FieldDefinition

const required = 'The Key field is required.'

describe('EditPivotModal with a pivot Repeater', () => {
  it('shows a row error under the row field of the row it belongs to', async () => {
    vi.mocked(api.put).mockRejectedValue(new ApiError(422, 'Validation failed.', [
      { field: 'steps.1.fields.key', message: required, code: 'required' },
    ]))

    render(
      <QueryClientProvider client={new QueryClient()}>
        <EditPivotModal
          title="Home"
          endpoint="/api/resources/clients/1/belongs-to-many/pages/3/pivot"
          pivotEndpoint="/api/resources/clients/1/belongs-to-many/pages/pivot-fields/3"
          pivotFields={[stepsField]}
          initialValues={{ steps: [
            { id: 'a', type: 'step', fields: { key: 'intro' } },
            { id: 'b', type: 'step', fields: { key: '' } },
          ] }}
          onSuccess={vi.fn()}
          onCancel={vi.fn()}
        />
      </QueryClientProvider>,
    )

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    const rows = [...document.querySelectorAll('[draggable]')] as HTMLElement[]
    await waitFor(() => expect(within(rows[1]).getByText(required)).toBeTruthy())
    expect(within(rows[0]).queryByText(required)).toBeNull()
  }, 10000)
})
