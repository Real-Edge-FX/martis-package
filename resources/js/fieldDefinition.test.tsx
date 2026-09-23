import { describe, expect, it } from 'vitest'
import { render, renderHook, screen } from '@testing-library/react'
import ts from 'typescript'
import typesSource from '@/types/index.ts?raw'
import { useMartisForm } from '@/hooks/useMartisForm'
import { FieldInput } from '@/components/fields/FieldRenderer'
import type { FieldDefinition } from '@/types'

const phpFields = import.meta.glob('../../src/Fields/*.php', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

/** The string literals of the `FieldType` union. */
function fieldTypeLiterals(): string[] {
    const file = ts.createSourceFile('index.ts', typesSource, ts.ScriptTarget.Latest)
    const alias = file.statements.find((statement): statement is ts.TypeAliasDeclaration => ts.isTypeAliasDeclaration(statement) && statement.name.text === 'FieldType')
    if (alias === undefined || !ts.isUnionTypeNode(alias.type)) return []

    return alias.type.types.flatMap((member) => (ts.isLiteralTypeNode(member) && ts.isStringLiteral(member.literal) ? [member.literal.text] : []))
}

/**
 * `FieldDefinition` is what the forms, displays and tables accept: the
 * server sends every flag (`Field::toArray()`), and a definition written by
 * hand (a Tool's own field set, docs/tool-fields.md) sends only its identity.
 * The declarations published to consumer extensions carry this type, so a
 * gap here fails the consumer's `tsc`.
 */
describe('FieldDefinition', () => {
    it('lists the type of every field the package ships in FieldType', () => {
        const shipped: string[] = []
        for (const [path, source] of Object.entries(phpFields)) {
            if (!/public function type\(\): string/.test(source)) continue
            const literal = /public function type\(\): string\s*\{\s*return '([a-z_]+)';\s*\}/.exec(source)?.[1]
            expect(literal, `${path} returns a literal type`).toBeDefined()
            shipped.push(literal as string)
        }

        expect(shipped.length).toBeGreaterThan(40)
        expect(shipped.filter((type) => !fieldTypeLiterals().includes(type)).sort()).toEqual([])
    })

    it('accepts a definition written by hand with only its type, attribute and label', () => {
        // No server flags, and `type` may name a custom field (`martis:field Rating`).
        const title: FieldDefinition = { type: 'text', attribute: 'title', label: 'Title' }
        const slug: FieldDefinition = { type: 'slug', attribute: 'slug', label: 'Slug', sourceAttribute: 'title' }
        const rating: FieldDefinition = { type: 'rating', attribute: 'score', label: 'Score' }

        const { result } = renderHook(() => useMartisForm({ fields: [title, slug, rating] }))
        expect(result.current.resolvedFields).toEqual([title, slug, rating])

        render(<FieldInput {...result.current.fieldProps(title)} />)
        expect(screen.getByRole('textbox')).toBeTruthy()
    })
})
