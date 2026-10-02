import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ToastProvider } from '@/contexts/ToastContext'
import { apiSegments } from '@/test-support/apiPaths'

/*
 * The action modal builds the Action's endpoints from the resource, the lens
 * and the Action's key. Each is one encoded segment, so a key that holds `../`
 * (an Action's `uriKey()` an app overrides, a lens named from a link) cannot
 * redirect the fields request or the run to another endpoint.
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
  uriKey: '../../users/5/actions/promote', name: 'Promote', icon: null, showIcon: true, iconColor: null,
  group: null, destructive: false, showOnIndex: true, showOnDetail: true, showInline: false,
  executionMode: 'bulk', standalone: false, sole: false, queued: false, withConfirmation: true,
  confirmText: null, confirmButtonText: null, cancelButtonText: null, modalSize: 'md',
  supportsDryRun: false, customComponent: null, customComponentProps: {}, logEvents: true,
  isPivotAction: false, pivotLabel: null,
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockReset()
  apiPostMock.mockResolvedValue({ data: { type: 'message', data: { message: 'Done.' } } })
  apiGetMock.mockResolvedValue({ data: { fields: [] } })
})

function renderModal(props: { resource: string; lens?: string }) {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <ToastProvider>
        <MemoryRouter>
          <ActionModal {...props} action={action} selectedIds={[1]} visible onHide={() => {}} onSuccess={() => {}} />
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  )
}

describe('ActionModal — keys stay one path segment', () => {
  it('reads the fields and runs the Action through the resource endpoint', async () => {
    renderModal({ resource: 'projects' })

    await waitFor(() => expect(apiGetMock).toHaveBeenCalledTimes(1))
    const actionSegment = '..%252F..%252Fusers%252F5%252Factions%252Fpromote'
    expect(apiSegments(apiGetMock.mock.calls[0]![0] as string)).toEqual(['resources', 'projects', 'actions', actionSegment, 'fields'])

    fireEvent.click(await waitFor(() => {
      const button = document.body.querySelector('.martis-btn-primary')
      expect(button).not.toBeNull()
      return button as HTMLElement
    }))
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    expect(apiSegments(apiPostMock.mock.calls[0]![0] as string)).toEqual(['resources', 'projects', 'actions', actionSegment])
  })

  it('encodes the lens key as well', async () => {
    renderModal({ resource: 'projects', lens: '../x' })

    await waitFor(() => expect(apiGetMock).toHaveBeenCalledTimes(1))
    expect(apiSegments(apiGetMock.mock.calls[0]![0] as string)).toEqual([
      'resources', 'projects', 'lenses', '..%252Fx', 'actions', '..%252F..%252Fusers%252F5%252Factions%252Fpromote', 'fields',
    ])
  })
})
