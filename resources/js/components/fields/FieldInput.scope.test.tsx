import { describe, it, expect, vi } from 'vitest'
import { render, renderHook } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import type { FieldInputProps } from './types'
import { componentRegistry } from '@/lib/componentRegistry'
import { FieldInput } from './FieldRenderer'
import { FieldsForm } from './FieldsForm'
import { useMartisForm } from '@/hooks/useMartisForm'

/*
 * Server-scoped fields (the remote Select search, v1.37.0) need to know
 * which Resource or Tool owns the form and which context it renders in.
 * `useMartisForm` carries `toolKey` next to `resourceKey`, and `FieldInput`
 * forwards both scope keys plus `context` to the concrete input, through
 * every layout container.
 */

vi.mock('@/hooks/useDependsOnSync', () => ({
  useDependsOnSync: () => new Map(), // no server-side overrides in this test
}))

let received: FieldInputProps | null = null

function Probe(props: FieldInputProps) {
  received = props
  return <div data-testid="probe" />
}

componentRegistry.register('probe:input', Probe)

function field(attribute: string, extra: Partial<FieldDefinition> = {}): FieldDefinition {
  return {
    attribute, label: attribute, type: 'text', component: 'probe:input',
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: true, showOnDetail: true, showOnForms: true,
    rules: [], ...extra,
  } as FieldDefinition
}

describe('form scope threading', () => {
  it('useMartisForm exposes toolKey and includes it in fieldProps()', () => {
    const f = field('model')
    const { result } = renderHook(() => useMartisForm({ fields: [f], toolKey: 'settings' }))

    expect(result.current.toolKey).toBe('settings')
    expect(result.current.fieldProps(f).toolKey).toBe('settings')
    expect(result.current.fieldProps(f).resourceKey).toBeUndefined()
  })

  it('FieldInput forwards toolKey and context to the concrete input', () => {
    received = null
    render(
      <FieldInput field={field('model')} value={null} onChange={() => {}} toolKey="settings" context="update" />,
    )

    // Read through a cast: TS narrows `received` to null after the reset above.
    const got = received as FieldInputProps | null
    expect(got?.toolKey).toBe('settings')
    expect(got?.context).toBe('update')
  })

  it('FieldsForm threads toolKey through section, panel and tab_group containers', () => {
    const seen: Record<string, string | undefined> = {}
    function Recorder(props: FieldInputProps) {
      seen[props.field.attribute] = props.toolKey
      return null
    }
    componentRegistry.register('recorder:input', Recorder)
    const mk = (attribute: string) => field(attribute, { component: 'recorder:input' })
    const fields = [
      mk('top'),
      { type: 'section', title: 'S', description: null, columns: 1, collapsible: false, collapsedByDefault: false, limit: null, fields: [mk('in_section')] },
      { type: 'panel', title: 'P', description: null, collapsible: false, collapsedByDefault: false, limit: null, fields: [mk('in_panel')] },
      { type: 'tab_group', tabs: [{ title: 'T', fields: [mk('in_tab')] }] },
    ] as unknown as FieldDefinition[]

    function Harness() {
      const form = useMartisForm({ fields, toolKey: 'settings' })
      return <FieldsForm form={form} context="create" />
    }
    render(<Harness />)

    expect(seen).toEqual({ top: 'settings', in_section: 'settings', in_panel: 'settings', in_tab: 'settings' })
  })
})
