import type { ComponentType } from 'react'
import { config } from '@/lib/config'
import { componentRegistry } from '@/lib/componentRegistry'

/**
 * The shell pieces and top-bar slots a consumer can fill from the component
 * registry, with the registry key each one reads by default. The pieces
 * replace a bundled component; the slots (v2.7.0) add one to the top bar.
 */
const DEFAULT_KEYS = {
  sidebar: 'layout:sidebar',
  topbar: 'layout:topbar',
  topbar_start: 'topbar:start',
  topbar_end: 'topbar:end',
} as const

export type ShellPiece = keyof typeof DEFAULT_KEYS

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyComponent = ComponentType<any>

/**
 * The component registered for a shell piece or slot, or null. Resolution:
 *
 *   1. `config('martis.layout.components.<piece>')`: a custom registry key
 *      set in PHP config. Wins when the key is registered.
 *   2. The default key (`layout:sidebar`, `topbar:start`, ...). Any
 *      component registered there wins when no config override is set.
 *   3. Null: the caller renders its bundled component, or nothing.
 */
export function resolveShellOverride(piece: ShellPiece): AnyComponent | null {
  const configured = config.layout?.components?.[piece]
  if (configured && componentRegistry.has(configured)) {
    const override = componentRegistry.resolve(configured)
    if (override) return override as unknown as AnyComponent
  }
  const defaultKey = DEFAULT_KEYS[piece]
  if (componentRegistry.has(defaultKey)) {
    const override = componentRegistry.resolve(defaultKey)
    if (override) return override as unknown as AnyComponent
  }
  return null
}
