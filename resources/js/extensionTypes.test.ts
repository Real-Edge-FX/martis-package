import { describe, expect, it } from 'vitest'
import ts from 'typescript'
import { martisRuntime } from '@/lib/martisRuntime'
import * as runtimeEntry from '@/extension-types/runtime'
import * as routerEntry from '@/extension-types/react-router-dom'
import * as i18nextEntry from '@/extension-types/react-i18next'
import * as queryEntry from '@/extension-types/tanstack-react-query'
import runtimeSource from '@/lib/martisRuntime.ts?raw'
import runtimeEntrySource from '@/extension-types/runtime.ts?raw'
import viteExtensionsConfig from '../../stubs/extensions/vite.extensions.config.ts.stub?raw'
import tsconfigExtensions from '../../stubs/extensions/tsconfig.extensions.json.stub?raw'

const stubs = import.meta.glob('../../stubs/extensions/*.stub', { query: '?raw', import: 'default', eager: true }) as Record<string, string>
const stub = (file: string): string => stubs[`../../stubs/extensions/${file}`] ?? ''

/**
 * Contract tests for the TypeScript declarations `martis:install` publishes
 * next to the consumer-extension shims (`.shims/<shim>.d.mts`):
 *
 * 1. Each type entry in `resources/js/extension-types/` re-exports exactly
 *    the names its shim exports, as the objects the runtime serves.
 * 2. Each generated `<shim>-shim.d.mts.stub` declares exactly those names
 *    (plus the types the runtime module exports) and imports only what a
 *    consumer app installs.
 * 3. The published tsconfig sends every specifier the Vite config sends to a
 *    declared shim to that shim's declarations, and nothing else.
 */

/** The object each shim reads its exports from (`const R = window.Martis.runtime`, `RR`, `I`, `Q`). */
const RUNTIME_OBJECTS: Record<string, Record<string, unknown>> = {
    R: martisRuntime as unknown as Record<string, unknown>,
    RR: martisRuntime.reactRouterDom as unknown as Record<string, unknown>,
    I: martisRuntime.reactI18next as unknown as Record<string, unknown>,
    Q: martisRuntime.tanstackReactQuery as unknown as Record<string, unknown>,
}

const SHIMS = [
    { shim: 'runtime', entry: runtimeEntry },
    { shim: 'react-router-dom', entry: routerEntry },
    { shim: 'react-i18next', entry: i18nextEntry },
    { shim: 'tanstack-react-query', entry: queryEntry },
]

/** The `export const <name> = <object>.<key>` lines of a shim, and the object it exports as default. */
function shimExports(source: string): { named: Map<string, { object: string; key: string }>; defaultObject: string } {
    const named = new Map([...source.matchAll(/^export const (\w+) = (\w+)\.(\w+)$/gm)].map(([, name, object, key]) => [name, { object, key }] as const))

    return { named, defaultObject: /^export default (\w+)$/m.exec(source)?.[1] ?? '' }
}

/** The types a TS module exports (`export type { ... }`, `export type X = ...`, `export interface X`). */
function typeExports(source: string): string[] {
    const file = ts.createSourceFile('module.ts', source, ts.ScriptTarget.Latest)
    const names = new Set<string>()
    for (const statement of file.statements) {
        if (ts.isExportDeclaration(statement) && statement.isTypeOnly && statement.exportClause && ts.isNamedExports(statement.exportClause)) {
            for (const element of statement.exportClause.elements) names.add(element.name.text)
        }
        if ((ts.isTypeAliasDeclaration(statement) || ts.isInterfaceDeclaration(statement)) && statement.modifiers?.some((modifier) => modifier.kind === ts.SyntaxKind.ExportKeyword)) {
            names.add(statement.name.text)
        }
    }

    return [...names].sort()
}

/** Names a declaration file exports (values and type-only), and every module it imports. */
function declarationExports(source: string): { values: string[]; types: string[]; modules: string[] } {
    const file = ts.createSourceFile('shim.d.mts', source, ts.ScriptTarget.Latest, false, ts.ScriptKind.TS)
    const values = new Set<string>()
    const types = new Set<string>()
    const modules = new Set<string>()

    const visit = (node: ts.Node): void => {
        if ((ts.isImportDeclaration(node) || ts.isExportDeclaration(node)) && node.moduleSpecifier && ts.isStringLiteral(node.moduleSpecifier)) {
            modules.add(node.moduleSpecifier.text)
        }
        if (ts.isImportTypeNode(node) && ts.isLiteralTypeNode(node.argument) && ts.isStringLiteral(node.argument.literal)) {
            modules.add(node.argument.literal.text)
        }
        ts.forEachChild(node, visit)
    }
    visit(file)

    for (const statement of file.statements) {
        if (ts.isExportDeclaration(statement) && statement.exportClause && ts.isNamedExports(statement.exportClause)) {
            for (const element of statement.exportClause.elements) {
                (statement.isTypeOnly || element.isTypeOnly ? types : values).add(element.name.text)
            }
        }
    }

    return { values: [...values].sort(), types: [...types].sort(), modules: [...modules].sort() }
}

describe('the extension shim declarations', () => {
    it('ship for exactly the shims that have a type entry', () => {
        const declared = Object.keys(stubs)
            .map((path) => /\/([\w-]+)-shim\.d\.mts\.stub$/.exec(path)?.[1])
            .filter((shim): shim is string => shim !== undefined)
            .sort()
        expect(declared).toEqual(SHIMS.map(({ shim }) => shim).sort())
    })

    it.each(SHIMS)('declare exactly what the $shim shim exports, and import only what a consumer installs', ({ shim }) => {
        const { named } = shimExports(stub(`${shim}-shim.mjs.stub`))
        const declaration = declarationExports(stub(`${shim}-shim.d.mts.stub`))

        expect(declaration.values).toEqual([...named.keys(), 'default'].sort())
        expect(declaration.types).toEqual(shim === 'runtime' ? typeExports(runtimeSource) : [])
        // `martis:install` adds react, react-dom and @phosphor-icons/react to the
        // consumer; the runtime reaches the third-party types through the
        // sibling shims' declarations.
        for (const module of declaration.modules) {
            expect(module).toMatch(/^(?:react(?:-dom)?(?:\/[\w-]+)?|@phosphor-icons\/react|\.\/(?:react-router-dom|react-i18next|tanstack-react-query)\.mjs)$/)
        }
    })
})

describe.each(SHIMS)('the $shim shim', ({ shim, entry }) => {
    const { named, defaultObject } = shimExports(stub(`${shim}-shim.mjs.stub`))

    it('has a type entry that re-exports each shim export as the object the runtime serves', () => {
        const exported = entry as Record<string, unknown>
        expect(Object.keys(exported).filter((key) => key !== 'default').sort()).toEqual([...named.keys()].sort())

        for (const [name, { object, key }] of named) {
            expect(exported[name], name).toBe(RUNTIME_OBJECTS[object][key])
        }

        // The default export is the object the shim's default reads: the
        // runtime itself, or the host's module namespace.
        const expectedDefault = RUNTIME_OBJECTS[defaultObject]
        const actualDefault = exported.default as Record<string, unknown>
        expect(Object.keys(actualDefault).sort()).toEqual(Object.keys(expectedDefault).sort())
        for (const key of Object.keys(expectedDefault)) expect(actualDefault[key], `default.${key}`).toBe(expectedDefault[key])
    })
})

describe('the runtime type entry', () => {
    it('exports every type the runtime module exports', () => {
        // Read from the sources: types do not exist at runtime.
        expect(typeExports(runtimeEntrySource)).toEqual(typeExports(runtimeSource))
    })
})

/** The tsconfig `paths` pattern for a whole-specifier alias regex (`^@\/lib\/.*$` becomes `@/lib/*`). */
function tsPathPattern(source: string): string {
    const pattern = source.replace(/^\^/, '').replace(/\$$/, '').replace(/\\\//g, '/').replace(/\.\*$/, '*')
    expect(pattern, `/${source}/ has no tsconfig paths equivalent`).toMatch(/^[\w@/-]+\*?$/)

    return pattern
}

describe('the published tsconfig.extensions.json', () => {
    const tsconfig = JSON.parse(tsconfigExtensions) as { compilerOptions: Record<string, unknown>; include?: string[] }

    it('sends every specifier the Vite config aliases to a declared shim to its declarations, and nothing else', () => {
        const shimFiles = Object.fromEntries([...viteExtensionsConfig.matchAll(/const (\w+) = path\.join\(shimsDir, '([\w-]+)\.mjs'\)/g)].map(([, variable, file]) => [variable, file]))
        const declared = new Set(SHIMS.map(({ shim }) => shim))
        const expected: Record<string, string[]> = {}

        for (const [, literal, source, replacement] of viteExtensionsConfig.matchAll(/\{find: (?:'([^']+)'|\/(.+?)\/[a-z]*), replacement: (\w+)\}/g)) {
            const shim = shimFiles[replacement]
            // react, react-dom and react/jsx-runtime are typed by the consumer's @types/react.
            if (!declared.has(shim)) continue
            expected[literal ?? tsPathPattern(source)] = [`./resources/js/martis-extensions/.shims/${shim}.d.mts`]
        }

        expect(Object.keys(expected)).toHaveLength(9)
        expect(tsconfig.compilerOptions.paths).toEqual(expected)
    })

    it('covers only the browser-side extension sources', () => {
        // TypeScript 6 rejects `baseUrl` as deprecated (TS5101); `paths` resolve from the tsconfig.
        expect(tsconfig.compilerOptions.baseUrl).toBeUndefined()
        // No Node globals in browser code, and no @types/node needed (TS2688).
        expect(tsconfig.compilerOptions.types).toEqual(['vite/client'])
        expect(tsconfig.include).toEqual(['resources/js/martis-extensions/**/*'])
    })
})
