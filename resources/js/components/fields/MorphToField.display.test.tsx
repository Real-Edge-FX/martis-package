import { describe, it, expect } from 'vitest'
import { render } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition } from '@/types'
import { MorphToFieldDisplay } from './MorphToField'

/*
 * The action log's Target column (Nova's MorphToActionTarget) is a MorphTo
 * with no `types()`: the server resolves the target's resource and hands its
 * singular label in the value. The display prefers it over the field's
 * `morphTypes`, and otherwise falls back to them, then to the URI key.
 */

function makeField(morphTypes: unknown[] = []): FieldDefinition {
  return {
    attribute: 'target', label: 'Target', type: 'morph_to', morphTypes,
    nullable: true, readonly: true, required: false, sortable: false, searchable: false,
    showOnIndex: true, showOnDetail: true, showOnForms: false, rules: [],
  } as unknown as FieldDefinition
}

function renderDisplay(value: unknown, field: FieldDefinition) {
  return render(
    <MemoryRouter>
      <MorphToFieldDisplay field={field} value={value} />
    </MemoryRouter>,
  )
}

describe('MorphToFieldDisplay type label', () => {
  it('prefers the resource label the server resolved', () => {
    const { container } = renderDisplay(
      { type: 'App\\Models\\Project', id: '5', title: 'Apollo', resourceType: 'projects', resourceLabel: 'Project' },
      makeField(),
    )
    expect(container.textContent).toContain('Project:')
    expect(container.textContent).toContain('Apollo')
    expect(container.querySelector('a[href$="/resources/projects/5"]')).not.toBeNull()
  })

  it('falls back to the field morph types without a resolved label', () => {
    const { container } = renderDisplay(
      { type: 'App\\Models\\Post', id: 1, title: 'Hello', resourceType: 'posts' },
      makeField([{ value: 'posts', label: 'Post' }]),
    )
    expect(container.textContent).toContain('Post:')
  })

  it('renders an unlinked title when the server gave no resource type', () => {
    const { container } = renderDisplay(
      { type: 'App\\Models\\Project', id: '5', title: 'Project: 5', resourceType: null },
      makeField(),
    )
    expect(container.textContent).toBe('Project: 5')
    expect(container.querySelector('a')).toBeNull()
  })
})
