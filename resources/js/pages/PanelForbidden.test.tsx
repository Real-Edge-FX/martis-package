import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router'

const signOut = vi.fn(() => Promise.resolve())
vi.mock('@/lib/signOut', () => ({ signOut: () => signOut() }))

import { PanelForbiddenPage } from './PanelForbidden'

describe('PanelForbiddenPage', () => {
  it('explains the refusal and signs the user out', () => {
    render(<MemoryRouter><PanelForbiddenPage /></MemoryRouter>)

    expect(screen.getByText('No access to this panel')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: /Sign out/ }))

    expect(signOut).toHaveBeenCalledTimes(1)
  })

  it('offers no documentation link', () => {
    render(<MemoryRouter><PanelForbiddenPage /></MemoryRouter>)

    expect(screen.queryByRole('link')).toBeNull()
  })
})
