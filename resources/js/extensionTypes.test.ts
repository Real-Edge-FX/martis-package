import { beforeAll, describe, expect, it } from 'vitest'
import ts from 'typescript'
import { martisRuntime } from '@/lib/martisRuntime'
import * as runtimeEntry from '@/extension-types/runtime'
import * as routerEntry from '@/extension-types/react-router-dom'
import * as i18nextEntry from '@/extension-types/react-i18next'
import * as queryEntry from '@/extension-types/tanstack-react-query'
import * as reactDomEntry from '@/extension-types/react-dom'
import * as reactDomClientEntry from '@/extension-types/react-dom-client'
import { reactDomClientHandle, reactDomHandle } from '@/lib/reactDomHandles'
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
 * 2. Each generated `<shim>-shim.d.mts.stub` declares exactly those names,
 *    plus the types of its module: the ones the runtime module exports, or
 *    every type of the host's copy of a third-party library (its interfaces
 *    and type aliases, so a consumer keeps the types its `paths` no longer
 *    take from `node_modules`), and imports only what a consumer app
 *    installs.
 * 3. The published tsconfig sends every specifier the Vite config sends to a
 *    shim to that shim's declarations (`react` and `react/jsx-runtime`
 *    excepted, typed by the consumer's `@types/react`), and nothing else.
 */

/**
 * The object each shim reads its exports from (`const R = window.Martis.runtime`,
 * `RR`, `I`, `Q`; `D` and `C`, the ReactDOM 18 handles on `window.Martis`
 * since v2.10.0), and the default export of the react-dom shims: the same
 * members as their named exports.
 */
const RUNTIME_OBJECTS: Record<string, Record<string, unknown>> = {
    R: martisRuntime as unknown as Record<string, unknown>,
    RR: martisRuntime.reactRouterDom as unknown as Record<string, unknown>,
    I: martisRuntime.reactI18next as unknown as Record<string, unknown>,
    Q: martisRuntime.tanstackReactQuery as unknown as Record<string, unknown>,
    D: reactDomHandle,
    C: reactDomClientHandle,
    ReactDOM: reactDomHandle,
    ReactDOMClient: reactDomClientHandle,
}

const SHIMS = [
    { shim: 'runtime', specifier: '@martis/runtime', entry: runtimeEntry },
    { shim: 'react-dom', specifier: 'react-dom', entry: reactDomEntry },
    { shim: 'react-dom-client', specifier: 'react-dom/client', entry: reactDomClientEntry },
    // React Router 7's `react-router-dom` is a re-export of `react-router`,
    // the package the host installs: its types are the library's.
    { shim: 'react-router-dom', specifier: 'react-router-dom', library: 'react-router', entry: routerEntry },
    { shim: 'react-i18next', specifier: 'react-i18next', entry: i18nextEntry },
    { shim: 'tanstack-react-query', specifier: '@tanstack/react-query', entry: queryEntry },
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

/**
 * The exports of each module that are types only (interfaces and type
 * aliases), as the package's TypeScript resolves it: the host's copy of the
 * library, which the declarations carry. A class or enum is a value too,
 * which a shim exports by name or not at all.
 */
function libraryTypeExports(specifiers: string[]): Record<string, string[]> {
    const file = `${ts.sys.getCurrentDirectory()}/resources/js/__library-type-exports__.ts`
    const source = specifiers.map((specifier, index) => `import * as Module${index} from '${specifier}'`).join('\n')
    const options: ts.CompilerOptions = { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext, moduleResolution: ts.ModuleResolutionKind.Bundler, jsx: ts.JsxEmit.ReactJSX, strict: true, skipLibCheck: true, noEmit: true, types: [] }
    const host = ts.createCompilerHost(options)
    const program = ts.createProgram({
        rootNames: [file],
        options,
        host: {
            ...host,
            fileExists: (name) => name === file || host.fileExists(name),
            readFile: (name) => (name === file ? source : host.readFile(name)),
            getSourceFile: (name, version) => (name === file ? ts.createSourceFile(name, source, version) : host.getSourceFile(name, version)),
        },
    })
    const checker = program.getTypeChecker()
    const imports = program.getSourceFile(file)?.statements.filter(ts.isImportDeclaration) ?? []

    return Object.fromEntries(specifiers.map((specifier, index) => {
        const module = checker.getSymbolAtLocation(imports[index].moduleSpecifier)
        expect(module, `${specifier} does not resolve`).toBeDefined()
        const names = checker.getExportsOfModule(module as ts.Symbol)
            .map((symbol) => [symbol, (symbol.flags & ts.SymbolFlags.Alias ? checker.getAliasedSymbol(symbol) : symbol).flags] as const)
            .filter(([, flags]) => (flags & ts.SymbolFlags.Type) !== 0 && (flags & ts.SymbolFlags.Value) === 0)
            .map(([symbol]) => symbol.name)
            .filter((name) => name !== 'default')

        return [specifier, names.sort()]
    }))
}

/**
 * Types a third-party shim declares that its library no longer exports: kept
 * under their old names so an extension that imports them keeps compiling.
 * `@tanstack/query-core` renamed `Enabled` to `QueryBooleanOption` and dropped
 * its own `NoInfer` for TypeScript's built-in one (5.100.13).
 */
const COMPAT_TYPES: Record<string, string[]> = {
    'tanstack-react-query': ['Enabled', 'NoInfer'],
}

const TYPES_ONLY = [{ shim: 'i18next', specifier: 'i18next' }]

describe('the extension shim declarations', () => {
    // One program reads the four libraries: seconds on a loaded machine.
    let libraryTypes: Record<string, string[]> = {}
    beforeAll(() => {
        libraryTypes = libraryTypeExports([...SHIMS.filter(({ shim }) => shim !== 'runtime').map(({ specifier, library }) => library ?? specifier), 'i18next'])
    }, 120_000)

    it('ship for exactly the shims that have a type entry', () => {
        const declared = Object.keys(stubs)
            .map((path) => /\/([\w-]+)-shim\.d\.mts\.stub$/.exec(path)?.[1])
            .filter((shim): shim is string => shim !== undefined)
            .sort()
        expect(declared).toEqual([...SHIMS, ...TYPES_ONLY].map(({ shim }) => shim).sort())
    })

    it.each(SHIMS)('declare exactly what the $shim shim exports, and import only what a consumer installs', ({ shim, specifier, library }) => {
        const { named } = shimExports(stub(`${shim}-shim.mjs.stub`))
        const declaration = declarationExports(stub(`${shim}-shim.d.mts.stub`))

        expect(declaration.values).toEqual([...named.keys(), 'default'].sort())
        // The runtime's own types, or every type of the library.
        const types = shim === 'runtime' ? typeExports(runtimeSource) : [...(libraryTypes[library ?? specifier] ?? []), ...(COMPAT_TYPES[shim] ?? [])].sort()
        expect(declaration.types).toEqual(types)
        // `martis:install` adds react, react-dom and @phosphor-icons/react to the
        // consumer; the runtime reaches the third-party types through the
        // sibling shims' declarations.
        for (const module of declaration.modules) {
            expect(module).toMatch(/^(?:react(?:-dom)?(?:\/[\w-]+)?|@phosphor-icons\/react|\.\/(?:react-dom|react-router-dom|react-i18next|tanstack-react-query|i18next)\.mjs)$/)
        }
        // The consumer's tsconfig sends the shim's specifier to these very
        // declarations, so they cannot take anything from it.
        expect(declaration.modules).not.toContain(specifier)
    })

    it('name in their header the module they declare: a shim that exists, or none for a types-only entry', () => {
        const header = (shim: string): string => /^\/\*\*[\s\S]*?\*\//.exec(stub(`${shim}-shim.d.mts.stub`))?.[0] ?? ''
        for (const { shim } of SHIMS) {
            expect(stub(`${shim}-shim.mjs.stub`), `${shim}-shim.mjs.stub`).not.toBe('')
            expect(header(shim)).toContain(`\`.shims/${shim}.mjs\``)
        }
        for (const { shim, specifier } of TYPES_ONLY) {
            // No `.shims/<shim>.mjs` exists and no build resolves the specifier to one.
            expect(stub(`${shim}-shim.mjs.stub`)).toBe('')
            expect(header(shim)).not.toContain(`.shims/${shim}.mjs`)
            expect(header(shim)).toContain('Types only')
            expect(header(shim)).toContain(`import type`)
            expect(header(shim)).toContain(`'${specifier}'`)
        }
    })

    it('declare i18next\'s own types and values, importing nothing, for the shims and the consumer to share', async () => {
        const declaration = declarationExports(stub('i18next-shim.d.mts.stub'))
        const i18next = await import('i18next')

        // i18next's types brand strings with two `declare const` symbols that
        // only the declarations export (`$PluralBrand`, `$SelectorKeyBrand`).
        const brands = declaration.values.filter((name) => name.startsWith('$'))
        expect(brands).toEqual(['$PluralBrand', '$SelectorKeyBrand'])
        expect(declaration.values.filter((name) => !name.startsWith('$'))).toEqual(Object.keys(i18next).sort())
        expect(declaration.types).toEqual(libraryTypes.i18next ?? [])
        expect(declaration.modules).toEqual([])
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

    it('sends every specifier the Vite config aliases to a shim to that shim\'s declarations, React\'s own excepted', () => {
        const shimFiles = Object.fromEntries([...viteExtensionsConfig.matchAll(/const (\w+) = path\.join\(shimsDir, '([\w-]+)\.mjs'\)/g)].map(([, variable, file]) => [variable, file]))
        const declared = new Set(SHIMS.map(({ shim }) => shim))
        const expected: Record<string, string[]> = {}

        for (const [, literal, source, replacement] of viteExtensionsConfig.matchAll(/\{find: (?:'([^']+)'|\/(.+?)\/[a-z]*), replacement: (\w+)\}/g)) {
            const specifier = literal ?? tsPathPattern(source)
            // `react` and `react/jsx-runtime` are typed by the consumer's
            // @types/react, at the host's major (`martis:install`), since
            // their shims pass the host's React through. Any other specifier
            // tsc resolved on its own would accept names its shim does not
            // export: the build would then fail on code tsc passed.
            if (specifier === 'react' || specifier === 'react/jsx-runtime') continue
            const shim = shimFiles[replacement]
            expect(declared.has(shim), `${specifier} goes to .shims/${shim}.mjs, which has no declarations`).toBe(true)
            expected[specifier] = [`./resources/js/martis-extensions/.shims/${shim}.d.mts`]
        }

        // Types-only entries no Vite alias names (v2.3.0): the shared i18next
        // declarations, and the test kit's in the installed package.
        expected.i18next = ['./resources/js/martis-extensions/.shims/i18next.d.mts']
        expected['@martis/testing'] = ['./vendor/martis/martis/dist/testing/testing.d.mts']

        expect(Object.keys(expected)).toHaveLength(14)
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
