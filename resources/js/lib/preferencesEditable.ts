import { config } from './config'

/** The preference names `martis.preferences.editable` governs (v2.12.0). */
export type EditablePreference = 'theme' | 'accent' | 'brandColor' | 'density' | 'locale' | 'reducedMotion'

const ALL: readonly EditablePreference[] = ['theme', 'accent', 'brandColor', 'density', 'locale', 'reducedMotion']

/**
 * Whether users may change `key` (v2.12.0): `preferences.editable` of the
 * page config, the list the server resolved. Read from `window.MartisConfig`
 * at call time when present (the same object as `config` in the shell),
 * else from `config`. Without a list every preference is editable.
 */
export function isPreferenceEditable(key: EditablePreference): boolean {
  const source = (window as unknown as { MartisConfig?: typeof config }).MartisConfig ?? config
  const editable = source.preferences?.editable
  return !Array.isArray(editable) || editable.includes(key)
}

/**
 * `values` without the preferences users may not change. A `brandColor` of
 * `null` is part of picking an accent (it clears a hex, the default's
 * included), so it stays whenever `accent` is editable, as on the server.
 */
export function onlyEditable<T extends object>(values: T): Partial<T> {
  const out: Partial<T> = {}
  for (const [key, value] of Object.entries(values)) {
    const locked = ALL.includes(key as EditablePreference) && !isPreferenceEditable(key as EditablePreference)
    if (locked && !(key === 'brandColor' && value === null && isPreferenceEditable('accent'))) continue
    ;(out as Record<string, unknown>)[key] = value
  }
  return out
}
