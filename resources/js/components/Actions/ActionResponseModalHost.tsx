import { Component, createContext, useCallback, useContext, useRef, useState, type ComponentType, type ReactNode } from 'react'
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
  /** The registry key the action answered with, named when the component throws. */
  key: string
  Component: ComponentType<ActionResponseModalProps>
  data: Record<string, unknown>
  onClose: () => void
}

const ActionResponseModalContext = createContext<ShowActionResponseModal | null>(null)

interface BoundaryProps {
  componentKey: string
  onError: () => void
  children: ReactNode
}

/**
 * Keeps a response component that throws from taking the shell down: the
 * router has no `errorElement`, so the error would otherwise replace the
 * whole Layout route. It logs the registry key and closes the modal, so the
 * page still refreshes, as Nova logs a failing modal component and carries on.
 */
class ResponseComponentBoundary extends Component<BoundaryProps, { failed: boolean }> {
  state = { failed: false }

  static getDerivedStateFromError(): { failed: boolean } {
    return { failed: true }
  }

  componentDidCatch(error: unknown): void {
    console.error(`[martis] action response component "${this.props.componentKey}" threw`, error)
    this.props.onError()
  }

  render(): ReactNode {
    return this.state.failed ? null : this.props.children
  }
}

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

    // A second answer closes the modal it replaces first, so the run that
    // opened it still refreshes its page.
    const previous = activeRef.current
    activeRef.current = null
    previous?.onClose()

    nextId.current += 1
    const next = { id: nextId.current, key: component, Component, data, onClose }
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
      {active !== null && (
        <ResponseComponentBoundary key={active.id} componentKey={active.key} onError={() => close(active.id)}>
          <active.Component data={active.data} onClose={() => close(active.id)} />
        </ResponseComponentBoundary>
      )}
    </ActionResponseModalContext.Provider>
  )
}

/** The host's `show`, or null outside the shell. */
export function useActionResponseModal(): ShowActionResponseModal | null {
  return useContext(ActionResponseModalContext)
}
