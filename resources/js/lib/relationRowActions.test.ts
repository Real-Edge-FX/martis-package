import { describe, it, expect } from 'vitest'
import { pivotRowActions } from './relationRowActions'

describe('pivotRowActions — RP-139: read-only pivot panels collapse the Actions column', () => {
  const base = {
    readOnly: false,
    pivotFieldsCount: 0,
    canDetach: false,
    hideEditAction: false,
    hideDeleteAction: false,
  }

  it('reports no actions for a fully read-only panel (canDetach false, no pivot fields)', () => {
    // The exact RP-139 scenario: canAttach/canDetach false + all hide flags.
    // hasAny=false is what tells the field to omit rowActionsExtras so the
    // shell collapses the otherwise-blank Actions column.
    const r = pivotRowActions({ ...base, canDetach: false, pivotFieldsCount: 0 })
    expect(r).toEqual({ showEditPivot: false, showDetach: false, hasAny: false })
  })

  it('reports no actions when readOnly, even with pivot fields and detach otherwise allowed', () => {
    const r = pivotRowActions({ ...base, readOnly: true, pivotFieldsCount: 3, canDetach: true })
    expect(r.hasAny).toBe(false)
    expect(r.showEditPivot).toBe(false)
    expect(r.showDetach).toBe(false)
  })

  it('shows detach (and hasAny) when canDetach and not hidden', () => {
    const r = pivotRowActions({ ...base, canDetach: true })
    expect(r).toEqual({ showEditPivot: false, showDetach: true, hasAny: true })
  })

  it('shows edit-pivot (and hasAny) when pivot fields exist and edit is not hidden', () => {
    const r = pivotRowActions({ ...base, pivotFieldsCount: 2 })
    expect(r).toEqual({ showEditPivot: true, showDetach: false, hasAny: true })
  })

  it('honours hideDeleteAction — detach hidden collapses when it is the only action', () => {
    const r = pivotRowActions({ ...base, canDetach: true, hideDeleteAction: true })
    expect(r).toEqual({ showEditPivot: false, showDetach: false, hasAny: false })
  })

  it('honours hideEditAction — edit-pivot hidden collapses when it is the only action', () => {
    const r = pivotRowActions({ ...base, pivotFieldsCount: 2, hideEditAction: true })
    expect(r).toEqual({ showEditPivot: false, showDetach: false, hasAny: false })
  })

  it('keeps the column when at least one action survives (edit hidden, detach allowed)', () => {
    const r = pivotRowActions({ ...base, pivotFieldsCount: 2, hideEditAction: true, canDetach: true })
    expect(r).toEqual({ showEditPivot: false, showDetach: true, hasAny: true })
  })
})
