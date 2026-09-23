import { describe, expect, it } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { martisRuntime } from '@/lib/martisRuntime'
import { componentRegistry } from '@/lib/componentRegistry'
import { iconRegistry } from '@/lib/iconRegistry'
import { layoutRegistry } from '@/lib/layoutRegistry'
import { MartisLoader } from '@/components/Loader'
import { addShortcut, disableShortcut, listShortcuts } from '@/lib/keyboardShortcuts'
import runtimeSource from './lib/martisRuntime.ts?raw'
import runtimeShim from '../../stubs/extensions/runtime-shim.mjs.stub?raw'
import viteExtensionsConfig from '../../stubs/extensions/vite.extensions.config.ts.stub?raw'
import type { FieldDefinition } from '@/types'

const docs = import.meta.glob('../../docs/*.md', { query: '?raw', import: 'default', eager: true }) as Record<string, string>
const shims = import.meta.glob('../../stubs/extensions/*-shim.mjs.stub', { query: '?raw', import: 'default', eager: true }) as Record<string, string>
const declarations = import.meta.glob('../../stubs/extensions/*-shim.d.mts.stub', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

/**
 * The consumer vite's alias table, applied the way Vite's alias plugin
 * does: the first entry whose `find` matches wins (a string matches the
 * whole specifier or a path prefix), and only the matched text is
 * replaced, so a regex that matches the start of `@/lib/api` would leave
 * `api` on the shim path. Resolves to the replacement's variable name
 * (`runtimeShim`), or to the specifier itself when no entry matches.
 */
const aliases: { find: string | RegExp; replacement: string }[] = [...viteExtensionsConfig.matchAll(/\{find: (?:'([^']+)'|\/(.+?)\/([a-z]*)), replacement: (\w+)\}/g)]
    .map(([, literal, source, flags, replacement]) => ({ find: literal ?? new RegExp(source, flags), replacement }))

function resolveAlias(id: string): string {
    const entry = aliases.find(({ find }) => (typeof find === 'string' ? id === find || id.startsWith(`${find}/`) : find.test(id)))
    return entry ? id.replace(entry.find, entry.replacement) : id
}

/**
 * The shim behind a replacement variable: the config publishes each shim as
 * `.shims/<name>.mjs` (`const runtimeShim = path.join(shimsDir,
 * 'runtime.mjs')`) from the `<name>-shim.mjs.stub` next to it, with its
 * declarations from `<name>-shim.d.mts.stub`.
 */
function shimFile(variable: string): string | undefined {
    return viteExtensionsConfig.match(new RegExp(`const ${variable} = path\\.join\\(shimsDir, '([\\w-]+)\\.mjs'\\)`))?.[1]
}

/** The names the shim behind a replacement variable exports. */
function shimExports(variable: string): Set<string> {
    const source = shims[`../../stubs/extensions/${shimFile(variable)}-shim.mjs.stub`] ?? ''
    return new Set([...source.matchAll(/^export const (\w+) =/gm)].map(([, name]) => name))
}

/**
 * The types the declarations published next to that shim export
 * (`export type { ... }`), or `null` for a shim without declarations: the
 * React shims, which the consumer's `@types/react` types.
 */
function shimTypes(variable: string): Set<string> | null {
    const source = declarations[`../../stubs/extensions/${shimFile(variable)}-shim.d.mts.stub`]
    if (source === undefined) return null

    return new Set([...source.matchAll(/^export type \{([^}]*)\}/gm)].flatMap(([, names]) => names.split(',').map((name) => name.trim().split(/\s+as\s+/).pop() ?? '')).filter((name) => name !== ''))
}

/**
 * Contract tests for the consumer-extension runtime bag. Three concerns:
 *
 * 1. The exports a consumer reaches for are present and callable.
 *    Consumer-extension bundles bind to `window.Martis.runtime.X` at
 *    runtime — if a name silently disappears, the consumer crashes
 *    only after deploy. These tests guard the contract.
 *
 * 2. The scaffold `martis:install` publishes resolves them: the runtime
 *    shim names every member, the vite aliases send `@martis/runtime` and
 *    the legacy paths to it, and every import the docs examples make
 *    resolves in that build. A gap here fails the consumer's build, not
 *    ours.
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

        // Registries (v1.38.0): the instances the SPA reads, not copies,
        // so a registration from an extension reaches the host.
        expect(martisRuntime.componentRegistry).toBe(componentRegistry)
        expect(martisRuntime.iconRegistry).toBe(iconRegistry)
        expect(martisRuntime.layoutRegistry).toBe(layoutRegistry)

        // Page and override hooks (v1.38.0)
        expect(martisRuntime.usePageTitle).toBeTypeOf('function')
        expect(martisRuntime.useModalHistoryLock).toBeTypeOf('function')
        expect(martisRuntime.useOverrideProps).toBeTypeOf('function')
        expect(martisRuntime.useOverridePropsOptional).toBeTypeOf('function')
        // A context Provider is an object, not a plain function.
        expect(martisRuntime.OverridePropsProvider).toBeDefined()
        expect(martisRuntime.useUnsavedChangesGuard).toBeTypeOf('function')
        expect(martisRuntime.useError).toBeTypeOf('function')

        // Theme and display helpers (v1.38.0). The loader is the
        // registry-aware wrapper, so an extension renders the loader the
        // app registered under `loader`.
        expect(martisRuntime.cssVar).toBeTypeOf('function')
        expect(martisRuntime.accentColor).toBeTypeOf('function')
        expect(martisRuntime.mutedTextColor).toBeTypeOf('function')
        expect(martisRuntime.chartPalette).toBeTypeOf('function')
        expect(martisRuntime.resolveColor).toBeTypeOf('function')
        expect(martisRuntime.avatarColorForSeed).toBeTypeOf('function')
        expect(martisRuntime.Sparkline).toBeTypeOf('function')
        expect(martisRuntime.ClearButton).toBeTypeOf('function')
        expect(martisRuntime.MartisLoader).toBe(MartisLoader)

        // Preferences and locale (v1.38.0)
        expect(martisRuntime.usePreferences).toBeTypeOf('function')
        expect(martisRuntime.usePreferencesOptional).toBeTypeOf('function')
        expect(martisRuntime.loadLocale).toBeTypeOf('function')
        expect(martisRuntime.applyDocumentDirection).toBeTypeOf('function')
        expect(martisRuntime.usePrefersReducedMotion).toBeTypeOf('function')

        // Keyboard shortcuts (v1.38.0): the registry the shell binds its own
        // combos to, so an extension's combo shows in the help overlay and
        // takes part in the first-registered-wins order.
        expect(martisRuntime.addShortcut).toBe(addShortcut)
        expect(martisRuntime.disableShortcut).toBe(disableShortcut)
        expect(martisRuntime.listShortcuts).toBe(listShortcuts)

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

    it('every import the docs examples make resolves in a consumer extension build', () => {
        // The docs examples are copied into consumer extensions, which the
        // consumer's own vite builds from `vite.extensions.config.ts`. An
        // aliased specifier (`@martis/runtime`, the legacy `@/lib/*` style
        // paths, `react-dom`, ...) reaches a shim, and a value name that shim
        // does not export fails the build with "is not exported"; a type
        // must be one the shim's declarations export (from `@martis/runtime`,
        // one `lib/martisRuntime.ts` re-exports), since the consumer's
        // tsconfig sends the specifier to them, React's own shims excepted
        // (typed by @types/react). An `@/...` or `@martis/...` path no alias
        // matches does not resolve at all, and one the legacy aliases send to the runtime
        // shim builds but teaches a package path, so the docs name
        // `@martis/runtime`. A code block that documents the package's own
        // source says so on a `// Package-internal` line and is skipped.
        const runtimeTypes = new Set([
            ...[...runtimeSource.matchAll(/^export type \{([^}]*)\}/gm)].flatMap(([, names]) => names.split(',').map((name) => name.trim())),
            ...[...runtimeSource.matchAll(/^export type (\w+)/gm)].map(([, name]) => name),
        ])
        const problems: string[] = []

        for (const [file, source] of Object.entries(docs)) {
            const page = file.replace('../../docs/', '')
            const consumerFacing = source.replace(
                /^([ \t>]*)(`{3,}|~{3,})[^\n]*\n[\s\S]*?^\1\2[ \t]*$/gm,
                (block) => (/^[ \t>]*\/\/ Package-internal\b/m.test(block) ? '' : block),
            )
            const imports = consumerFacing.matchAll(/import\s+(type\s+)?((?:\w+\s*,\s*)?(?:\{[^}]*\}|\*\s*as\s+\w+|\w+))\s+from\s+['"]([^'"]+)['"]/g)

            for (const [, typeOnly, clause, specifier] of imports) {
                const target = resolveAlias(specifier)
                if (target === specifier) {
                    if (/^@(\/|martis\/)/.test(specifier)) problems.push(`${page}: '${specifier}' does not resolve`)
                    continue
                }
                if (target === 'runtimeShim' && specifier !== '@martis/runtime') {
                    problems.push(`${page}: '${specifier}' is a legacy path, import from '@martis/runtime'`)
                    continue
                }

                const exported = shimExports(target)
                const types = target === 'runtimeShim' ? runtimeTypes : shimTypes(target)
                const names = (clause.match(/\{([^}]*)\}/)?.[1] ?? '').split(',').map((s) => s.trim()).filter((s) => s !== '')
                for (const entry of names) {
                    const isType = typeOnly !== undefined || entry.startsWith('type ')
                    const name = entry.replace(/^type\s+/, '').split(/\s+as\s+/)[0]
                    if (isType && types === null) continue
                    if (exported.has(name) || (isType && types?.has(name))) continue
                    problems.push(`${page}: ${isType ? 'type ' : ''}${name} from '${specifier}'`)
                }
            }
        }

        expect(problems).toEqual([])
    })

    it('the consumer vite config sends @martis/runtime and every legacy runtime path to the whole shim', () => {
        const expected: Record<string, string> = {
            'react': 'reactShim',
            'react-dom': 'reactDomShim',
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
            // The type module the v1.9.3 field override imports (type-only).
            '@/components/fields/types': 'runtimeShim',
        }

        expect(Object.fromEntries(Object.keys(expected).map((id) => [id, resolveAlias(id)]))).toEqual(expected)
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
