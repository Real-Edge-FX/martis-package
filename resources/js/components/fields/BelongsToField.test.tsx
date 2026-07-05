import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import type { FieldDefinition } from '@/types'

vi.mock('@/lib/config', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/config')>()
  return {
    ...actual,
    config: {
      ...actual.config,
      resourceRecordUrls: { projects: '/tools/pk?id={id}' },
    },
  }
})

import { BelongsToFieldDisplay } from './BelongsToField'

function baseField(overrides: Record<string, unknown>): FieldDefinition {
  return {
    attribute: 'owner',
    label: 'Owner',
    type: 'belongsTo',
    nullable: false,
    readonly: false,
    required: false,
    sortable: false,
    searchable: false,
    showOnIndex: false,
    showOnDetail: true,
    showOnForms: true,
    rules: [],
    reserved: [],
    ...overrides,
  } as unknown as FieldDefinition
}

function renderWithRouter(ui: React.ReactElement) {
  return render(<MemoryRouter>{ui}</MemoryRouter>)
}

describe('BelongsToFieldDisplay — record-detail link resolution', () => {
  it('routes through the configured Tool URL when the related resource has a template', () => {
    const field = baseField({ relatedResource: 'projects' })
    renderWithRouter(<BelongsToFieldDisplay field={field} value={{ id: 42, title: 'Acme' }} />)

    const link = screen.getByRole('link', { name: 'Acme' })
    expect(link.getAttribute('href')).toBe('/tools/pk?id=42')
  })

  it('falls back to the default resource detail path when no template is configured', () => {
    const field = baseField({ relatedResource: 'users' })
    renderWithRouter(<BelongsToFieldDisplay field={field} value={{ id: 7, title: 'Jane' }} />)

    const link = screen.getByRole('link', { name: 'Jane' })
    expect(link.getAttribute('href')).toBe('/resources/users/7')
  })
})
