import type { CSSProperties } from 'react'

/**
 * Resolves the spans a field occupies at each breakpoint of a form or detail
 * field grid (the body of a Section, a Panel or a Tab) from the `colSpan` /
 * `colSpanMd` / `colSpanLg` values `Field::toArray()` serialises.
 *
 * The cascade is mobile-first, like Tailwind's `col-span-* md:col-span-*
 * lg:col-span-*` and the dashboard grid (lib/cardGridSpan.ts): a tier that is
 * not declared inherits the one below it. The breakpoints themselves are not
 * applied here. The grid carries its track count (`fieldGridStyle`) and each
 * field its three values (`fieldGridSpanStyle`) as custom properties, and
 * `.martis-field-grid` in martis.css owns `grid-column` per media query, so
 * the SPA never writes an inline `grid-column` that a stylesheet could not
 * override. Below `md` that stylesheet gives every field the full row
 * (REA-1292).
 */
export interface FieldGridSpanSource {
  colSpan?: number | null
  colSpanMd?: number | null
  colSpanLg?: number | null
}

export interface FieldGridSpan {
  /** `colSpan()` (or `span()`): the base span, honoured from `md` when no other tier is set. */
  base: number
  /** `colSpanMd()` falling back to `base`: the span from 768px. */
  md: number
  /** `colSpanLg()` falling back to `md`: the span from 1024px. */
  lg: number
}

/** Tracks of a Panel or Tab grid, and of a Section without `columns()`. */
export const FIELD_GRID_COLUMNS = 12

function trackCount(columns: number | null | undefined): number {
  return Math.max(1, Math.round(columns ?? FIELD_GRID_COLUMNS))
}

/**
 * `columns` is the track count of the grid the field sits in
 * (`Section::columns()`, 12 for a Panel or a Tab). Every tier is clamped to
 * it: a span wider than the grid would make the browser add implicit tracks,
 * squeezing the real columns and letting the next fields flow into the extra
 * ones, so it takes the full row instead. A field without a span takes the
 * full row too.
 */
export function resolveFieldGridSpan(field: FieldGridSpanSource, columns: number = FIELD_GRID_COLUMNS): FieldGridSpan {
  const tracks = trackCount(columns)
  const clamp = (span: number): number => Math.max(1, Math.min(tracks, Math.round(span)))

  const base = clamp(field.colSpan ?? tracks)
  const md = clamp(field.colSpanMd ?? base)
  const lg = clamp(field.colSpanLg ?? md)

  return { base, md, lg }
}

/**
 * Inline style for a grid item: the resolved spans as the custom properties
 * `.martis-field-grid > *` reads at each breakpoint.
 */
export function fieldGridSpanStyle(field: FieldGridSpanSource, columns: number = FIELD_GRID_COLUMNS): CSSProperties {
  const span = resolveFieldGridSpan(field, columns)

  return {
    '--martis-field-span': String(span.base),
    '--martis-field-span-md': String(span.md),
    '--martis-field-span-lg': String(span.lg),
  } as CSSProperties
}

/**
 * Inline style for the grid itself: its track count, which
 * `.martis-field-grid` turns into `grid-template-columns`. Written on every
 * field grid, not only on a Section with custom columns, so a grid nested in
 * another one never inherits the outer count.
 */
export function fieldGridStyle(columns: number = FIELD_GRID_COLUMNS): CSSProperties {
  return { '--martis-field-columns': String(trackCount(columns)) } as CSSProperties
}
