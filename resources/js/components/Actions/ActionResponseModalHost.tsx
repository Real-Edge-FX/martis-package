import { createContext, useCallback, useContext, useRef, useState, type ComponentType, type ReactNode } from 'react'
import { componentRegistry } from '@/lib/componentRegistry'

/** The props of a component an action shows with `ActionResponse::modal($component, $data)`. */
export interface ActionResponseModalProps {
  /** The `$data` the action passed. */
  data: Record<string, unknown>
  /** Close the modal; the page the action ran from then refreshes. */
  onClose: () => void
}

/** Show the component registered under `component`; false when none is registered. */
export type ShowActionResponseModal = (component: string, data: Record<string, unknown>, onClose: () => void) => boolean

interface ActiveModal {
  id: number
  Component: ComponentType<ActionResponseModalProps>
  data: Record<string, unknown>
  onClose: () => void
}

const ActionResponseModalContext = createContext<ShowActionResponseModal | null>(null)

/**
 * Hosts the component an action answers with `ActionResponse::modal()`
 * (v2.3.0). The shell `Layout` mounts it around every page, so the action
 * modal, a pivot action modal or a custom action component can close and
 * hand the answer over. The component draws its own dialog, as a Nova modal
 * response component does, and receives `{ data, onClose }`.
 */
export function ActionResponseModalProvider({ children }: { children: ReactNode }) {
  const [active, setActive] = useState<ActiveModal | null>(null)
  const activeRef = useRef<ActiveModal | null>(null)
  const nextId = useRef(0)

  const show = useCallback<ShowActionResponseModal>((component, data, onClose) => {
    const Component = componentRegistry.resolve(component) as ComponentType<ActionResponseModalProps> | undefined
    if (Component === undefined) return false

    nextId.current += 1
    const next = { id: nextId.current, Component, data, onClose }
    activeRef.current = next
    setActive(next)

    return true
  }, [])

  // Closing twice (a double click, two close buttons) calls onClose once.
  const close = useCallback((id: number) => {
    const current = activeRef.current
    if (current === null || current.id !== id) return
    activeRef.current = null
    setActive(null)
    current.onClose()
  }, [])

  return (
    <ActionResponseModalContext.Provider value={show}>
      {children}
      {active !== null && <active.Component key={active.id} data={active.data} onClose={() => close(active.id)} />}
    </ActionResponseModalContext.Provider>
  )
}

/** The host's `show`, or null outside the shell. */
export function useActionResponseModal(): ShowActionResponseModal | null {
  return useContext(ActionResponseModalContext)
}
