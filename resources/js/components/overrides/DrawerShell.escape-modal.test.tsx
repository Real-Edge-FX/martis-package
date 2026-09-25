import { describe, it, expect, vi } from 'vitest'
import { useState } from 'react'
import { render, screen, fireEvent, act } from '@testing-library/react'
import { DrawerShell } from './DrawerShell'
import { DeleteModal } from '@/components/DeleteModal'
import { KeyboardShortcutsHelp } from '@/components/KeyboardShortcutsHelp'

/*
 * Escape in a modal opened over a drawer closes the modal only. The
 * drawer's Escape handler did not check the modal lock the back button
 * already uses (`getModalLockCount()`), so the same keystroke closed the
 * delete confirmation and the drawer under it.
 */

function DrawerWithDeleteModal({ onClose }: { onClose: () => void }) {
  const [open, setOpen] = useState(false)
  return (
    <DrawerShell title="Profile" onClose={onClose}>
      <button type="button" onClick={() => setOpen(true)}>Open delete</button>
      <DeleteModal open={open} resourceLabel="Profile" isSoftDelete={false} onConfirm={async () => setOpen(false)} onCancel={() => setOpen(false)} />
    </DrawerShell>
  )
}

async function settle() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 400))
  })
}

describe('DrawerShell Escape with a modal open', () => {
  it('closes the delete confirmation and leaves the drawer open', async () => {
    const onClose = vi.fn()
    render(<DrawerWithDeleteModal onClose={onClose} />)

    fireEvent.click(screen.getByRole('button', { name: 'Open delete' }))
    expect(screen.getByRole('dialog', { name: /Profile/ })).toBeTruthy()

    fireEvent.keyDown(document, { key: 'Escape' })
    await settle()

    expect(screen.queryByRole('dialog', { name: /Delete/ })).toBeNull()
    expect(onClose).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'Open delete' })).toBeTruthy()
  })

  it('closes the keyboard shortcuts help and leaves the drawer open', async () => {
    const onClose = vi.fn()
    render(
      <DrawerShell title="Profile" onClose={onClose}>
        <KeyboardShortcutsHelp />
      </DrawerShell>,
    )

    fireEvent.keyDown(document, { key: '?', shiftKey: true })
    expect(await screen.findByRole('dialog')).toBeTruthy()

    fireEvent.keyDown(document, { key: 'Escape' })
    await settle()

    expect(onClose).not.toHaveBeenCalled()
  })

  it('closes the drawer on Escape when no modal is open (control)', async () => {
    const onClose = vi.fn()
    render(<DrawerWithDeleteModal onClose={onClose} />)

    fireEvent.keyDown(document, { key: 'Escape' })
    await settle()

    expect(onClose).toHaveBeenCalledOnce()
  })
})
