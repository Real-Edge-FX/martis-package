import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { render, act, screen } from '@testing-library/react'
import { ToastProvider, useToast } from '@/contexts/ToastContext'

/**
 * Regression test for the v1.8.7 toast double-close bug.
 *
 * The custom `content` renderer in ToastProvider already paints a
 * Phosphor `<XIcon>` button styled with `.martis-toast-close`. The
 * PrimeReact `<Toast>` was being given `closable: true`, which made
 * it draw a SECOND default close glyph next to ours — operators saw
 * two X icons stacked.
 *
 * The fix flips `closable` to `false` so only the custom button shows.
 * This test asserts there is exactly one close button per toast.
 */

function Trigger({ message }: { message: string }) {
  const { addToast } = useToast()
  return (
    <button
      type="button"
      onClick={() => addToast('success', message)}
    >
      fire
    </button>
  )
}

describe('Toast', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('renders exactly one close button per toast', async () => {
    render(
      <ToastProvider>
        <Trigger message="Record created successfully." />
      </ToastProvider>,
    )

    const trigger = screen.getByRole('button', { name: 'fire' })
    await act(async () => {
      trigger.click()
      await new Promise((r) => setTimeout(r, 50))
    })

    const closeButtons = document.querySelectorAll('.martis-toast-close, .p-toast-icon-close')
    expect(closeButtons.length).toBe(1)
  })
})
