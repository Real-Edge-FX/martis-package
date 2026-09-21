import type { CSSProperties } from 'react'

/**
 * Resolves the 12-column spans a dashboard card occupies at each breakpoint
 * from the `width` / `widthMd` / `widthLg` values the PHP descriptor
 * serialises (`Metric::width()` and friends, `Card::width()` and friends).
 *
 * The cascade is mobile-first, like Tailwind's `col-span-* md:col-span-*
 * lg:col-span-*`: a tier that is not declared inherits the one below it.
 * The breakpoints themselves are not applied here. The card carries the
 * three values as custom properties (see `cardGridSpanStyle`) and
 * `.martis-dashboard-grid` in martis.css owns `grid-column` per media
 * query, so the SPA never writes an inline `grid-column` that a stylesheet
 * could not override. Below `md` that stylesheet forces every card to the
 * full row, the same rule `.martis-section-grid` applies to field spans.
 */
export interface CardGridSpanSource {
  width?: number | null
  widthMd?: number | null
  widthLg?: number | null
}

export interface CardGridSpan {
  /** `width()`: the base span, honoured from `md` when no other tier is set. */
  base: number
  /** `widthMd()` falling back to `base`: the span from 768px. */
  md: number
  /** `widthLg()` falling back to `md`: the span from 1024px. */
  lg: number
}

/** `Metric::$width` default on the PHP side. */
const DEFAULT_SPAN = 4

function clamp(span: number): number {
  return Math.max(1, Math.min(12, Math.round(span)))
}

export function resolveCardGridSpan(card: CardGridSpanSource): CardGridSpan {
  const base = clamp(card.width ?? DEFAULT_SPAN)
  const md = clamp(card.widthMd ?? base)
  const lg = clamp(card.widthLg ?? md)

  return { base, md, lg }
}

/**
 * Inline style for the grid item: the resolved spans as the custom
 * properties `.martis-dashboard-grid > *` reads at each breakpoint.
 */
export function cardGridSpanStyle(card: CardGridSpanSource): CSSProperties {
  const span = resolveCardGridSpan(card)

  return {
    '--martis-card-span': String(span.base),
    '--martis-card-span-md': String(span.md),
    '--martis-card-span-lg': String(span.lg),
  } as CSSProperties
}
