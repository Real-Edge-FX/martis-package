import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * The action modal seeds its form from the fields' server-resolved defaults
 * once per opening, shows the help text and tooltip of each field, and
 * names the record of a single-record run on the fields request.
 */

const apiGetMock = vi.fn()
const apiPostMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      post: (...args: unknown[]) => apiPostMock(...args),
    },
  }
})

import { ActionModal, type ActionMeta } from './ActionModal'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

const action: ActionMeta = {
  uriKey: 'rename', name: 'Rename', icon: null, showIcon: true, iconColor: null,
  group: null, destructive: false, showOnIndex: true, showOnDetail: true, showInline: false,
  executionMode: 'bulk', standalone: false, sole: false, queued: false, withConfirmation: true,
  confirmText: null, confirmButtonText: null, cancelButtonText: null, modalSize: 'md',
  supportsDryRun: false, customComponent: null, customComponentProps: {}, logEvents: true,
  isPivotAction: false, pivotLabel: null,
}

const nameField = {
  attribute: 'name', label: 'Name', type: 'text', defaultValue: 'abc',
  helpText: 'Pick <strong>a name</strong>', tooltip: 'Shown to everyone',
  nullable: true, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
}

function fieldCalls(): string[] {
  return apiGetMock.mock.calls.map((c) => String(c[0])).filter((u) => u.includes('/fields'))
}

function mount(props: Partial<React.ComponentProps<typeof ActionModal>> = {}, client = new QueryClient()) {
  const ui = (visible: boolean) => (
    <QueryClientProvider client={client}>
      <ToastProvider>
        <MemoryRouter>
          <ActionModal resource="posts" action={action} selectedIds={[1]} visible={visible} onHide={() => {}} onSuccess={() => {}} {...props} />
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>
  )
  const view = render(ui(true))
  return { ...view, setVisible: (v: boolean) => view.rerender(ui(v)), client }
}

const input = () => document.body.querySelector('#name') as HTMLInputElement

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockReset()
  apiPostMock.mockResolvedValue({ data: { type: 'message', data: { message: 'Done.' } } })
  apiGetMock.mockImplementation(async () => ({ data: { fields: [nameField] } }))
})

describe('ActionModal defaults', () => {
  it('starts the input from the field default', async () => {
    mount()
    await waitFor(() => expect(input()?.value).toBe('abc'))
  })

  it('sends the default untouched, and the edited value once edited', async () => {
    mount()
    await waitFor(() => expect(input()?.value).toBe('abc'))
    fireEvent.click(document.body.querySelector('.martis-btn-primary') as HTMLElement)
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    expect(apiPostMock.mock.calls[0][1].fields).toEqual({ name: 'abc' })

    apiPostMock.mockClear()
    fireEvent.change(input(), { target: { value: 'xyz' } })
    fireEvent.click(document.body.querySelector('.martis-btn-primary') as HTMLElement)
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    expect(apiPostMock.mock.calls[0][1].fields).toEqual({ name: 'xyz' })
  })

  it('restarts from the defaults when the modal is reopened', async () => {
    const { setVisible } = mount()
    await waitFor(() => expect(input()?.value).toBe('abc'))
    fireEvent.change(input(), { target: { value: 'xyz' } })
    expect(input().value).toBe('xyz')

    setVisible(false)
    setVisible(true)
    await waitFor(() => expect(input()?.value).toBe('abc'))
  })

  it('does not wipe an edit when the fields refetch in the background', async () => {
    const { client } = mount()
    await waitFor(() => expect(input()?.value).toBe('abc'))
    fireEvent.change(input(), { target: { value: 'xyz' } })
    apiGetMock.mockImplementation(async () => ({ data: { fields: [{ ...nameField, helpText: 'changed' }] } }))
    await client.invalidateQueries({ queryKey: ['action-fields'] })
    await waitFor(() => expect(document.body.querySelector('.martis-input-help')?.textContent).toBe('changed'))
    expect(input().value).toBe('xyz')
  })
})

describe('ActionModal help text and tooltip', () => {
  it('renders the help and ties it to the input, and the tooltip icon', async () => {
    mount()
    await waitFor(() => expect(input()).not.toBeNull())
    const help = document.body.querySelector('.martis-input-help') as HTMLElement
    expect(help.querySelector('strong')?.textContent).toBe('a name')
    await waitFor(() => expect(input().getAttribute('aria-describedby')).toBe(help.id))
    expect(document.body.querySelector('label[for="name"]')).not.toBeNull()
    expect(document.body.querySelector('[aria-label="Shown to everyone"]')).not.toBeNull()
  })
})

describe('ActionModal fields request', () => {
  it('names the record for one selected record, with the panel params when via is set', async () => {
    mount({ selectedIds: [7], via: { viaResource: 'users', viaResourceId: 3, viaRelationship: 'posts' } })
    await waitFor(() => expect(fieldCalls()).toHaveLength(1))
    expect(fieldCalls()[0]).toBe('/api/resources/posts/actions/rename/fields?resourceId=7&viaResource=users&viaResourceId=3&viaRelationship=posts')
  })

  it('keys the cache by record so two records never share fields', async () => {
    const client = new QueryClient()
    mount({ selectedIds: [7] }, client)
    await waitFor(() => expect(fieldCalls()).toHaveLength(1))
    const keys = client.getQueryCache().getAll().map((q) => q.queryKey)
    expect(keys.some((k) => JSON.stringify(k).includes('7'))).toBe(true)
    expect(fieldCalls()[0]).toContain('resourceId=7')
  })

  it.each<[string, Partial<React.ComponentProps<typeof ActionModal>>]>([
    ['several records', { selectedIds: [1, 2] }],
    ['a standalone action', { selectedIds: [1], action: { ...action, standalone: true } }],
  ])('sends no record for %s', async (_label, props) => {
    mount(props)
    await waitFor(() => expect(fieldCalls()).toHaveLength(1))
    expect(fieldCalls()[0]).toBe('/api/resources/posts/actions/rename/fields')
  })
})
