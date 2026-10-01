import { afterEach, describe, expect, it, vi } from 'vitest'
import { StrictMode, useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { useAuth } from '@/contexts/AuthContext'
import { usePreferences } from '@/contexts/PreferencesContext'
import { useToast } from '@/contexts/ToastContext'
import { config } from '@/lib/config'
import i18n from '@/lib/i18n'
import { allowDataRouterNavigation } from '@/test-support/dataRouterNavigation'
import { MartisTestProvider } from './MartisTestProvider'

allowDataRouterNavigation()

function Probe() {
  const { user } = useAuth()
  const { prefs } = usePreferences()
  const { addToast } = useToast()
  const { t } = useTranslation('reports')
  const { reportId } = useParams()
  const query = useQuery({ queryKey: ['probe'], queryFn: async () => 'loaded' })

  return (
    <div>
      <p>user:{user?.name ?? 'guest'}</p>
      <p>theme:{prefs.theme}</p>
      <p>title:{t('title')}</p>
      <p>report:{reportId ?? '-'}</p>
      <p>query:{query.data ?? '…'}</p>
      <button type="button" onClick={() => addToast('success', 'Saved')}>toast</button>
      <Link to="/reports/9">next</Link>
    </div>
  )
}

afterEach(() => vi.unstubAllGlobals())

describe('MartisTestProvider', () => {
  it('renders a component with the providers of the shell, and calls no server', async () => {
    const fetchSpy = vi.fn()
    vi.stubGlobal('fetch', fetchSpy)
    render(
      <MartisTestProvider preferences={{ theme: 'light' }} translations={{ reports: { title: 'Reports' } }} path="/reports/:reportId" initialPath="/reports/3">
        <Probe />
      </MartisTestProvider>,
    )

    expect(screen.getByText('user:Test User')).toBeTruthy()
    expect(screen.getByText('theme:light')).toBeTruthy()
    expect(screen.getByText('title:Reports')).toBeTruthy()
    expect(screen.getByText('report:3')).toBeTruthy()
    expect(await screen.findByText('query:loaded')).toBeTruthy()
    fireEvent.click(screen.getByText('toast'))
    fireEvent.click(screen.getByText('next'))
    expect(await screen.findByText('report:9')).toBeTruthy()
    expect(fetchSpy).not.toHaveBeenCalled()
  })

  it('renders a guest with user={null}', () => {
    render(<MartisTestProvider user={null}><Probe /></MartisTestProvider>)

    expect(screen.getByText('user:guest')).toBeTruthy()
  })

  it('merges config into window.MartisConfig while it is mounted, then restores it', () => {
    const { unmount } = render(<MartisTestProvider config={{ brand: 'Acme Admin' }}><p>x</p></MartisTestProvider>)
    expect(config.brand).toBe('Acme Admin')

    unmount()
    expect(config.brand).toBeUndefined()
  })
})

/** Reads `config.brand` on every render, and re-renders on a click. */
function Brand() {
  const [clicks, setClicks] = useState(0)

  return <button type="button" onClick={() => setClicks(clicks + 1)}>brand:{String(config.brand)}:{clicks}</button>
}

function Title({ namespace }: { namespace: string }) {
  const { t } = useTranslation(namespace)

  return <h1>{t('title')}</h1>
}

const target = config as Record<string, unknown>

describe('MartisTestProvider isolation', () => {
  it('keeps the config override under StrictMode, after a re-render', () => {
    const { unmount } = render(<StrictMode><MartisTestProvider config={{ brand: 'Acme' }}><Brand /></MartisTestProvider></StrictMode>)
    expect(screen.getByRole('button').textContent).toBe('brand:Acme:0')

    fireEvent.click(screen.getByRole('button'))
    expect(screen.getByRole('button').textContent).toBe('brand:Acme:1')

    unmount()
    expect(config.brand).toBeUndefined()
  })

  it('restores only the keys of the provider that unmounts', () => {
    const first = render(<MartisTestProvider config={{ brand: 'A' }}><p>a</p></MartisTestProvider>)
    const second = render(<MartisTestProvider config={{ footer: 'B' }}><p>b</p></MartisTestProvider>)
    expect([target.brand, target.footer]).toEqual(['A', 'B'])

    first.unmount()
    expect([target.brand, target.footer]).toEqual([undefined, 'B'])

    second.unmount()
    expect('footer' in target).toBe(false)
  })

  it('gives a shared key back to the provider still mounted, whichever unmounts first', () => {
    const outer = render(<MartisTestProvider config={{ brand: 'Outer' }}><p>o</p></MartisTestProvider>)
    const inner = render(<MartisTestProvider config={{ brand: 'Inner' }}><p>i</p></MartisTestProvider>)
    expect(target.brand).toBe('Inner')

    outer.unmount()
    expect(target.brand).toBe('Inner')

    inner.unmount()
    expect('brand' in target).toBe(false)

    const again = render(<MartisTestProvider config={{ brand: 'Outer' }}><p>o</p></MartisTestProvider>)
    const later = render(<MartisTestProvider config={{ brand: 'Inner' }}><p>i</p></MartisTestProvider>)
    later.unmount()
    expect(target.brand).toBe('Outer')
    again.unmount()
    expect('brand' in target).toBe(false)
  })

  it('puts back a key the app config already had', () => {
    target.footer = 'Original footer'
    try {
      const { unmount } = render(<MartisTestProvider config={{ footer: 'Test footer' }}><p>x</p></MartisTestProvider>)
      expect(target.footer).toBe('Test footer')

      unmount()
      expect(target.footer).toBe('Original footer')
    } finally {
      delete target.footer
    }
  })

  it('removes the translations it added when it unmounts, so a later test sees the key', () => {
    const { unmount } = render(<MartisTestProvider translations={{ kit_isolation: { title: 'Translated' } }}><Title namespace="kit_isolation" /></MartisTestProvider>)
    expect(screen.getByRole('heading').textContent).toBe('Translated')

    unmount()
    expect(i18n.hasResourceBundle('en', 'kit_isolation')).toBe(false)
    render(<MartisTestProvider><Title namespace="kit_isolation" /></MartisTestProvider>)
    expect(screen.getByRole('heading').textContent).toBe('title')
  })

  it('leaves a bundle the test setup registered as it found it', () => {
    i18n.addResourceBundle('en', 'kit_setup', { title: 'From setup', kept: 'Kept' })
    try {
      const { unmount } = render(
        <MartisTestProvider translations={{ kit_setup: { title: 'From the test', extra: 'Extra' } }}>
          <Title namespace="kit_setup" />
        </MartisTestProvider>,
      )
      expect(screen.getByRole('heading').textContent).toBe('From the test')
      expect(i18n.getResourceBundle('en', 'kit_setup')).toEqual({ title: 'From the test', kept: 'Kept', extra: 'Extra' })

      unmount()
      expect(i18n.getResourceBundle('en', 'kit_setup')).toEqual({ title: 'From setup', kept: 'Kept' })
    } finally {
      i18n.removeResourceBundle('en', 'kit_setup')
    }
  })

  it('keeps the translations of a provider still mounted when another unmounts', () => {
    const first = render(<MartisTestProvider translations={{ kit_shared: { title: 'First' } }}><p>a</p></MartisTestProvider>)
    const second = render(<MartisTestProvider translations={{ kit_shared: { subtitle: 'Second' } }}><p>b</p></MartisTestProvider>)
    expect(i18n.getResourceBundle('en', 'kit_shared')).toEqual({ title: 'First', subtitle: 'Second' })

    second.unmount()
    expect(i18n.getResourceBundle('en', 'kit_shared')).toEqual({ title: 'First' })

    first.unmount()
    expect(i18n.hasResourceBundle('en', 'kit_shared')).toBe(false)
  })
})
