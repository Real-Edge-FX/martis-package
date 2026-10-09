import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render } from '@testing-library/react'

const { configMock } = vi.hoisted(() => ({ configMock: {} as Record<string, unknown> }))

vi.mock('@/lib/config', () => ({ config: configMock, BASE_PATH: '/martis', API_BASE_URL: 'http://localhost/martis' }))
vi.mock('@/components/auth/AuthControls', () => ({ AuthControls: () => null }))
vi.mock('@/components/MartisTooltip', () => ({ MartisTooltip: () => null }))
vi.mock('@images/logo.png', () => ({ default: '/bundled-logo.png' }))

import { AuthFrame } from './AuthFrame'

function brandImages() {
  return [...document.querySelectorAll('.martis-auth-brand img')].map((img) => ({
    src: img.getAttribute('src'),
    className: img.className,
  }))
}

beforeEach(() => {
  for (const key of Object.keys(configMock)) delete configMock[key]
  configMock.brand = 'Acme'
})

describe('AuthFrame brand row', () => {
  it('renders the authentication lockup pair when it is set, over the menu lockup (v2.6.0)', () => {
    Object.assign(configMock, {
      logo: '/brand/menu-light.svg',
      logoDark: '/brand/menu-dark.svg',
      authLogo: '/brand/auth-light.svg',
      authLogoDark: '/brand/auth-dark.svg',
    })
    render(<AuthFrame>form</AuthFrame>)

    expect(document.querySelector('.martis-auth-brand')?.getAttribute('data-mode')).toBe('logo')
    expect(brandImages()).toEqual([
      { src: '/brand/auth-light.svg', className: 'martis-brand-img--light' },
      { src: '/brand/auth-dark.svg', className: 'martis-brand-img--dark' },
    ])
  })

  it('serves a single authentication lockup to both themes', () => {
    Object.assign(configMock, { logo: '/brand/menu-light.svg', authLogo: '/brand/auth.svg' })
    render(<AuthFrame>form</AuthFrame>)

    expect(brandImages()).toEqual([{ src: '/brand/auth.svg', className: '' }])
  })

  it('falls back to the menu lockup without an authentication lockup', () => {
    Object.assign(configMock, { logo: '/brand/menu-light.svg', logoDark: '/brand/menu-dark.svg' })
    render(<AuthFrame>form</AuthFrame>)

    expect(brandImages().map((image) => image.src)).toEqual(['/brand/menu-light.svg', '/brand/menu-dark.svg'])
  })

  it('falls back to the bundled logo when nothing is set', () => {
    render(<AuthFrame>form</AuthFrame>)

    expect(brandImages().map((image) => image.src)).toEqual(['/bundled-logo.png'])
  })
})
