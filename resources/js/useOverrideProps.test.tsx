import { describe, it, expect } from 'vitest'
import { renderHook } from '@testing-library/react'
import {
  useOverrideProps,
  useOverridePropsOptional,
  OverridePropsProvider,
} from '@/hooks/useOverrideProps'
import type { OverrideProps } from '@/types'

const samplePayload = {
  schema: { uriKey: 'invoices', label: 'Invoices' },
  resource: 'invoices',
  params: {},
  record: null,
  recordId: null,
  navigate: () => undefined,
  onClose: () => undefined,
  onCreated: () => undefined,
  onUpdated: () => undefined,
  onDeleted: () => undefined,
  onEdit: () => undefined,
  onView: () => undefined,
  addToast: () => undefined,
} as unknown as OverrideProps

describe('useOverrideProps', () => {
  it('returns the payload when wrapped in <OverridePropsProvider>', () => {
    const { result } = renderHook(() => useOverrideProps(), {
      wrapper: ({ children }) => (
        <OverridePropsProvider value={samplePayload}>{children}</OverridePropsProvider>
      ),
    })
    expect(result.current.resource).toBe('invoices')
  })

  it('throws a descriptive error outside the provider', () => {
    expect(() => renderHook(() => useOverrideProps())).toThrow(/OverridePropsProvider/)
  })

  it('useOverridePropsOptional returns null outside the provider', () => {
    const { result } = renderHook(() => useOverridePropsOptional())
    expect(result.current).toBeNull()
  })

  it('useOverridePropsOptional returns the payload when wrapped', () => {
    const { result } = renderHook(() => useOverridePropsOptional(), {
      wrapper: ({ children }) => (
        <OverridePropsProvider value={samplePayload}>{children}</OverridePropsProvider>
      ),
    })
    expect(result.current).not.toBeNull()
    expect(result.current?.schema.uriKey).toBe('invoices')
  })
})
