import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { createMemoryRouter, Outlet, RouterProvider, type RouteObject } from 'react-router'
import { DynamicCrumbProvider, useDynamicCrumb } from '@/contexts/DynamicCrumbContext'
import { Breadcrumbs } from './Breadcrumbs'

function renderTrail(children: RouteObject[], path: string): void {
  const routes: RouteObject[] = [
    {
      path: '/',
      element: (
        <>
          <Breadcrumbs />
          <Outlet />
        </>
      ),
      children,
    },
  ]

  render(
    <DynamicCrumbProvider>
      <RouterProvider router={createMemoryRouter(routes, { initialEntries: [path] })} />
    </DynamicCrumbProvider>,
  )
}

describe('Breadcrumbs', () => {
  it('shows a crumb label as given, without translating it', async () => {
    renderTrail([{ path: 'findings', element: <p>page</p>, handle: { crumbLabel: 'Findings: open' } }], '/findings')

    const trail = await screen.findByRole('navigation', { name: 'Breadcrumbs' })
    expect(trail.textContent).toContain('Findings: open')
  })

  it('still translates the crumb key of a package route', async () => {
    renderTrail([{ path: 'profile', element: <p>page</p>, handle: { crumb: 'profile' } }], '/profile')

    const trail = await screen.findByRole('navigation', { name: 'Breadcrumbs' })
    expect(trail.textContent).toContain('Profile')
  })

  it('lets the page replace the crumb label with a dynamic one', async () => {
    function FindingPage() {
      useDynamicCrumb('F-0192')
      return <p>page</p>
    }
    renderTrail([{ path: 'findings/:id', element: <FindingPage />, handle: { crumbLabel: 'Findings' } }], '/findings/1')

    expect(await screen.findByText('F-0192')).toBeTruthy()
    expect(screen.getByRole('navigation', { name: 'Breadcrumbs' }).textContent).not.toContain('Findings')
  })

  it('shows a blank instead of the static label while the page says its label is pending', async () => {
    function LoadingPage() {
      useDynamicCrumb(null, { pending: true })
      return <p>page</p>
    }
    renderTrail([{ path: 'findings/:id', element: <LoadingPage />, handle: { crumbLabel: 'Findings' } }], '/findings/1')

    const trail = await screen.findByRole('navigation', { name: 'Breadcrumbs' })
    expect(trail.querySelector('.martis-breadcrumb-pending')).not.toBeNull()
    expect(trail.textContent).not.toContain('Findings')
  })

  it('keeps a crumbPending route blank while its page has published nothing', async () => {
    function SilentPage() {
      return <p>page</p>
    }
    renderTrail([{ path: 'silent', element: <SilentPage />, handle: { crumb: 'dashboard', crumbPending: true } }], '/silent')

    const trail = await screen.findByRole('navigation', { name: 'Breadcrumbs' })
    expect(trail.querySelector('.martis-breadcrumb-pending')).not.toBeNull()
    expect(trail.textContent).not.toContain('Dashboard')
  })

  it('uses the static key of a crumbPending route once its page settled without a label', async () => {
    function SettledPage() {
      useDynamicCrumb(null)
      return <p>page</p>
    }
    renderTrail([{ path: 'settled', element: <SettledPage />, handle: { crumb: 'dashboard', crumbPending: true } }], '/settled')

    const trail = await screen.findByRole('navigation', { name: 'Breadcrumbs' })
    expect(trail.textContent).toContain('Dashboard')
    expect(trail.querySelector('.martis-breadcrumb-pending')).toBeNull()
  })
})
