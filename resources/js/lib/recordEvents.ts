import { martisEventBus } from '@/lib/eventBus'

export type RecordEventKind = 'created' | 'updated' | 'deleted' | 'restored'

/**
 * Emit `martis:record-<kind>` for a write the SPA just made (v2.3.0). The
 * component that writes emits: the resource pages, the built-in drawers, the
 * relationship panels and the inline create modal. An override that writes
 * through its own API call emits itself. Skipped when the resource or the id
 * is unknown.
 */
export function emitRecordEvent(kind: RecordEventKind, resourceKey: string | undefined, id: string | number | null | undefined): void {
  if (!resourceKey || id === undefined || id === null || id === '') return
  martisEventBus.emit(`martis:record-${kind}`, { resourceKey, id })
}
