import { describe, expect, it } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { martisRuntime } from '@/lib/martisRuntime'
import type { FieldDefinition } from '@/types'

/**
 * Contract tests for the consumer-extension runtime bag. Two concerns:
 *
 * 1. The exports a consumer reaches for are present and callable.
 *    Consumer-extension bundles bind to `window.Martis.runtime.X` at
 *    runtime — if a name silently disappears, the consumer crashes
 *    only after deploy. These tests guard the contract.
 *
 * 2. The FieldInput export added in v1.14.0 actually routes to the
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

        // Layout components
        expect(martisRuntime.AuthFrame).toBeTypeOf('function')
        expect(martisRuntime.Sidebar).toBeTypeOf('function')
        expect(martisRuntime.Topbar).toBeTypeOf('function')
        expect(martisRuntime.Footer).toBeTypeOf('function')

        // Field renderer (v1.14.0)
        expect(martisRuntime.FieldInput).toBeTypeOf('function')
        expect(martisRuntime.FieldDisplay).toBeTypeOf('function')

        // Composition components
        expect(martisRuntime.DrawerShell).toBeTypeOf('function')
        // PrimeReact Tooltip is a forwardRef object, not a plain function.
        expect(martisRuntime.Tooltip).toBeDefined()

        // 3rd-party re-exports
        expect(martisRuntime.reactRouterDom).toBeTypeOf('object')
        expect(martisRuntime.reactI18next).toBeTypeOf('object')
        expect(martisRuntime.tanstackReactQuery).toBeTypeOf('object')
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
