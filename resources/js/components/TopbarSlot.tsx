import { Component, type ErrorInfo, type ReactNode } from 'react'
import { resolveShellOverride } from '@/lib/shellComponents'

type Slot = 'topbar_start' | 'topbar_end'

/**
 * Where a top-bar slot component is rendered (v2.14.0):
 *
 * - `topbar`: in the bar itself (the sidebar preset's top bar on a wide
 *   screen, the topnav preset's bar on a wide screen).
 * - `drawer`: in the sidebar preset's mobile navigation drawer, under the
 *   brand. A phone's top bar has no room next to Martis's own items.
 * - `breadcrumbs`: in the topnav preset's breadcrumb strip on a phone (that
 *   preset has no drawer), at the end of the strip.
 */
export type TopbarSlotPlacement = 'topbar' | 'drawer' | 'breadcrumbs'

/** The props a top-bar slot component receives (v2.14.0). */
export interface TopbarSlotProps {
  placement: TopbarSlotPlacement
}

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

/** Whether a component is registered for either top-bar slot. */
export function hasTopbarSlots(): boolean {
  return resolveShellOverride('topbar_start') !== null || resolveShellOverride('topbar_end') !== null
}

/**
 * A top-bar slot (v2.7.0): the component registered under `topbar:start` or
 * `topbar:end` (or under the key `martis.layout.components.topbar_start` /
 * `topbar_end` names), or nothing. The sidebar layout's top bar and the
 * topnav layout's bar both render the two slots, so an app adds to the bar
 * without replacing it. On a phone the slots leave the bar (v2.14.0): the
 * sidebar layout renders them in its navigation drawer, the topnav layout
 * in its breadcrumb strip. The component receives its `placement`, so it
 * can render a fuller or a more compact form. A component that throws
 * renders nothing; the rest of the bar stays.
 */
export function TopbarSlot({ slot, placement = 'topbar' }: { slot: Slot; placement?: TopbarSlotPlacement }) {
  const Registered = resolveShellOverride(slot)

  return Registered ? (
    <SlotBoundary slot={slot}>
      <Registered placement={placement} />
    </SlotBoundary>
  ) : null
}
