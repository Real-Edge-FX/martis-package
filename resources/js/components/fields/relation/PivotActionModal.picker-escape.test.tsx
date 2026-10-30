import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent, waitFor } from '@testing-library/react'
import { useState } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useEscapeLayer } from '@/lib/escapeLayers'

/*
 * A picker open in the pivot action's form is a layer over the modal:
 * Escape closes the picker first, and only a second Escape closes the modal.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string, fallback?: string) => fallback ?? key, i18n: { language: 'en' } }),
}))
vi.mock('react-router', () => ({ useParams: () => ({}), useNavigate: () => () => {} }))
vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(async () => ({ data: { fields: [] } })), post: vi.fn() },
  ApiError: class ApiError extends Error {},
}))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast: vi.fn() }) }))

import { PivotActionModal } from './PivotActionModal'
import type { ActionMeta } from '@/components/Actions/ActionModal'

const action = {
  uriKey: 'tag', name: 'Tag', standalone: false, destructive: false, withConfirmation: true,
  confirmText: null, confirmButtonText: null, cancelButtonText: null, modalSize: 'md',
  isPivotAction: true, pivotLabel: null,
} as unknown as ActionMeta

// Stands in for a picker whose list is open in the form.
function OpenPicker() {
  const [open, setOpen] = useState(true)
  useEscapeLayer(open, () => setOpen(false))
  return open ? <span data-testid="picker">open</span> : null
}

describe('PivotActionModal with an open picker', () => {
  it('closes the picker on the first Escape and the modal on the second', async () => {
    const onClose = vi.fn()
    const view = render(
      <QueryClientProvider client={new QueryClient()}>
        <PivotActionModal actionsUrl="/api/x" resourceKey="users" action={action} selectedIds={[1]} onSuccess={() => {}} onClose={onClose} />
        <OpenPicker />
      </QueryClientProvider>,
    )
    await waitFor(() => expect(document.body.querySelector('[role="dialog"]')).not.toBeNull())

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(view.queryByTestId('picker')).toBeNull()
    expect(onClose).not.toHaveBeenCalled()

    fireEvent.keyDown(document, { key: 'Escape' })
    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1))
  })
})
