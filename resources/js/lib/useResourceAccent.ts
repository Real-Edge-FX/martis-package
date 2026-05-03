import { useMemo, type CSSProperties } from 'react'

const HEX_RE = /^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i

/**
 * Wrapper-scoped accent override for a resource view.
 *
 * The Resource API lets each resource declare an accent that should
 * recolour its surfaces while it is open:
 *
 *     class ProjectResource extends Resource
 *     {
 *         public static function accentColor(): ?string
 *         {
 *             return 'violet';   // or '#7C3AED' for a custom hex
 *         }
 *     }
 *
 * Pre-v1.8.8 the override was applied to the root `<html>` element,
 * which made the accent leak everywhere — including the sidebar,
 * topbar and any badges sitting outside the resource page (the
 * "PRO" pill on a sibling resource's nav row, for example, would
 * adopt the active resource's accent because it referenced
 * `var(--martis-accent)`).
 *
 * v1.8.8 returns props for a wrapper element instead. Spread the
 * result onto the outermost div the page renders; the override is
 * scoped to descendants only, leaving sidebar / topbar / sibling
 * chrome on the user's global accent.
 *
 * Two forms accepted:
 *
 *   - **Named accent** (`'martis' | 'blue' | 'teal' | 'violet' |
 *     'amber'`, or any custom name the host theme registered) →
 *     emits `data-resource-accent="<name>"`. CSS rules in the
 *     bundled stylesheet map the name to its 6 token values.
 *   - **Hex string** (`'#RGB' | '#RRGGBB' | '#RRGGBBAA'`) → emits
 *     an inline `--martis-accent` custom property only. Hover /
 *     active / bg / focus tokens stay on the global value, which
 *     keeps the visual surface coherent without forcing the
 *     consumer to declare a full palette per hex.
 *   - `null` / `undefined` → returns an empty object, keeping
 *     the user's preference active everywhere.
 */
export function useResourceAccent(
  accent: string | null | undefined,
): { 'data-resource-accent'?: string; style?: CSSProperties } {
  return useMemo(() => {
    if (!accent) return {}

    if (HEX_RE.test(accent)) {
      // Inline custom property — narrow CSSProperties cast handles
      // the `--martis-*` keys React's typings don't model.
      return { style: { '--martis-accent': accent } as CSSProperties }
    }

    return { 'data-resource-accent': accent }
  }, [accent])
}
