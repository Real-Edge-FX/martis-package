import { it, expect, vi } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { useMartisForm } from '@/hooks/useMartisForm'
import type { FieldDefinition } from '@/types'

// Capture the options useMartisForm passes to useDependsOnSync so we can
// assert the `disabled` gate (syncDisabled / missing resourceKey) is threaded.
const depsSpy = vi.hoisted(() => vi.fn((_opts: { disabled?: boolean }) => new Map()))
vi.mock('@/hooks/useDependsOnSync', () => ({
  useDependsOnSync: (opts: { disabled?: boolean }) => depsSpy(opts),
}))

const fields: FieldDefinition[] = [
  { type: 'text', attribute: 'title', label: 'Title' } as unknown as FieldDefinition,
  { type: 'slug', attribute: 'slug', label: 'Slug' } as unknown as FieldDefinition,
]

it('tracks values, threads fieldProps, and surfaces errors', () => {
  const { result } = renderHook(() =>
    useMartisForm({ fields, initialValues: { title: 'Hello' }, resourceKey: 'projects', context: 'create' }))

  expect(result.current.values.title).toBe('Hello')

  const p = result.current.fieldProps(fields[1])
  expect(p.resourceKey).toBe('projects')
  expect(p.formValues).toBe(result.current.values)

  act(() => result.current.setValue('slug', 'hello'))
  expect(result.current.values.slug).toBe('hello')

  act(() => result.current.setErrors({ slug: 'taken' }))
  expect(result.current.fieldProps(fields[1]).error).toBe('taken')
})

it('threads recordId to the dependsOn sync so update-context requests carry the record id', () => {
  depsSpy.mockClear()
  renderHook(() =>
    useMartisForm({ fields, resourceKey: 'projects', context: 'update', recordId: 42 }))
  expect((depsSpy.mock.calls[0][0] as { recordId?: unknown }).recordId).toBe(42)
})

it('gates the dependsOn sync: disabled when syncDisabled or no resourceKey, enabled otherwise', () => {
  // syncDisabled true (e.g. update form before the record hydrates) → no sync.
  depsSpy.mockClear()
  renderHook(() =>
    useMartisForm({ fields, resourceKey: 'projects', context: 'update', syncDisabled: true }))
  expect(depsSpy.mock.calls[0][0].disabled).toBe(true)

  // Ready + scoped → sync enabled.
  depsSpy.mockClear()
  renderHook(() =>
    useMartisForm({ fields, resourceKey: 'projects', context: 'update', syncDisabled: false }))
  expect(depsSpy.mock.calls[0][0].disabled).toBe(false)

  // No resourceKey → never sync, regardless of syncDisabled.
  depsSpy.mockClear()
  renderHook(() => useMartisForm({ fields, context: 'create' }))
  expect(depsSpy.mock.calls[0][0].disabled).toBe(true)
})

it('resolves an immutable field as readonly on an update form only', () => {
  const code = { type: 'text', attribute: 'code', label: 'Code', readonly: false, immutable: true } as unknown as FieldDefinition

  const update = renderHook(() => useMartisForm({ fields: [code], context: 'update' }))
  expect(update.result.current.resolvedFields[0]!.readonly).toBe(true)
  expect(update.result.current.fieldProps(update.result.current.resolvedFields[0]!).field.readonly).toBe(true)

  const create = renderHook(() => useMartisForm({ fields: [code], context: 'create' }))
  expect(create.result.current.resolvedFields[0]!.readonly).toBe(false)
})

it('keeps an immutable field readonly on an update form after a dependsOn sync', () => {
  const code = {
    type: 'text', attribute: 'code', label: 'Code', readonly: false, immutable: true, dependsOn: { fields: ['title'] },
  } as unknown as FieldDefinition
  // A sync-field response carries the flags the way the schema does.
  const overrides = new Map([['code', { ...code, placeholder: 'Synced' }]])
  depsSpy.mockImplementation(() => overrides)
  try {
    const { result } = renderHook(() => useMartisForm({ fields: [code], resourceKey: 'projects', context: 'update' }))
    expect(result.current.resolvedFields[0]).toMatchObject({ placeholder: 'Synced', readonly: true })
  } finally {
    depsSpy.mockImplementation(() => new Map())
  }
})

// A form built for a stored record hands its context to every input, so an
// input that behaves differently on an edit form (a Slug keeps a stored slug,
// a Repeater locks the immutable fields of its stored rows) sees 'update'.
// fieldProps() left it out, and FieldsForm rendered 'create' unless told.
it('hands the form context to every input', () => {
  const update = renderHook(() => useMartisForm({ fields, resourceKey: 'projects', context: 'update', recordId: 42 }))
  expect(update.result.current.context).toBe('update')
  expect(update.result.current.fieldProps(fields[1]).context).toBe('update')

  const create = renderHook(() => useMartisForm({ fields, resourceKey: 'projects' }))
  expect(create.result.current.context).toBe('create')
  expect(create.result.current.fieldProps(fields[1]).context).toBe('create')
})
