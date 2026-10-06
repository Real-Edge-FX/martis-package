import { describe, expect, it } from 'vitest'
import ts from 'typescript'
import * as React from 'react'
import runtimeEntrySource from '@/extension-types/runtime.ts?raw'

/**
 * Type-checks what a consumer extension compiles against the declarations
 * `martis:install` publishes (`.shims/*.d.mts`) and the published
 * `tsconfig.extensions.json`, as `tsc -p tsconfig.extensions.json` does in
 * a scaffolded app:
 *
 * 1. the TSX every generator writes (`martis:component`, `martis:field`,
 *    `martis:card`, `martis:tool --with-component`), strict;
 * 2. the extension entry (`index.ts`) once it imports `@martis/runtime`, as
 *    the docs have it do, strict;
 * 3. every TypeScript docs block that imports `@martis/runtime` or another
 *    module the build sends to a declared shim, or uses `window.Martis`, as
 *    a snippet: what it leaves out (an undeclared
 *    local, the reader's own module) is context, anything else (a runtime
 *    name used wrongly or not imported, a React hook not imported, a prop
 *    that does not exist) fails. A block headed
 *    `// resources/js/martis-extensions/index.ts` is compiled as that file:
 *    the scaffold's entry with the block added.
 *
 * The consumer lives in memory, as if under the package's
 * `node_modules/.cache`, so `react` and `@types/react` resolve from the
 * package; `@martis/runtime`, `react-router-dom` (and `react-router`), `react-i18next` and
 * `@tanstack/react-query` go to the declarations through the tsconfig
 * `paths`, as in a consumer app, which installs none of those libraries.
 */

const raw = (files: Record<string, string>): Record<string, string> =>
    Object.fromEntries(Object.entries(files).map(([file, source]) => [file.replace(/^.*\//, ''), source]))
const stubs = raw(import.meta.glob('../../stubs/*.tsx.stub', { query: '?raw', import: 'default', eager: true }) as Record<string, string>)
const extensionStubs = raw(import.meta.glob('../../stubs/extensions/*.stub', { query: '?raw', import: 'default', eager: true }) as Record<string, string>)
const docs = raw(import.meta.glob('../../docs/*.md', { query: '?raw', import: 'default', eager: true }) as Record<string, string>)
const commands = import.meta.glob('../../src/Console/*Command.php', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

const EXT = 'resources/js/martis-extensions'
const fill = (source: string, token: string, value: string): string => source.split(token).join(value)
const pascal = (kebab: string): string => kebab.split('-').map((part) => part.charAt(0).toUpperCase() + part.slice(1)).join('')

/** The TSX of every generator stub, placeholders filled the way the generators fill them. */
function generatorOutputs(): Record<string, string> {
    const outputs: Record<string, string> = {}
    for (const [stub, source] of Object.entries(stubs)) {
        if (!/^(component-[\w-]+|field|tool-component)\.tsx\.stub$/.test(stub)) continue
        const kebab = stub.replace(/\.tsx\.stub$/, '')
        const name = pascal(kebab)
        let output = source
        for (const [token, value] of [['{{ class }}', name], ['{{ kebab }}', kebab], ['{{ display_name }}', name], ['{{ class_short }}', name], ['{{ component_key }}', `tool:${kebab}`], ['{{ component_name }}', name], ['{{ type }}', kebab]]) {
            output = fill(output, token, value)
        }
        outputs[`${EXT}/overrides/${name}.tsx`] = output
    }

    return outputs
}

/** A docs block that starts with bare JSX, or returns at its top level, runs inside a component. */
function asModule(block: string): string {
    const lines = block.split('\n')
    const imports = lines.filter((line) => line.startsWith('import '))
    const rest = lines.filter((line) => !line.startsWith('import '))
    const code = rest.filter((line) => line.trim() !== '' && !line.trim().startsWith('//'))
    if (code[0]?.trimStart().startsWith('<')) {
        return [...imports, 'export function DocsExample() {', '  return (<>', ...rest, '  </>)', '}'].join('\n')
    }
    if (rest.some((line) => line.startsWith('return '))) {
        return [...imports, 'export function DocsExample() {', ...rest, '}'].join('\n')
    }

    return `${block}\nexport {}\n`
}

/** The first line of a docs block that goes into the extension entry. */
const ENTRY_BLOCK = /^\/\/ resources\/js\/martis-extensions\/index\.ts\b/

/** An import of a third-party module the build sends to a shim with declarations. */
const SHIMMED_IMPORT = /from ['"](?:react-dom|react-router(?:-dom)?|react-i18next|@tanstack\/react-query)['"]/

/**
 * Every TypeScript docs block (```ts, ```tsx or ```typescript) that imports
 * `@martis/runtime` or a shimmed third-party module, or reaches the host
 * through `window.Martis`, except the ones documenting package code. A
 * block headed with the entry's path is the scaffold's `index.ts` with the
 * block added.
 */
function docsBlocks(): Record<string, string> {
    const blocks: Record<string, string> = {}
    for (const [page, source] of Object.entries(docs)) {
        for (const match of source.matchAll(/```(?:tsx?|typescript)\n([\s\S]*?)```/g)) {
            const block = match[1]
            if (!(block.includes('@martis/runtime') || SHIMMED_IMPORT.test(block) || block.includes('@martis/testing') || /\bwindow\.Martis\b/.test(block)) || block.includes('Package-internal')) continue
            const name = `docs-examples/${page.replace(/\.md$/, '')}_${source.slice(0, match.index).split('\n').length}`
            if (ENTRY_BLOCK.test(block)) blocks[`${name}.index.ts`] = `${extensionStubs['index.ts.stub']}\n${block}`
            else blocks[`${name}.tsx`] = asModule(block)
        }
    }

    return blocks
}

/**
 * The scaffold's entry once it imports `@martis/runtime`, as the docs have
 * it do to register a component or a shortcut. The runtime declarations
 * then load before the entry's own `Window.Martis` declaration, so one in
 * the declarations would clash with it (TS2717).
 */
const ENTRY_WITH_RUNTIME = `${EXT}/entry-with-runtime/index.ts`
const entryWithRuntime = [
    "import { addShortcut, componentRegistry } from '@martis/runtime'",
    '',
    extensionStubs['index.ts.stub'],
    "componentRegistry.register('status-badge', () => null)",
    "addShortcut('mod+k', () => undefined, { description: 'Open my launcher', group: 'Navigation', allowInInput: true })",
    '',
].join('\n')

// The consumer, in memory: the scaffold `martis:install` publishes, the
// generator outputs, an entry that imports the runtime, the probes below
// and the docs blocks.
const consumer = `${ts.sys.getCurrentDirectory()}/node_modules/.cache/martis-extension-typecheck`
const files = new Map<string, string>()
const put = (relative: string, contents: string): void => void files.set(`${consumer}/${relative}`, contents)

put('tsconfig.extensions.json', extensionStubs['tsconfig.extensions.json.stub'])
put(`${EXT}/tsconfig.json`, extensionStubs['martis-extensions-tsconfig.json.stub'] ?? '')
put(`${EXT}/index.ts`, extensionStubs['index.ts.stub'])
for (const [stub, source] of Object.entries(extensionStubs)) {
    if (stub.endsWith('-shim.d.mts.stub')) put(`${EXT}/.shims/${stub.replace('-shim.d.mts.stub', '.d.mts')}`, source)
}
const testingDeclarations = raw(import.meta.glob('../../dist/testing/testing.d.mts', { query: '?raw', import: 'default', eager: true }) as Record<string, string>)
put('vendor/martis/martis/dist/testing/testing.d.mts', testingDeclarations['testing.d.mts'] ?? '')
for (const [file, source] of Object.entries(generatorOutputs())) put(file, source)
put(ENTRY_WITH_RUNTIME, entryWithRuntime)
for (const [file, source] of Object.entries(docsBlocks())) put(file, source)
// Docs blocks are snippets: untyped parameters are not what this checks.
put('tsconfig.docs.json', JSON.stringify({ extends: './tsconfig.extensions.json', compilerOptions: { noImplicitAny: false } }))

const underConsumer = (directory: string): boolean => [...files.keys()].some((file) => file.startsWith(`${directory}/`))
// Every program below reads the same files (the DOM lib, @types/react, the
// declarations): parse each once. A worker kept busy parsing them for each
// program in turn could not answer Vitest's RPC in time on a loaded machine.
const parsed = new Map<string, ts.SourceFile>()
const host: ts.CompilerHost = {
    ...ts.createCompilerHost({}),
    fileExists: (file) => files.has(file) || ts.sys.fileExists(file),
    readFile: (file) => files.get(file) ?? ts.sys.readFile(file),
    directoryExists: (directory) => underConsumer(directory) || (ts.sys.directoryExists?.(directory) ?? false),
    realpath: (file) => (files.has(file) || underConsumer(file) ? file : (ts.sys.realpath?.(file) ?? file)),
    getSourceFile: (file, languageVersion) => {
        const key = `${file}|${typeof languageVersion === 'object' ? JSON.stringify(languageVersion) : languageVersion}`
        const cached = parsed.get(key)
        if (cached !== undefined) return cached
        const text = files.get(file) ?? ts.sys.readFile(file)
        if (text === undefined) return undefined
        const source = ts.createSourceFile(file, text, languageVersion)
        parsed.set(key, source)

        return source
    },
}

/** The compiler options a consumer tsconfig resolves to (its `extends` included). */
function compilerOptions(config: string): ts.CompilerOptions {
    const file = `${consumer}/${config}`
    const { config: json, error } = ts.readConfigFile(file, host.readFile)
    if (error !== undefined) throw new Error(ts.flattenDiagnosticMessageText(error.messageText, '\n'))
    const parsed = ts.parseJsonConfigFileContent(json, { useCaseSensitiveFileNames: true, readDirectory: () => [], fileExists: host.fileExists, readFile: host.readFile }, file.slice(0, file.lastIndexOf('/')), undefined, file)

    return parsed.options
}

/** Diagnostics of the given consumer files under a consumer tsconfig, as `path(line,col): TSxxxx message`. */
function typecheck(config: string, sources: string[]): string[] {
    const program = ts.createProgram({ rootNames: sources.map((source) => `${consumer}/${source}`), options: compilerOptions(config), host })

    return ts.getPreEmitDiagnostics(program).map((diagnostic) => {
        const message = ts.flattenDiagnosticMessageText(diagnostic.messageText, '\n')
        if (diagnostic.file === undefined || diagnostic.start === undefined) return `TS${diagnostic.code}: ${message}`
        const { line, character } = diagnostic.file.getLineAndCharacterOfPosition(diagnostic.start)

        return `${diagnostic.file.fileName.replace(`${consumer}/`, '')}(${line + 1},${character + 1}): TS${diagnostic.code}: ${message}`
    })
}

/** Names a consumer imports from somewhere: the runtime's values and types, and React's exports. */
function importableNames(): Set<string> {
    const names = new Set(Object.keys(React))
    for (const [, name] of extensionStubs['runtime-shim.mjs.stub'].matchAll(/^export const (\w+) =/gm)) names.add(name)
    for (const [, name] of (runtimeEntrySource.split('export type {')[1]?.split('}')[0] ?? '').matchAll(/(\w+),/g)) names.add(name)

    return names
}

const extensionSources = [`${EXT}/index.ts`, ...Object.keys(generatorOutputs())]

/**
 * The types a consumer imports from the libraries the build shims: the
 * tsconfig `paths` send those specifiers to the declarations, not to
 * `node_modules`, so the declarations carry each library's types. A class
 * or enum the shim does not export has no value in the build, and is
 * reached through the default export, the host's module.
 */
const LIBRARY_TYPES_PROBE = `${EXT}/tools/LibraryTypesProbe.tsx`
put(LIBRARY_TYPES_PROBE, [
    "import type { Container } from 'react-dom'",
    "import type ReactRouterDom from 'react-router-dom'",
    "import type { LinkProps, NavigateFunction } from 'react-router-dom'",
    "import type { To } from 'react-router'",
    "import type { UseTranslationResponse } from 'react-i18next'",
    "import { useQuery, type Enabled, type NoInfer, type QueryKey, type UseQueryResult } from '@tanstack/react-query'",
    '// @ts-expect-error the shim does not export QueryCache, so the build has no value for it',
    "import { QueryCache } from '@tanstack/react-query'",
    '',
    "export type LibraryTypes = [Container, LinkProps, NavigateFunction, To, ReactRouterDom.NavigationType, UseTranslationResponse<'translation', undefined>, QueryKey, Enabled, NoInfer<string>]",
    '',
    'export default function LibraryTypesProbe() {',
    "  const query: UseQueryResult<string> = useQuery({ queryKey: ['probe'], queryFn: async () => 'ok' })",
    '  void QueryCache',
    '  return <span>{query.data}</span>',
    '}',
    '',
].join('\n'))

/**
 * What an extension can take from `react-dom`: the build sends it to a shim
 * that carries the runtime's `createPortal` and `flushSync` only, so tsc has
 * to take those (a library such as `@tanstack/react-virtual` imports
 * `flushSync`) and refuse the rest of the module (`unstable_batchedUpdates`)
 * instead of reading `@types/react-dom`.
 */
const REACT_DOM_PROBE = `${EXT}/tools/ReactDomProbe.tsx`
put(REACT_DOM_PROBE, [
    "import ReactDOM, { createPortal, flushSync } from 'react-dom'",
    '// @ts-expect-error the react-dom shim carries createPortal and flushSync only',
    "import { unstable_batchedUpdates } from 'react-dom'",
    '',
    'export default function ReactDomProbe() {',
    '  void unstable_batchedUpdates',
    '  flushSync(() => {})',
    '  ReactDOM.flushSync(() => {})',
    '  return ReactDOM.createPortal(createPortal(<span />, document.body), document.body)',
    '}',
    '',
].join('\n'))

/**
 * A test that hands its own i18next instance to the shim's I18nextProvider
 * (v2.3.0): the tsconfig sends `i18next` to the declarations the shims share,
 * so the instance is the type the provider takes, without a cast. And the
 * test kit's provider takes a QueryClient made from the shimmed module.
 */
const I18NEXT_PROBE = `${EXT}/__tests__/I18nextProbe.tsx`
put(I18NEXT_PROBE, [
    "import i18next from 'i18next'",
    "import { I18nextProvider } from 'react-i18next'",
    "import { QueryClient } from '@tanstack/react-query'",
    "import { MartisTestProvider, defaultTestUser } from '@martis/testing'",
    '',
    'const i18n = i18next.createInstance()',
    '',
    'export default function I18nextProbe() {',
    '  return (',
    '    <MartisTestProvider user={defaultTestUser} queryClient={new QueryClient()} locale="pt_PT">',
    '      <I18nextProvider i18n={i18n}><span /></I18nextProvider>',
    '    </MartisTestProvider>',
    '  )',
    '}',
    '',
].join('\n'))

describe('a consumer extension type-checks against the published declarations', () => {
    it('fills every placeholder of the generator stubs', () => {
        // The placeholders the generators substitute (`'{{ class }}' => ...` in
        // src/Console): not a JSX `style={{ ... }}` nor an i18next `{{brand}}`.
        const placeholders = new Set<string>()
        for (const source of Object.values(commands)) {
            for (const [token] of source.matchAll(/'\{\{ ?\w+ ?\}\}'/g)) placeholders.add(token.slice(1, -1))
        }
        expect(placeholders).toContain('{{ class }}')
        const unfilled = Object.entries(generatorOutputs()).filter(([, source]) => [...placeholders].some((token) => source.includes(token))).map(([file]) => file)
        expect(Object.keys(generatorOutputs()).length).toBeGreaterThan(12)
        expect(unfilled).toEqual([])
    })

    it('type-checks the TSX every generator writes, strict', () => {
        expect(typecheck('tsconfig.extensions.json', extensionSources)).toEqual([])
    }, 120_000)

    it('types react-dom as the shim the build sends it to, strict', () => {
        expect(typecheck('tsconfig.extensions.json', [REACT_DOM_PROBE])).toEqual([])
    }, 120_000)

    it('takes a real i18next instance and the test kit provider, with no cast, strict', () => {
        expect(typecheck('tsconfig.extensions.json', [I18NEXT_PROBE])).toEqual([])
    }, 120_000)

    it('types each shimmed library\'s own types from the declarations, and no class the shim does not export, strict', () => {
        expect(typecheck('tsconfig.extensions.json', [LIBRARY_TYPES_PROBE])).toEqual([])
    }, 120_000)

    it('type-checks the extension entry once it imports @martis/runtime, strict', () => {
        // Its own program, with the entry as the only root, as in an app
        // whose index.ts registers through the runtime.
        expect(typecheck('tsconfig.extensions.json', [ENTRY_WITH_RUNTIME])).toEqual([])
    }, 120_000)

    it('types the extension sources the same way for an editor, through the tsconfig.json next to them', () => {
        // Editors (tsserver) use the nearest tsconfig.json, not tsconfig.extensions.json.
        const editor = JSON.parse(extensionStubs['martis-extensions-tsconfig.json.stub'] ?? '{}') as { extends?: string; include?: string[] }
        expect(editor.extends).toBe('../../../tsconfig.extensions.json')
        expect(editor.include).toEqual(['./**/*'])
        expect(compilerOptions(`${EXT}/tsconfig.json`).paths).toEqual(compilerOptions('tsconfig.extensions.json').paths)
        expect(typecheck(`${EXT}/tsconfig.json`, extensionSources)).toEqual([])
    }, 120_000)

    it('type-checks every docs example that imports @martis/runtime or a shimmed module, or uses window.Martis', () => {
        const importable = importableNames()
        const errors = typecheck('tsconfig.docs.json', [`${EXT}/index.ts`, ...Object.keys(docsBlocks())]).filter((line) => {
            // Context a snippet leaves out: a local it does not declare, the reader's own module.
            const missing = /TS(?:2304|2552): Cannot find name '(\w+)'/.exec(line)?.[1]
            if (missing !== undefined && !importable.has(missing)) return false

            return !/TS2307: Cannot find module '\./.test(line)
        })
        expect(Object.keys(docsBlocks()).length).toBeGreaterThan(30)
        expect(errors).toEqual([])
    }, 120_000)
})
