import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, render, renderHook, screen } from '@testing-library/react'
import { ActionResponseModalProvider, useActionResponseModal, type ActionResponseModalProps } from './ActionResponseModalHost'
import { componentRegistry } from '@/lib/componentRegistry'

function TokenModal({ data, onClose }: ActionResponseModalProps) {
  return (
    <div role="dialog">
      <p>Token: {String(data.token)}</p>
      <button type="button" onClick={onClose}>Done</button>
      <button type="button" onClick={onClose}>Close again</button>
    </div>
  )
}

afterEach(() => componentRegistry.unregister('generated-token'))

describe('ActionResponseModalProvider', () => {
  it('renders the registered component with the data, and calls onClose once', () => {
    componentRegistry.register('generated-token', TokenModal)
    const onClose = vi.fn()
    let show: ReturnType<typeof useActionResponseModal> = null
    function Grab() {
      show = useActionResponseModal()
      return null
    }
    render(<ActionResponseModalProvider><Grab /></ActionResponseModalProvider>)

    let shown = false
    act(() => {
      shown = show!('generated-token', { token: 's3cret' }, onClose)
    })

    expect(shown).toBe(true)
    expect(screen.getByText('Token: s3cret')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Done' }))
    expect(onClose).toHaveBeenCalledTimes(1)
    expect(screen.queryByText('Token: s3cret')).toBeNull()
  })

  it('answers false for an unregistered component', () => {
    const { result } = renderHook(() => useActionResponseModal(), { wrapper: ActionResponseModalProvider })

    expect(result.current!('missing', {}, () => {})).toBe(false)
  })

  it('is null outside the provider', () => {
    const { result } = renderHook(() => useActionResponseModal())

    expect(result.current).toBeNull()
  })
})
