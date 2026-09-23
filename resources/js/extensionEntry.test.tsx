import { beforeAll, describe, expect, it } from 'vitest'
import ts from 'typescript'
import { render, screen } from '@testing-library/react'
import { componentRegistry } from '@/lib/componentRegistry'
import { FieldDisplay, FieldInput, registerDefaultFields } from '@/components/fields/FieldRenderer'
import type { FieldDefinition } from '@/types'
import indexStub from '../../stubs/extensions/index.ts.stub?raw'

/**
 * The extension entry `martis:install` publishes
 * (`stubs/extensions/index.ts.stub`), run against the SPA's own registry
 * with the modules `import.meta.glob` loads from the four buckets: each
 * file has to land under the key the SPA looks it up by, and the key the
 * generators write on the PHP side (`martis:tool`, `martis:card`,
 * `martis:field`, `martis:component`).
 */

type Bucket = Record<string, Record<string, unknown>>

function runEntry(buckets: Record<string, Bucket>): void {
    const js = ts.transpileModule(indexStub, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText
    // `import.meta.glob('./tools/*.tsx', { eager: true })` returns the bucket's modules by path.
    const body = js.replace(/import\.meta\.glob\(/g, 'glob(').replace(/^export \{\};?\s*$/m, '')
    new Function('window', 'glob', body)({ Martis: { componentRegistry } }, (pattern: string) => buckets[pattern] ?? {})
}

const field = (type: string): FieldDefinition => ({ type, attribute: 'value', label: 'Value' }) as unknown as FieldDefinition

/**
 * File names and the key the entry derives from each: PascalCase to kebab,
 * an acronym kept whole. `tests/Feature/Console/ExtensionKeyTest.php` holds
 * the same table for the PHP side, which the generators use.
 */
const DERIVED_KEYS: [string, string][] = [
    ['Charts', 'charts'],
    ['SystemHealth', 'system-health'],
    ['SEOReport', 'seo-report'],
    ['HTTPStatus', 'http-status'],
    ['OAuthClients', 'o-auth-clients'],
    ['ApiV2Keys', 'api-v2-keys'],
]

describe('the scaffold extension entry', () => {
    beforeAll(() => registerDefaultFields())

    it('registers a Tool and a card under the keys their PHP classes bind', () => {
        const Charts = () => null
        const RevenueGauge = () => null
        runEntry({
            './tools/*.tsx': { './tools/Charts.tsx': { default: Charts } },
            './cards/*.tsx': { './cards/RevenueGauge.tsx': { default: RevenueGauge } },
        })

        expect(componentRegistry.resolve('tool:charts')).toBe(Charts)
        expect(componentRegistry.resolve('card:revenue-gauge')).toBe(RevenueGauge)
    })

    it.each(DERIVED_KEYS)('derives the key of %s as %s', (name, kebab) => {
        const Tool = () => null
        runEntry({ './tools/*.tsx': { [`./tools/${name}.tsx`]: { default: Tool } } })

        expect(componentRegistry.resolve(`tool:${kebab}`)).toBe(Tool)
    })

    it('renders a field of the fields bucket through its own Display and Input', () => {
        // `martis:field PriceTag` writes `fields/PriceTag.tsx` and a PHP class
        // whose `type()` is `price-tag`: the renderer resolves that type.
        const Display = () => <span>price display</span>
        const Input = () => <span>price input</span>
        runEntry({ './fields/*.tsx': { './fields/PriceTag.tsx': { Display, Input } } })

        render(<FieldDisplay field={field('price-tag')} value="12" />)
        render(<FieldInput field={field('price-tag')} value="12" onChange={() => undefined} />)

        expect(screen.getByText('price display')).toBeTruthy()
        expect(screen.getByText('price input')).toBeTruthy()
        // An explicit PHP `->component('field:price-tag')` still reaches the display.
        expect(componentRegistry.resolve('field:price-tag')).toBe(Display)
    })

    it('renders a field module with only a default export in both contexts', () => {
        const Stars = () => <span>stars</span>
        runEntry({ './fields/*.tsx': { './fields/StarRating.tsx': { default: Stars } } })

        render(<FieldDisplay field={field('star-rating')} value={3} />)
        render(<FieldInput field={field('star-rating')} value={3} onChange={() => undefined} />)

        expect(screen.getAllByText('stars')).toHaveLength(2)
    })

    it('registers the overrides under their fixed or derived keys', () => {
        const Sidebar = () => null
        const Display = () => null
        const Input = () => null
        const RichBio = () => null
        runEntry({
            './overrides/*.tsx': {
                './overrides/Sidebar.tsx': { default: Sidebar },
                './overrides/StatusBadge.tsx': { Display, Input },
                './overrides/RichBio.tsx': { default: RichBio },
            },
        })

        expect(componentRegistry.resolve('layout:sidebar')).toBe(Sidebar)
        expect(componentRegistry.resolve('status-badge')).toBe(Display)
        expect(componentRegistry.resolve('status-badge-input')).toBe(Input)
        expect(componentRegistry.resolve('rich-bio')).toBe(RichBio)
    })
})
