import { resolveShellOverride } from '@/lib/shellComponents'

/**
 * A top-bar slot (v2.7.0): the component registered under `topbar:start` or
 * `topbar:end` (or under the key `martis.layout.components.topbar_start` /
 * `topbar_end` names), rendered with no props, or nothing. The sidebar
 * layout's top bar and the topnav layout's bar both render the two slots,
 * so an app adds to the bar without replacing it.
 */
export function TopbarSlot({ slot }: { slot: 'topbar_start' | 'topbar_end' }) {
  const Slot = resolveShellOverride(slot)

  return Slot ? <Slot /> : null
}
