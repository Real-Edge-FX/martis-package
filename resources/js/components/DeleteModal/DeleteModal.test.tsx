import { describe, it, expect, vi } from 'vitest'
import { useState } from 'react'
import { render, screen, fireEvent } from '@testing-library/react'
import { DeleteModal } from './DeleteModal'

/*
 * The delete confirmation is a modal dialog: it is named by its title,
 * Cancel (the least destructive choice) takes the focus when it opens, Tab
 * and Shift+Tab stay inside it, and on close the focus goes back to the
 * control that opened it.
 */

function Harness({ onConfirm = async () => {} }: { onConfirm?: () => Promise<void> }) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>Open delete</button>
      <DeleteModal
        open={open}
        resourceLabel="Profile"
        isSoftDelete={false}
        onConfirm={async () => {
          await onConfirm()
          setOpen(false)
        }}
        onCancel={() => setOpen(false)}
      />
    </>
  )
}

function openModal(): HTMLElement {
  const opener = screen.getByRole('button', { name: 'Open delete' })
  opener.focus()
  fireEvent.click(opener)
  return opener
}

describe('DeleteModal focus and naming', () => {
  it('is named by its title and described by its message', () => {
    render(<Harness />)
    openModal()

    const dialog = screen.getByRole('dialog')
    const title = document.getElementById(dialog.getAttribute('aria-labelledby') ?? '')
    const body = document.getElementById(dialog.getAttribute('aria-describedby') ?? '')
    expect(title?.tagName).toBe('H3')
    expect(title?.textContent).toContain('Profile')
    expect(body?.className).toContain('martis-modal-body')
    expect(screen.getByRole('dialog', { name: /Profile/ })).toBe(dialog)
  })

  it('focuses Cancel when it opens', () => {
    render(<Harness />)
    openModal()

    const footerButtons = screen.getByRole('dialog').querySelectorAll('.martis-modal-foot button')
    expect(document.activeElement).toBe(footerButtons[0])
  })

  it('keeps Tab and Shift+Tab inside the dialog', () => {
    render(<Harness />)
    openModal()

    const buttons = Array.from(screen.getByRole('dialog').querySelectorAll<HTMLElement>('button'))
    const first = buttons[0]
    const last = buttons[buttons.length - 1]

    last.focus()
    fireEvent.keyDown(document, { key: 'Tab' })
    expect(document.activeElement).toBe(first)

    fireEvent.keyDown(document, { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(last)

    // Focus that escaped the dialog is brought back into it.
    screen.getByRole('button', { name: 'Open delete' }).focus()
    fireEvent.keyDown(document, { key: 'Tab' })
    expect(document.activeElement).toBe(first)
  })

  it('gives the focus back to the control that opened it, on cancel, Escape and confirm', async () => {
    const onConfirm = vi.fn(async () => {})
    render(<Harness onConfirm={onConfirm} />)

    let opener = openModal()
    fireEvent.click(screen.getByRole('dialog').querySelectorAll<HTMLElement>('.martis-modal-foot button')[0])
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(document.activeElement).toBe(opener)

    opener = openModal()
    fireEvent.keyDown(document, { key: 'Escape' })
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(document.activeElement).toBe(opener)

    opener = openModal()
    const buttons = screen.getByRole('dialog').querySelectorAll<HTMLElement>('.martis-modal-foot button')
    fireEvent.click(buttons[buttons.length - 1])
    await vi.waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
    expect(onConfirm).toHaveBeenCalledOnce()
    expect(document.activeElement).toBe(opener)
  })
})
