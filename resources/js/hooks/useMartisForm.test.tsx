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
