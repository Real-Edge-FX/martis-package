/**
 * Martis Event Bus
 *
 * A lightweight publish/subscribe event system for React components.
 *
 * Built-in events:
 *   - martis:record-created  — fired after a record is created
 *   - martis:record-updated  — fired after a record is updated
 *   - martis:record-deleted  — fired after a record is deleted
 *   - martis:record-restored — fired after a record is restored
 *   - martis:action-executed — fired after an action completes
 *   - martis:refresh-index   — request index table to refresh its data
 *   - martis:notification-received — pluggable real-time feed for the
 *     notification bell; any transport (consumer ws-gateway, SSE, Echo
 *     listener) can emit this to push a notification instantly instead
 *     of waiting for the bell's poll interval
 *
 * Usage:
 * ```ts
 * // Subscribe
 * const { on, off } = useEventBus()
 * useEffect(() => {
 *   const handler = (payload) => console.log(payload)
 *   on('martis:record-created', handler)
 *   return () => off('martis:record-created', handler)
 * }, [on, off])
 *
 * // Emit
 * const { emit } = useEventBus()
 * emit('martis:record-created', { resourceKey: 'posts', id: 1 })
 * ```
 */

type EventPayload = Record<string, unknown>
type EventHandler = (payload: EventPayload) => void

interface EventBusEvents {
  'martis:record-created': { resourceKey: string; id: number | string }
  'martis:record-updated': { resourceKey: string; id: number | string }
  'martis:record-deleted': { resourceKey: string; id: number | string }
  'martis:record-restored': { resourceKey: string; id: number | string }
  'martis:action-executed': { resourceKey: string; action: string; ids: (number | string)[] }
  'martis:refresh-index': { resourceKey?: string }
  'martis:notification-received': { id?: string | number; title?: string; message?: string }
}

/** All known event names plus any custom string key. */
type EventName = keyof EventBusEvents | (string & Record<never, never>)

class EventBus {
  private readonly listeners = new Map<string, Set<EventHandler>>()

  /** Subscribe to an event. The handler is called on every emission. */
  on(event: string, handler: EventHandler): void {
    if (!this.listeners.has(event)) {
      this.listeners.set(event, new Set())
    }
    this.listeners.get(event)!.add(handler)
  }

  /**
   * Subscribe to an event once. The handler is automatically removed after first call.
   */
  once(event: string, handler: EventHandler): void {
    const wrappedHandler: EventHandler = (payload) => {
      handler(payload)
      this.off(event, wrappedHandler)
    }
    this.on(event, wrappedHandler)
  }

  /** Unsubscribe a handler from an event. */
  off(event: string, handler: EventHandler): void {
    this.listeners.get(event)?.delete(handler)
  }

  /**
   * Emit an event, calling all registered handlers with the payload.
   */
  emit<K extends Extract<keyof EventBusEvents, string>>(event: K, payload: EventBusEvents[K]): void
  emit(event: string, payload?: EventPayload): void
  emit(event: string, payload: EventPayload = {}): void {
    const handlers = this.listeners.get(event)
    if (!handlers || handlers.size === 0) return

    // Call handlers in insertion order, defensively copying the set
    // in case a handler removes itself during iteration.
    for (const handler of [...handlers]) {
      try {
        handler(payload)
      } catch (err) {
        console.error(`[Martis EventBus] Handler threw for event "${event}":`, err)
      }
    }
  }

  /**
   * Remove all handlers for a given event (or all events if not specified).
   */
  clear(event?: string): void {
    if (event) {
      this.listeners.delete(event)
    } else {
      this.listeners.clear()
    }
  }

  /**
   * Returns the number of handlers registered for an event.
   * Useful for testing and debugging.
   */
  listenerCount(event: string): number {
    return this.listeners.get(event)?.size ?? 0
  }
}

/**
 * Singleton event bus instance shared across the entire Martis application.
 */
export const martisEventBus = new EventBus()

export type { EventName, EventPayload, EventHandler, EventBusEvents }
export { EventBus }
