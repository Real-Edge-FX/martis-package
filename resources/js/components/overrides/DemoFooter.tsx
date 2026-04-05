import { Heartbeat } from '@phosphor-icons/react'

/**
 * Demo: Custom Footer component override.
 *
 * Registered via componentRegistry.register('layout:footer', DemoFooter)
 * This replaces the default footer across the entire admin panel,
 * demonstrating the component override system.
 */
export function DemoFooter() {
  return (
    <footer
      className="flex items-center justify-between border-t px-6 py-3"
      style={{
        backgroundColor: 'var(--martis-sidebar-bg)',
        borderColor: 'var(--martis-border)',
        color: 'var(--martis-text-muted)',
        fontSize: '0.75rem',
      }}
    >
      <span className="flex items-center gap-1.5">
        <Heartbeat size={14} style={{ color: 'var(--martis-accent)' }} />
        Martis Demo — Override System Active
      </span>
      <span style={{ color: 'var(--martis-text-muted)' }}>
        Component Override Demo
      </span>
    </footer>
  )
}
