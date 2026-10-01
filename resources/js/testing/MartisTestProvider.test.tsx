import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { useAuth } from '@/contexts/AuthContext'
import { usePreferences } from '@/contexts/PreferencesContext'
import { useToast } from '@/contexts/ToastContext'
import { config } from '@/lib/config'
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
