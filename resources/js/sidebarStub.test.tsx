import { describe, expect, it, vi } from 'vitest'
import userEvent from '@testing-library/user-event'
import { render, screen } from '@testing-library/react'
import ts from 'typescript'
import * as React from 'react'
import * as JsxRuntime from 'react/jsx-runtime'
import { MemoryRouter, NavLink } from 'react-router'
import type { ComponentType } from 'react'
import type { NavigationGroup } from '@/types'
import { useModalFocus } from '@/hooks/useModalFocus'
import sidebarStub from '../../stubs/component-sidebar.tsx.stub?raw'

/**
 * The sidebar `martis:component --type=sidebar` writes, rendered: its source
 * is transpiled like a consumer build would and wired to the real router,
 * with the `/api/navigation` payload served by a stand-in `useQuery`.
 */
function generatedSidebar(groups: NavigationGroup[]): ComponentType<{ collapsed?: boolean; mobileOpen?: boolean; onMobileClose?: () => void }> {
    const source = sidebarStub.split('{{ class }}').join('Sidebar').split('{{ kebab }}').join('sidebar')
    const { outputText } = ts.transpileModule(source, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX, target: ts.ScriptTarget.ES2022 },
    })
    const runtime = {
        NavLink,
        api: {},
        useQuery: () => ({ data: groups }),
        useModalFocus,
        useTranslation: () => ({ t: (_key: string, fallback: string) => fallback }),
    }
    const modules: Record<string, unknown> = { 'react': React, 'react/jsx-runtime': JsxRuntime, '@martis/runtime': runtime }
    const module = { exports: {} as { default?: ComponentType<{ collapsed?: boolean; mobileOpen?: boolean; onMobileClose?: () => void }> } }
    const load = (specifier: string): unknown => {
        if (!(specifier in modules)) throw new Error(`the sidebar stub imports ${specifier}`)
        return modules[specifier]
    }
    new Function('require', 'module', 'exports', outputText)(load, module, module.exports)
    if (module.exports.default === undefined) throw new Error('the sidebar stub has no default export')

    return module.exports.default
}

describe('the generated sidebar override', () => {
    it('lists the items of a nested group under its label', () => {
        const Sidebar = generatedSidebar([
            {
                label: 'Content',
                items: [
                    { type: 'link', label: 'Home', url: '/home', icon: null },
                    { type: 'group', label: 'Settings', items: [{ type: 'link', label: 'Profile', url: '/profile', icon: null }] },
                    { type: 'link', label: 'Docs', url: 'https://example.com/docs', icon: null, external: true },
                ],
            },
        ])

        render(
            <MemoryRouter initialEntries={['/profile']}>
                <Sidebar />
            </MemoryRouter>,
        )

        expect(screen.getByText('Settings').className).toBe('martis-sb-subgroup-label')
        expect(screen.getByRole('link', { name: 'Profile' }).getAttribute('href')).toBe('/profile')
        expect(screen.getByRole('link', { name: 'Profile' }).className).toContain('active')
        expect(screen.getByRole('link', { name: 'Home' }).getAttribute('href')).toBe('/home')
        expect(screen.getByRole('link', { name: 'Docs' }).getAttribute('target')).toBe('_blank')
    })

    it('names its links in the collapsed rail and exposes the navigation landmark', () => {
        const Sidebar = generatedSidebar([
            { label: 'Content', items: [{ type: 'link', label: 'Home', url: '/home', icon: null }] },
        ])

        render(
            <MemoryRouter>
                <Sidebar collapsed mobileOpen={undefined} />
            </MemoryRouter>,
        )

        const navigation = screen.getByRole('navigation', { name: 'Main navigation' })
        expect(navigation.id).toBe('martis-navigation')
        expect(screen.getByRole('link', { name: 'Home' }).getAttribute('aria-label')).toBe('Home')
    })

    it('is a modal dialog on mobile that closes on Escape', async () => {
        const onMobileClose = vi.fn()
        const Sidebar = generatedSidebar([
            { label: 'Content', items: [{ type: 'link', label: 'Home', url: '/home', icon: null }] },
        ])

        render(
            <MemoryRouter>
                <Sidebar mobileOpen onMobileClose={onMobileClose} />
            </MemoryRouter>,
        )

        const dialog = screen.getByRole('dialog', { name: 'Main navigation' })
        expect(dialog.id).toBe('martis-sidebar')
        expect(dialog.hasAttribute('inert')).toBe(false)
        expect(document.activeElement).toBe(screen.getByRole('link', { name: 'Home' }))

        await userEvent.setup().keyboard('{Escape}')
        expect(onMobileClose).toHaveBeenCalledTimes(1)
    })
})
