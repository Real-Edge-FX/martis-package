import { describe, expect, it } from 'vitest'
import { isValidElement, type ReactNode } from 'react'
import { render, screen } from '@testing-library/react'
import { createMemoryRouter, RouterProvider, type RouteObject } from 'react-router-dom'
import { layoutRegistry } from '@/lib/layoutRegistry'
import { ResourceLayout } from '@/components/ResourceLayout'
import { router } from '@/router'

/**
 * Per-resource page layouts (v1.38.0): `layoutRegistry.register(uriKey,
 * Layout)` wraps every page of that resource in `Layout`, inside the
 * shell. The registry is a module singleton with no reset, so each test
 * registers under a key of its own.
 */

function renderResourcePage(path: string): void {
    const routes: RouteObject[] = [
        {
            element: <ResourceLayout />,
            children: [
                { path: '/resources/:resource', element: <p>index page</p> },
                { path: '/resources/:resource/:id', element: <p>detail page</p> },
            ],
        },
    ]

    render(<RouterProvider router={createMemoryRouter(routes, { initialEntries: [path] })} />)
}

describe('ResourceLayout', () => {
    it('wraps a page of a resource in the layout registered for that resource', async () => {
        layoutRegistry.register('layout-test-invoices', ({ children }: { children: ReactNode }) => (
            <section aria-label="Invoices layout">{children}</section>
        ))

        renderResourcePage('/resources/layout-test-invoices/7')

        const layout = await screen.findByRole('region', { name: 'Invoices layout' })
        expect(layout.contains(screen.getByText('detail page'))).toBe(true)
    })

    it('renders the page of a resource with no registered layout as is', async () => {
        layoutRegistry.register('layout-test-other', ({ children }: { children: ReactNode }) => (
            <section aria-label="Other layout">{children}</section>
        ))

        renderResourcePage('/resources/layout-test-posts')

        expect(await screen.findByText('index page')).toBeTruthy()
        expect(screen.queryByRole('region')).toBeNull()
    })

    it('sits above every resource route of the app router', () => {
        // The pathless ResourceLayout route has to be an ancestor of each
        // `resources/:resource...` route, or that page skips the layout.
        const resourceRoutes: Record<string, boolean> = {}
        const visit = (routes: RouteObject[], wrapped: boolean): void => {
            for (const route of routes) {
                const inLayout = wrapped || (isValidElement(route.element) && route.element.type === ResourceLayout)
                if (route.path?.startsWith('resources/')) resourceRoutes[route.path] = inLayout
                if (route.children) visit(route.children, inLayout)
            }
        }
        // The data router keeps the route objects it was created with,
        // `element` included; its agnostic type just does not declare it.
        visit(router.routes as RouteObject[], false)

        expect(resourceRoutes).toEqual({
            'resources/:resource': true,
            'resources/:resource/lens/:lens': true,
            'resources/:resource/create': true,
            'resources/:resource/:id': true,
            'resources/:resource/:id/edit': true,
        })
    })
})
