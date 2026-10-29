import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useState } from 'react'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ToastProvider } from '@/contexts/ToastContext'

/* The inline-create modal starts from each field's `default()`, on every opening. */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) } }
})

import { InlineCreateModal } from './InlineCreateModal'
import { registerDefaultFields } from './fields/FieldRenderer'

registerDefaultFields()

const codeField = {
  attribute: 'code', label: 'Code', type: 'text', defaultValue: 'abc',
  nullable: true, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
}

function Host() {
  const [open, setOpen] = useState(false)
  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>New author</button>
      <InlineCreateModal relatedResource="authors" open={open} onClose={() => setOpen(false)} onCreated={() => setOpen(false)} />
    </>
  )
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockResolvedValue({ data: { fields: [codeField], singularLabel: 'Author', label: 'Authors' } })
})

const code = () => document.getElementById('code') as HTMLInputElement | null

describe('InlineCreateModal: field defaults', () => {
  it('starts from each field default, again each time it opens', async () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ToastProvider>
          <MemoryRouter>
            <Host />
          </MemoryRouter>
        </ToastProvider>
      </QueryClientProvider>,
    )

    fireEvent.click(screen.getByRole('button', { name: 'New author' }))
    await waitFor(() => expect(code()?.value).toBe('abc'))

    fireEvent.change(code()!, { target: { value: 'typed' } })
    fireEvent.click(screen.getAllByRole('button', { name: /cancel/i })[0])
    await waitFor(() => expect(code()).toBeNull())

    fireEvent.click(screen.getByRole('button', { name: 'New author' }))
    await waitFor(() => expect(code()?.value).toBe('abc'))
  })
})
