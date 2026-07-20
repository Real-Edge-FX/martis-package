import { useSyncExternalStore } from "react"

/**
 * Tiny React-free store for the "a locale switch is in progress" signal.
 *
 * `loadLocale()` (lib/i18n.ts) is a plain async function called from several
 * places — the two manual language pickers AND the automatic post-login /
 * reset reconciliation in PreferencesContext. Rather than thread React state
 * through all of them, `loadLocale` flips this module-level flag at the single
 * choke point and the root-mounted `LanguageSwitchOverlay` subscribes to it.
 *
 * A counter (not a boolean) balances overlapping switches: the signal stays
 * true until the last in-flight switch ends, and a stray `end` can never drive
 * it negative.
 */
let activeCount = 0
// Monotonic switch counter, bumped on every begin (even an overlapping one).
// Consumers that key off it (the overlay's safety-cap reset) can tell a fresh
// switch apart from a still-running previous one — so a switch that hangs past
// the overlay's safety cap can never suppress feedback for the NEXT switch.
let generation = 0
const listeners = new Set<() => void>()

function emit(): void {
  for (const listener of listeners) listener()
}

/** Mark a locale switch as started. Balanced by `endLocaleSwitch`. */
export function beginLocaleSwitch(): void {
  activeCount += 1
  generation += 1
  // Emit on EVERY begin: the boolean snapshot may be unchanged (an overlapping
  // switch keeps it true), but `generation` always advances and generation
  // subscribers must be notified so a fresh switch is never swallowed.
  emit()
}

/** Mark a locale switch as finished. A call with no active switch is a no-op. */
export function endLocaleSwitch(): void {
  if (activeCount === 0) return
  activeCount -= 1
  if (activeCount === 0) emit()
}

/** Subscribe to switching-state transitions. Returns an unsubscribe fn. */
export function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

/** Current snapshot: true while at least one locale switch is in flight. */
export function getSnapshot(): boolean {
  return activeCount > 0
}

/** Monotonic id that advances on every `beginLocaleSwitch` call. */
export function getGeneration(): number {
  return generation
}

/**
 * React hook: `true` while a locale switch is in progress. Backed by
 * `useSyncExternalStore` so it stays consistent under concurrent rendering.
 */
export function useLocaleSwitching(): boolean {
  return useSyncExternalStore(subscribe, getSnapshot, getSnapshot)
}

/**
 * React hook: the current switch generation (advances on every begin). Feed it
 * to `useDelayedFlag`'s `resetKey` so each new switch can show the overlay even
 * if a prior, still-active switch already tripped the safety cap.
 */
export function useLocaleSwitchGeneration(): number {
  return useSyncExternalStore(subscribe, getGeneration, getGeneration)
}
