import { describe, expect, it, vi } from 'vitest'
import { act, render, screen } from '@testing-library/react'

// addToast() takes a link (v2.15.0), also through @martis/runtime: an
// extension may build it from stored data, so only a safe href renders.

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))

import { ToastProvider, useToast } from './ToastContext'

let add: ReturnType<typeof useToast>['addToast'] = () => {}
function Grab() {
  add = useToast().addToast
  return null
}

function renderProvider() {
  render(
    <ToastProvider>
      <Grab />
    </ToastProvider>,
  )
}

describe('a toast with a link', () => {
  it('renders a safe link that opens in a new tab', async () => {
    renderProvider()
    act(() => add('warning', 'Blocked', { link: { href: 'https://example.com/a', label: 'Open the page' } }))

    const link = await screen.findByRole('link', { name: 'Open the page' })
    expect(link.getAttribute('href')).toBe('https://example.com/a')
    expect(link.getAttribute('target')).toBe('_blank')
    expect(link.getAttribute('rel')).toBe('noopener noreferrer')
  })

  it('drops a javascript: link and keeps the message', async () => {
    renderProvider()
    act(() => add('warning', 'Blocked', { link: { href: 'javascript:alert(1)', label: 'Open the page' } }))

    expect(await screen.findByText('Blocked')).toBeTruthy()
    expect(screen.queryByRole('link', { name: 'Open the page' })).toBeNull()
  })
})
