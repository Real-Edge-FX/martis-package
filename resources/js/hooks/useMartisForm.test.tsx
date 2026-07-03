import { renderHook, act } from '@testing-library/react'
import { useMartisForm } from '@/hooks/useMartisForm'
import type { FieldDefinition } from '@/types'

vi.mock('@/hooks/useDependsOnSync', () => ({
  useDependsOnSync: () => new Map(), // no overrides in this unit test
}))

const fields: FieldDefinition[] = [
  { type: 'text', attribute: 'title', label: 'Title' } as FieldDefinition,
  { type: 'slug', attribute: 'slug', label: 'Slug' } as FieldDefinition,
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
