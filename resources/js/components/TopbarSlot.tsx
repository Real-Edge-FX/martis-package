import { Component, type ErrorInfo, type ReactNode } from 'react'
import { resolveShellOverride } from '@/lib/shellComponents'

type Slot = 'topbar_start' | 'topbar_end'

/**
 * Keeps a failing slot component from taking the top bar (search, bell,
 * menus) down with it: the slot renders nothing and the error is logged.
 */
class SlotBoundary extends Component<{ slot: Slot; children: ReactNode }, { failed: boolean }> {
  state = { failed: false }

  static getDerivedStateFromError(): { failed: boolean } {
    return { failed: true }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error(`[martis] The ${this.props.slot.replace('_', ':')} slot component threw; the slot renders nothing.`, error, info)
  }

  render() {
    return this.state.failed ? null : this.props.children
  }
}

/**
 * A top-bar slot (v2.7.0): the component registered under `topbar:start` or
 * `topbar:end` (or under the key `martis.layout.components.topbar_start` /
 * `topbar_end` names), rendered with no props, or nothing. The sidebar
 * layout's top bar and the topnav layout's bar both render the two slots,
 * so an app adds to the bar without replacing it. A component that throws
 * renders nothing; the rest of the bar stays.
 */
export function TopbarSlot({ slot }: { slot: Slot }) {
  const Registered = resolveShellOverride(slot)

  return Registered ? (
    <SlotBoundary slot={slot}>
      <Registered />
    </SlotBoundary>
  ) : null
}
