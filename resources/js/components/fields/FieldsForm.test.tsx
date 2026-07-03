import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { useMartisForm } from '@/hooks/useMartisForm'

/*
 * FieldsForm mounts the shared render loop (fields + tab_group/section/panel
 * containers) driven entirely by `useMartisForm`. This is the Step-2 test
 * from the Task 3 brief: it drives the classic slug-from-source behaviour
 * through the REAL shared form (`useMartisForm` + `FieldsForm`), not the
 * ad-hoc harness used by the pre-existing characterization tests. This is
 * safe post-fix (see commit a682da16a, "stabilize reserved-words dependency
 * to end SlugField render loop").
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
    },
  }
})

vi.mock('@/hooks/useDependsOnSync', () => ({
  useDependsOnSync: () => new Map(), // no server-side overrides in this test
}))

import { registerDefaultFields } from '@/components/fields/FieldRenderer'
import { FieldsForm } from './FieldsForm'

registerDefaultFields()

function baseField(overrides: Record<string, unknown>): FieldDefinition {
  return {
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

const titleField = baseField({ attribute: 'title', label: 'Title', type: 'text' })
const slugField = baseField({
  attribute: 'slug',
  label: 'Slug',
  type: 'slug',
  sourceAttribute: 'title',
  separator: '-',
})

function Harness({ fields }: { fields: FieldDefinition[] }) {
  const form = useMartisForm({ fields, resourceKey: 'posts', context: 'create' })
  return <FieldsForm form={form} context="create" />
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockResolvedValue({ data: { available: true, suggestion: null, reserved: false } })
})

describe('FieldsForm', () => {
  it('renders fields and updates slug-from-source through the shared form', async () => {
    render(<Harness fields={[titleField, slugField]} />)

    const titleInput = document.getElementById('title') as HTMLInputElement
    const slugInput = screen.getByTestId('slug-input-slug') as HTMLInputElement
    expect(slugInput.value).toBe('')

    fireEvent.change(titleInput, { target: { value: 'Hello World' } })

    await waitFor(() => {
      expect(slugInput.value).toBe('hello-world')
    })
  })
})
