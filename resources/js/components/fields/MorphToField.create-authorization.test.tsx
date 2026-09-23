import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { FieldDefinition } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * `MorphTo` serialises `showCreateRelationButton: true` when the user can
 * create at least one of its types, and flags each type with the policy's
 * `authorizedToCreate`, so the input can drop the "+" for the others. The
 * input never read the per-type flag: the "+" showed for a type the user
 * cannot create, and the modal it opened answered 403 ("Failed to load
 * schema."). The "+" now shows only for a type the user can create.
 */

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: vi.fn(() => new Promise(() => {})) },
  }
})

import { MorphToFieldInput } from './MorphToField'

const field = {
  attribute: 'commentable', label: 'Commentable', type: 'morph_to',
  morphTypes: [
    { value: 'posts', label: 'Post', authorizedToViewAny: true, authorizedToCreate: true },
    { value: 'videos', label: 'Video', authorizedToViewAny: true, authorizedToCreate: false },
  ],
  showCreateRelationButton: true,
  nullable: false, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
} as unknown as FieldDefinition

function renderInput(value: unknown) {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter>
        <ToastProvider>
          <MorphToFieldInput field={field} value={value} onChange={() => {}} resourceKey="comments" />
        </ToastProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const createButton = () => document.querySelector('.martis-morphto-create-btn')
const typeSelect = () => document.querySelector('select') as HTMLSelectElement

describe('MorphToFieldInput inline create per type', () => {
  it('offers no "+" for a type the user cannot create', () => {
    renderInput({ type: 'App\\Models\\Video', id: 4, title: 'Intro', resourceType: 'videos' })

    expect(typeSelect().value).toBe('videos')
    expect(createButton()).toBeNull()
  })

  it('offers the "+" once a type the user can create is picked', () => {
    renderInput({ type: 'App\\Models\\Video', id: 4, title: 'Intro', resourceType: 'videos' })

    fireEvent.change(typeSelect(), { target: { value: 'posts' } })

    expect(createButton()).not.toBeNull()
  })
})
