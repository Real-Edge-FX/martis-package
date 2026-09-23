import { describe, expect, it } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { martisRuntime } from '@/lib/martisRuntime'
import runtimeShim from '../../stubs/extensions/runtime-shim.mjs.stub?raw'
import viteExtensionsConfig from '../../stubs/extensions/vite.extensions.config.ts.stub?raw'
import type { FieldDefinition } from '@/types'

const docs = import.meta.glob('../../docs/*.md', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

/**
 * Contract tests for the consumer-extension runtime bag. Three concerns:
 *
 * 1. The exports a consumer reaches for are present and callable.
 *    Consumer-extension bundles bind to `window.Martis.runtime.X` at
 *    runtime — if a name silently disappears, the consumer crashes
 *    only after deploy. These tests guard the contract.
 *
 * 2. The scaffold `martis:install` publishes resolves them: the runtime
 *    shim names every member (and every name the docs import), and the
 *    vite aliases send `@martis/runtime` and the legacy paths to it. A
 *    gap here fails the consumer's build, not ours.
 *
 * 3. The FieldInput export added in v1.14.0 actually routes to the
 *    right component for the field type and threads `onChange`
 *    through. Catches regressions where someone refactors
 *    `FieldRenderer` and the runtime export quietly stops resolving.
 */

describe('martisRuntime', () => {
    it('exposes the documented contract surface', () => {
        // Hooks
        expect(martisRuntime.useAuth).toBeTypeOf('function')
        expect(martisRuntime.useToast).toBeTypeOf('function')
        expect(martisRuntime.useIsMobile).toBeTypeOf('function')

        // Lib
        expect(martisRuntime.api).toBeTypeOf('object')
        expect(martisRuntime.ApiError).toBeTypeOf('function')
        expect(martisRuntime.config).toBeTypeOf('object')

        // Event bus (pluggable real-time feed)
        expect(martisRuntime.martisEventBus).toBeTypeOf('object')

        // Layout components
        expect(martisRuntime.AuthFrame).toBeTypeOf('function')
        expect(martisRuntime.Sidebar).toBeTypeOf('function')
        expect(martisRuntime.Topbar).toBeTypeOf('function')
        expect(martisRuntime.Footer).toBeTypeOf('function')

        // Field renderer (v1.14.0)
        expect(martisRuntime.FieldInput).toBeTypeOf('function')
        expect(martisRuntime.FieldDisplay).toBeTypeOf('function')

        // Relation parent provider (v1.38.0)
        expect(martisRuntime.NestedParentProvider).toBeTypeOf('function')

        // Composition components
        expect(martisRuntime.DrawerShell).toBeTypeOf('function')
        // PrimeReact Tooltip is a forwardRef object, not a plain function.
        expect(martisRuntime.Tooltip).toBeDefined()

        // PrimeReact filter controls + portal primitive (v1.29.0)
        expect(martisRuntime.Dropdown).toBeDefined()
        expect(martisRuntime.MultiSelect).toBeDefined()
        expect(martisRuntime.createPortal).toBeTypeOf('function')

        // Shared field-form harness (v1.20.0)
        expect(martisRuntime.useMartisForm).toBeTypeOf('function')
        expect(martisRuntime.FieldsForm).toBeTypeOf('function')
        expect(martisRuntime.useToolFields).toBeTypeOf('function')
        expect(martisRuntime.useRevalidateOnFocus).toBeTypeOf('function')

        // 3rd-party re-exports
        expect(martisRuntime.reactRouterDom).toBeTypeOf('object')
        expect(martisRuntime.reactI18next).toBeTypeOf('object')
        expect(martisRuntime.tanstackReactQuery).toBeTypeOf('object')
    })

    it('the consumer-extension shim re-exports every runtime member under its own name, and nothing else', () => {
        // `@martis/runtime` resolves to the published shim in a consumer build,
        // and the shim reads each named export off `window.Martis.runtime`: a
        // name the shim lacks fails the consumer's build, and a name the
        // runtime lacks imports `undefined`. The third-party modules ride on
        // the runtime as namespaces and reach consumers through their own
        // shims and the hooks the shim flattens, not as named exports.
        const reexported = [...runtimeShim.matchAll(/^export const (\w+) = R\.(\w+)$/gm)]

        for (const [, name, key] of reexported) {
            expect(key).toBe(name)
            expect(martisRuntime).toHaveProperty(key)
        }

        const named = new Set(reexported.map(([, name]) => name))
        const namespaces = ['reactRouterDom', 'reactI18next', 'tanstackReactQuery']
        expect(Object.keys(martisRuntime).filter((key) => !namespaces.includes(key) && !named.has(key))).toEqual([])
    })

    it('every name the docs import from @martis/runtime is a shim export', () => {
        // The docs examples are copied into consumer extensions, whose build
        // fails on any imported name the shim does not export.
        const exported = new Set([...runtimeShim.matchAll(/^export const (\w+) =/gm)].map(([, name]) => name))
        const missing: string[] = []

        for (const [file, source] of Object.entries(docs)) {
            const imports = source.matchAll(/^import\s+(type\s+)?((?:\w+\s*,\s*)?(?:\{[^}]*\}|\*\s*as\s+\w+|\w+))\s+from\s+['"]@martis\/runtime['"]/gm)
            for (const [, typeOnly, clause] of imports) {
                if (typeOnly) continue
                const specifiers = (clause.match(/\{([^}]*)\}/)?.[1] ?? '').split(',').map((s) => s.trim())
                for (const specifier of specifiers) {
                    if (specifier === '' || specifier.startsWith('type ')) continue
                    const name = specifier.split(/\s+as\s+/)[0]
                    if (!exported.has(name)) missing.push(`${file.replace('../../', '')}: ${name}`)
                }
            }
        }

        expect(missing).toEqual([])
    })

    it('the consumer vite config sends @martis/runtime and every legacy runtime path to the whole shim', () => {
        // Mirrors Vite's alias plugin: the first entry whose `find` matches
        // wins (a string matches the whole specifier or a path prefix), and
        // only the matched text is replaced, so a regex that matches the
        // start of `@/lib/api` would leave `api` on the shim path.
        const entries: { find: string | RegExp; replacement: string }[] = [...viteExtensionsConfig.matchAll(/\{find: (?:'([^']+)'|\/(.+?)\/([a-z]*)), replacement: (\w+)\}/g)]
            .map(([, literal, source, flags, replacement]) => ({ find: literal ?? new RegExp(source, flags), replacement }))
        const resolve = (id: string) => {
            const entry = entries.find(({ find }) => (typeof find === 'string' ? id === find || id.startsWith(`${find}/`) : find.test(id)))
            return entry ? id.replace(entry.find, entry.replacement) : id
        }
        const expected: Record<string, string> = {
            'react': 'reactShim',
            'react-dom': 'reactShim',
            'react/jsx-runtime': 'jsxRuntimeShim',
            'react-router-dom': 'routerShim',
            'react-i18next': 'i18nextShim',
            '@tanstack/react-query': 'queryShim',
            '@martis/runtime': 'runtimeShim',
            // The paths override stubs published before v1.10.0 import.
            '@/contexts/AuthContext': 'runtimeShim',
            '@/lib/api': 'runtimeShim',
            '@/components/auth/AuthFrame': 'runtimeShim',
            '@martis/martis/hooks/useIsMobile': 'runtimeShim',
        }

        expect(Object.fromEntries(Object.keys(expected).map((id) => [id, resolve(id)]))).toEqual(expected)
    })

    it('FieldInput renders a text input for type=text and threads onChange', () => {
        // Text picks a native <input>, which is robust in jsdom.
        // Heavier types (select uses PrimeReact Dropdown, BelongsTo
        // pulls async options) are exercised by their own field-level
        // tests — this test only proves the runtime export resolves
        // through FieldRenderer to the right component and that the
        // onChange contract is preserved through the runtime layer.
        const field: FieldDefinition = {
            type: 'text',
            attribute: 'title',
            label: 'Title',
        } as unknown as FieldDefinition

        const calls: unknown[] = []
        const onChange = (v: unknown) => calls.push(v)

        render(
            <martisRuntime.FieldInput
                field={field}
                value="hello"
                onChange={onChange}
            />,
        )

        const input = screen.getByDisplayValue('hello') as HTMLInputElement
        expect(input.tagName).toBe('INPUT')

        fireEvent.change(input, { target: { value: 'world' } })
        expect(calls).toEqual(['world'])
    })

    it('FieldDisplay renders the formatted value for type=select', () => {
        const field: FieldDefinition = {
            type: 'select',
            attribute: 'status',
            label: 'Status',
            options: [
                { value: 'draft', label: 'Draft' },
                { value: 'published', label: 'Published' },
            ],
            displayUsingLabels: true,
        } as unknown as FieldDefinition

        render(
            <martisRuntime.FieldDisplay field={field} value="published" />,
        )

        // The display variant resolves the option label.
        expect(screen.getByText('Published')).toBeTruthy()
    })
})
