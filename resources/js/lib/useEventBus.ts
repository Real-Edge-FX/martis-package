import { useCallback, useEffect, useRef } from 'react'
import { martisEventBus, type EventBusEvents, type EventHandler } from '@/lib/eventBus'

/**
 * React hook for the Martis event bus.
 *
 * Provides `emit`, `on`, `once`, and `off` with automatic cleanup
 * when the component unmounts (prevents memory leaks).
 *
 * @example
 * ```tsx
 * const { on, emit } = useEventBus()
 *
 * useEffect(() => {
 *   return on('martis:record-created', ({ resourceKey }) => {
 *     console.log('Created in', resourceKey)
 *   })
 * }, [on])
 *
 * // Emit elsewhere:
 * emit('martis:record-created', { resourceKey: 'posts', id: 1 })
 * ```
 */
export function useEventBus() {
  const trackRef = useRef<Array<{ event: string; handler: EventHandler }>>([])

  // Cleanup all handlers on unmount
  useEffect(() => {
    return () => {
      for (const { event, handler } of trackRef.current) {
        martisEventBus.off(event, handler)
      }
      trackRef.current = []
    }
  }, [])

  /**
   * Subscribe to an event. Returns an unsubscribe function.
   * Handlers are automatically cleaned up when the component unmounts.
   */
  const on = useCallback(<K extends Extract<keyof EventBusEvents, string>>(
    event: K,
    handler: (payload: EventBusEvents[K]) => void,
  ): (() => void) => {
    const typedHandler = handler as EventHandler
    martisEventBus.on(event as string, typedHandler)
    trackRef.current.push({ event: event as string, handler: typedHandler })

    return () => {
      martisEventBus.off(event as string, typedHandler)
      trackRef.current = trackRef.current.filter(
        (h) => h.event !== event || h.handler !== typedHandler,
      )
    }
  }, [])

  /**
   * Subscribe to an event once. Auto-removed after first call.
   * Returns an unsubscribe function.
   */
  const once = useCallback(<K extends Extract<keyof EventBusEvents, string>>(
    event: K,
    handler: (payload: EventBusEvents[K]) => void,
  ): (() => void) => {
    const typedHandler = handler as EventHandler
    martisEventBus.once(event as string, typedHandler)
    trackRef.current.push({ event: event as string, handler: typedHandler })

    return () => {
      martisEventBus.off(event as string, typedHandler)
    }
  }, [])

  /**
   * Unsubscribe a specific handler from an event.
   */
  const off = useCallback(<K extends Extract<keyof EventBusEvents, string>>(
    event: K,
    handler: (payload: EventBusEvents[K]) => void,
  ): void => {
    const typedHandler = handler as EventHandler
    martisEventBus.off(event as string, typedHandler)
    trackRef.current = trackRef.current.filter(
      (h) => h.event !== event || h.handler !== typedHandler,
    )
  }, [])

  /**
   * Emit an event with a payload.
   */
  const emit = useCallback(<K extends Extract<keyof EventBusEvents, string>>(
    event: K,
    payload: EventBusEvents[K],
  ): void => {
    martisEventBus.emit(event, payload)
  }, [])

  return { on, once, off, emit }
}
