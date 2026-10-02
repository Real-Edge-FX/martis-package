import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, waitFor } from '@testing-library/react'

/*
 * Hardening (F101 family): the wizard injects the QR code the server
 * generated with `dangerouslySetInnerHTML`. It goes through the sanitiser's
 * `svg` profile now, which keeps the image and drops anything executable.
 */

const { post } = vi.hoisted(() => ({ post: vi.fn() }))

vi.mock('@/lib/api', () => ({ api: { post: (...args: unknown[]) => post(...args) }, ApiError: class extends Error {} }))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast: vi.fn() }) }))

import { TwoFactorWizard } from './TwoFactorWizard'

const QR =
  '<?xml version="1.0"?>\n<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" width="220px" height="220px" viewBox="0 0 220 220">'
  + '<rect x="0" y="0" width="220" height="220" fill="#ffffff" fill-opacity="1"/>'
  + '<path fill="#000000" fill-opacity="1" d="M11,11L53,11L53,17L11,17Z"/></svg>'

beforeEach(() => {
  post.mockReset()
})

async function renderWizard(svg: string) {
  post.mockResolvedValue({ qr_code_svg: svg, secret: 'ABCDEF' })
  render(<TwoFactorWizard visible onClose={() => {}} onEnabled={() => {}} />)
  await waitFor(() => expect(qrCode()).not.toBeNull())
}

/** The QR code, told from the icons of the dialog by its view box. */
const qrCode = (): SVGElement | null => document.body.querySelector('svg[viewBox="0 0 220 220"]')

describe('TwoFactorWizard QR code', () => {
  it('renders the QR code the server generated', async () => {
    await renderWizard(QR)

    const svg = qrCode() as SVGElement
    expect(svg.getAttribute('width')).toBe('220px')
    expect(svg.querySelector('rect')).not.toBeNull()
    expect(svg.querySelector('path')?.getAttribute('d')).toBe('M11,11L53,11L53,17L11,17Z')
  })

  it('drops a script and a handler an image carried', async () => {
    await renderWizard(QR.replace('<rect', '<script>window.__pwned = 1</script><rect onclick="window.__pwned = 2"'))

    const svg = qrCode() as SVGElement
    expect(svg.querySelector('script')).toBeNull()
    expect(svg.querySelector('rect')?.getAttribute('onclick')).toBeNull()
    expect((window as unknown as Record<string, unknown>).__pwned).toBeUndefined()
  })
})
