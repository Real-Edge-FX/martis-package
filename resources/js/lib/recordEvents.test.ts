import { afterEach, describe, expect, it, vi } from 'vitest'
import { emitRecordEvent } from './recordEvents'
import { martisEventBus } from './eventBus'
import { queryClient } from './query'

// The built-in bus events the docs promise (v2.3.0): the component that
// writes a record emits, and dashboard metrics refetch after an action.

const sources = import.meta.glob(
  [
    '../pages/ResourceIndex.tsx',
    '../pages/ResourceDetail.tsx',
    '../pages/ResourceLens.tsx',
    '../pages/ResourceCreate.tsx',
    '../pages/ResourceUpdate.tsx',
    '../components/overrides/DrawerCreate.tsx',
    '../components/overrides/DrawerUpdate.tsx',
    '../components/overrides/DrawerDetail.tsx',
    '../components/InlineCreateModal.tsx',
    '../components/fields/relation/RelationshipTableShell.tsx',
    '../components/fields/HasOneField.tsx',
    '../components/fields/MorphOneField.tsx',
  ],
  { query: '?raw', import: 'default', eager: true },
) as Record<string, string>

const SITES: [string, string[]][] = [
  ['../pages/ResourceIndex.tsx', ['deleted', 'restored']],
  ['../pages/ResourceDetail.tsx', ['deleted', 'restored']],
  ['../pages/ResourceLens.tsx', ['deleted']],
  ['../pages/ResourceCreate.tsx', ['created']],
  ['../pages/ResourceUpdate.tsx', ['updated']],
  ['../components/overrides/DrawerCreate.tsx', ['created']],
  ['../components/overrides/DrawerUpdate.tsx', ['updated']],
  ['../components/overrides/DrawerDetail.tsx', ['deleted']],
  ['../components/InlineCreateModal.tsx', ['created']],
  ['../components/fields/relation/RelationshipTableShell.tsx', ['deleted', 'restored']],
  ['../components/fields/HasOneField.tsx', ['deleted']],
  ['../components/fields/MorphOneField.tsx', ['deleted']],
]

afterEach(() => vi.restoreAllMocks())

describe('emitRecordEvent', () => {
  it('emits martis:record-<kind> with the resource and the id, as a string', () => {
    // A number from API data and a string from a route param name the same
    // record: listeners always get the string (v2.3.0).
    const listener = vi.fn()
    martisEventBus.on('martis:record-restored', listener)
    emitRecordEvent('restored', 'posts', 3)
    emitRecordEvent('restored', 'posts', 0)
    martisEventBus.off('martis:record-restored', listener)

    expect(listener).toHaveBeenNthCalledWith(1, { resourceKey: 'posts', id: '3' })
    expect(listener).toHaveBeenNthCalledWith(2, { resourceKey: 'posts', id: '0' })
  })

  it('emits a string id as it came', () => {
    const listener = vi.fn()
    martisEventBus.on('martis:record-updated', listener)
    emitRecordEvent('updated', 'posts', '3')
    emitRecordEvent('updated', 'posts', '018f7c1e-9b1d-7c2a-8f3e-2b4d6a8c0e1f')
    martisEventBus.off('martis:record-updated', listener)

    expect(listener).toHaveBeenNthCalledWith(1, { resourceKey: 'posts', id: '3' })
    expect(listener).toHaveBeenNthCalledWith(2, { resourceKey: 'posts', id: '018f7c1e-9b1d-7c2a-8f3e-2b4d6a8c0e1f' })
  })

  it('emits nothing without a resource or an id', () => {
    const listener = vi.fn()
    martisEventBus.on('martis:record-deleted', listener)
    emitRecordEvent('deleted', undefined, 3)
    emitRecordEvent('deleted', 'posts', undefined)
    emitRecordEvent('deleted', 'posts', '')
    martisEventBus.off('martis:record-deleted', listener)

    expect(listener).not.toHaveBeenCalled()
  })

  it.each(SITES)('%s emits its record events', (file, kinds) => {
    for (const kind of kinds) expect(sources[file], file).toContain(`emitRecordEvent('${kind}'`)
  })
})

describe('metrics and actions', () => {
  it('invalidates the dashboard metrics when an action ran', () => {
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries').mockResolvedValue(undefined)
    martisEventBus.emit('martis:action-executed', { resourceKey: 'posts', action: 'archive', ids: [1] })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['metric'] })
  })
})
