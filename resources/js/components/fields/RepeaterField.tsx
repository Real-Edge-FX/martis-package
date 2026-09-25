import { useCallback, useEffect, useMemo, useRef, useState, type DragEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { PlusIcon, TrashIcon, CaretUpIcon, CaretDownIcon, DotsSixVerticalIcon, XIcon, CopyIcon, ClipboardIcon } from '@phosphor-icons/react'
import { createPortal } from 'react-dom'
import { FieldInput } from './FieldRenderer'
import { ResourceIcon } from '@/components/ResourceIcon'
import { nestedErrorsOf, rowErrorsByIndex } from '@/lib/fieldErrors'
import type { FormErrors, RowErrors } from '@/lib/fieldErrors'
import type { FieldDefinition } from '@/types'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { useEscapeLayer } from '@/lib/escapeLayers'

/**
 * Row payload shape used in the React layer — mirrors the PHP payload:
 *   { id, type, fields: { attr -> value }, title? }
 * `title` is only present on rows of a repeatable whose `title()` is a
 * Closure: the server resolves it per row on every read (see
 * `Repeater::withResolvedTitle()`), the form sends it back untouched and
 * `Repeater::fill()` drops it, so it is never stored.
 */
interface RepeaterRow {
  id: string | number | null
  type: string
  fields: Record<string, unknown>
  title?: string | null
}

interface RepeatableDef {
  shortName: string
  uniqueKey: string
  label: string
  model?: string | null
  icon?: string | null
  color?: string | null
  titleTemplate?: string | null
  hasTitleCallback?: boolean
  badgeCount?: boolean
  fields: FieldDefinition[]
}

interface RepeaterTemplate {
  label: string
  type: string
  fields: Record<string, unknown>
  icon?: string | null
  color?: string | null
}

interface RepeaterMeta {
  storage?: 'json' | 'has_many' | 'polymorphic'
  typeColumn?: string
  payloadColumn?: string
  uniqueField?: string | null
  confirmRemoval?: boolean
  minRows?: number | null
  maxRows?: number | null
  collapsible?: boolean
  collapsedByDefault?: boolean
  reorderable?: boolean
  dependsOn?: string[]
  hideDuplicate?: boolean
  hideBulkPaste?: boolean
  rowTemplates?: RepeaterTemplate[]
  repeatables?: RepeatableDef[]
}

function randomId(): string {
  // RFC4122-ish — good enough for client-side row identity until the
  // server replaces it with a canonical UUID on first save.
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    const v = c === 'x' ? r : (r & 0x3) | 0x8
    return v.toString(16)
  })
}

function normalizeRows(value: unknown): RepeaterRow[] {
  if (!Array.isArray(value)) return []
  return value
    .map((raw): RepeaterRow | null => {
      if (!raw || typeof raw !== 'object') return null
      const row = raw as Record<string, unknown>
      const fields = (row.fields && typeof row.fields === 'object' && !Array.isArray(row.fields))
        ? (row.fields as Record<string, unknown>)
        : {}
      return {
        id: (row.id as string | number | null) ?? null,
        type: String(row.type ?? ''),
        fields,
        ...('title' in row ? { title: typeof row.title === 'string' ? row.title : null } : {}),
      }
    })
    .filter((r): r is RepeaterRow => r !== null)
}

/** The rows `collapsedByDefault()` starts collapsed, keyed by row id. */
function defaultCollapsed(rows: RepeaterRow[], meta: RepeaterMeta): Record<string, boolean> {
  if (!meta.collapsible || !meta.collapsedByDefault) return {}
  const map: Record<string, boolean> = {}
  rows.forEach((r) => {
    if (r.id !== null) map[String(r.id)] = true
  })
  return map
}

/** The key a row is known by: its id, or its position when it has none. */
function rowKeyOf(row: RepeaterRow, index: number): string {
  return String(row.id ?? index)
}

/** The ids of the rows that carry one. */
function rowIdsOf(rows: RepeaterRow[]): Set<string> {
  const ids = new Set<string>()
  rows.forEach((row) => {
    if (row.id !== null) ids.add(String(row.id))
  })
  return ids
}

/**
 * The values a new row starts with: each field's default, and the value
 * `seed` holds for a field the row writes (a row template's, a duplicated
 * row's, a pasted one's). A readonly field keeps its default: the save takes
 * no value of it from a new row. A key of `seed` that names no field of the
 * row type is kept.
 */
function newRowFields(rep: RepeatableDef, seed: Record<string, unknown> = {}): Record<string, unknown> {
  const fields: Record<string, unknown> = {}
  rep.fields.forEach((f) => {
    const seeded = !f.readonly && Object.prototype.hasOwnProperty.call(seed, f.attribute)
    fields[f.attribute] = seeded ? seed[f.attribute] : (f.defaultValue ?? null)
  })
  Object.entries(seed).forEach(([attribute, value]) => {
    if (!(attribute in fields)) fields[attribute] = value
  })
  return fields
}

/**
 * The server errors of the rows, bound to the key of the row each one was
 * reported for. The server keys them by the position the row had in the
 * save that failed, which is still its position when the errors arrive.
 * A row with no id (a legacy JSON row) is known by its position, so its
 * errors do not follow it when the rows above it move.
 */
function bindRowErrors(rows: RepeaterRow[], nestedErrors: FormErrors | undefined): Record<string, RowErrors> {
  const bound: Record<string, RowErrors> = {}
  for (const [index, errors] of Object.entries(rowErrorsByIndex(nestedErrors))) {
    const row = rows[Number(index)]
    if (row) bound[rowKeyOf(row, Number(index))] = errors
  }
  return bound
}

/** `collapsed` with every row that has an error open: a collapsed row would hide it. */
function openRowsWithErrors(collapsed: Record<string, boolean>, rowErrors: Record<string, RowErrors>): Record<string, boolean> {
  const keys = Object.keys(rowErrors)
  if (keys.length === 0) return collapsed
  const next = { ...collapsed }
  keys.forEach((key) => { next[key] = false })
  return next
}

/** `rowErrors` without the error of one row field (the errors inside the field's value stay). */
function withoutFieldError(rowErrors: Record<string, RowErrors>, key: string, attribute: string): Record<string, RowErrors> {
  const row = rowErrors[key]
  if (!row || !(attribute in row.fields)) return rowErrors
  const fields = { ...row.fields }
  delete fields[attribute]
  return { ...rowErrors, [key]: { ...row, fields } }
}

/** Apply a `{attr}` template against the row's field values. */
function applyTitleTemplate(template: string, rowFields: Record<string, unknown>): string {
  return template.replace(/\{([a-zA-Z0-9_]+)\}/g, (_m, key) => {
    const val = rowFields[key]
    if (val === null || val === undefined) return ''
    return String(val)
  }).trim()
}

/** Whether the repeatable computes its row header from the row (template or Closure). */
function hasDynamicTitle(rep: RepeatableDef | undefined): boolean {
  return !!rep && (!!rep.titleTemplate || rep.hasTitleCallback === true)
}

/**
 * Dynamic header text for a row, or `null` when the repeatable declares
 * none (the caller then shows the static label). The server-resolved
 * `title` of a Closure-titled row wins; a `{attr}` template is evaluated
 * live against the row's current fields. Both fall back to "Label #N" when
 * they yield nothing, so a row added or edited in the form (no resolved
 * title until the next save) stays distinguishable from its siblings.
 */
export function resolveRowTitle(rep: RepeatableDef | undefined, row: RepeaterRow, index: number): string | null {
  if (!rep) return null
  if (rep.hasTitleCallback && row.title) return row.title
  if (rep.titleTemplate) return applyTitleTemplate(rep.titleTemplate, row.fields) || `${rep.label} #${index + 1}`
  if (rep.hasTitleCallback) return `${rep.label} #${index + 1}`
  return null
}

// ---------------------------------------------------------------------------
// Display (read-only) — compact list of row summaries
// ---------------------------------------------------------------------------

export function RepeaterFieldDisplay({ field, value }: FieldDisplayProps) {
  const meta = (field as unknown as RepeaterMeta)
  const rows = normalizeRows(value)

  if (rows.length === 0) {
    return <span style={{ color: 'var(--martis-text-muted)' }}>—</span>
  }

  const repeatablesByName = new Map<string, RepeatableDef>()
  ;(meta.repeatables ?? []).forEach((r) => repeatablesByName.set(r.shortName, r))

  return (
    <div className="flex flex-col gap-2">
      {rows.map((row, index) => {
        const rep = repeatablesByName.get(row.type) ?? meta.repeatables?.[0]
        const title = resolveRowTitle(rep, row, index)
        return (
          <div
            key={String(row.id ?? index)}
            className="rounded-md border border-solid px-3 py-2 text-sm"
            style={{ borderColor: 'var(--martis-border)', backgroundColor: 'var(--martis-surface-alt)' }}
          >
            <div className="flex items-center gap-2">
              {rep?.icon && (
                <span style={{ color: tokenColor(rep.color) }}>
                  <ResourceIcon iconName={rep.icon} size={14} />
                </span>
              )}
              <span className="font-medium" style={{ color: 'var(--martis-text)' }}>
                {title || rep?.label || row.type}
              </span>
              {rep?.badgeCount && !hasDynamicTitle(rep) && (
                <span className="ml-auto text-xs" style={{ color: 'var(--martis-text-muted)' }}>
                  #{index + 1}
                </span>
              )}
            </div>
          </div>
        )
      })}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Input (create/update)
// ---------------------------------------------------------------------------

export function RepeaterFieldInput({ field, value, onChange, error, nestedErrors, resourceKey, recordId, context, toolKey, actionEndpoint, pivotEndpoint, formValues }: FieldInputProps) {
  const { t } = useTranslation('messages')
  const { t: tAct } = useTranslation('actions')

  const meta = field as unknown as RepeaterMeta
  // `fill()` skips a readonly Repeater whole (an `immutable()` one on update
  // arrives as readonly too), so its row fields render read-only and no
  // control below changes the rows.
  const repeatables = useMemo(() => {
    const defs = meta.repeatables ?? []
    if (!field.readonly) return defs
    return defs.map((rep) => ({ ...rep, fields: rep.fields.map((f) => ({ ...f, readonly: true })) }))
  }, [meta.repeatables, field.readonly])
  // The row fields of a row the record stores: the save keeps an immutable
  // field of such a row (it writes one on a new row only), so it renders
  // read-only there, as an update form renders an immutable field.
  const storedRowFields = useMemo(() => {
    const byType = new Map<string, FieldDefinition[]>()
    repeatables.forEach((rep) => {
      byType.set(rep.shortName, rep.fields.map((f) => (f.immutable && !f.readonly ? { ...f, readonly: true } : f)))
    })
    return byType
  }, [repeatables])
  const canReorder = meta.reorderable === true && !field.readonly
  const isMultiType = repeatables.length > 1
  const hasTemplates = (meta.rowTemplates?.length ?? 0) > 0
  const primaryType = repeatables[0]?.shortName ?? ''

  const rows = useMemo(() => normalizeRows(value), [value])

  // The server errors of the rows. `nestedErrors` keys them by the position
  // each row had in the save that failed (`1.fields.name`); they are bound to
  // the rows' keys when they arrive, so an error stays on its row when rows
  // are removed, reordered or added afterwards, and editing a row field
  // clears that field's error, as a form clears a field's own error on edit.
  // New errors are told apart by content while rendering: the form hands a
  // new object on every render, and every bundled form clears its errors
  // before a save, so the errors of the next failed save always arrive as new.
  const errorsSignature = nestedErrors ? JSON.stringify(nestedErrors) : ''
  const [adoptedErrors, setAdoptedErrors] = useState(errorsSignature)
  const [rowErrors, setRowErrors] = useState<Record<string, RowErrors>>(() => bindRowErrors(rows, nestedErrors))

  const [collapsed, setCollapsed] = useState<Record<string, boolean>>(() => openRowsWithErrors(defaultCollapsed(rows, meta), rowErrors))

  // The ids of the rows the record stores, on an update form: the rows of the
  // value the form hands in (the record it edits), as opposed to the rows
  // added, duplicated or pasted since, which are new.
  const [storedRowIds, setStoredRowIds] = useState<Set<string>>(() => rowIdsOf(rows))

  // The last value this input handed to `onChange`. A `value` prop that
  // differs from it came from outside (the edit form seeding the stored rows
  // after mount, "Create & add another" clearing the form), so its rows start
  // collapsed again; the form handing back what the input just emitted keeps
  // the rows the user opened, collapsed or added. Compared while rendering,
  // not in an effect: a row's own field can emit while it mounts (a slug
  // generated from a default), and child effects run before this input's.
  const emitted = useRef<unknown>(value)
  const [renderedValue, setRenderedValue] = useState<unknown>(value)
  if (value !== renderedValue) {
    setRenderedValue(value)
    if (value !== emitted.current) {
      setCollapsed(defaultCollapsed(rows, meta))
      setStoredRowIds(rowIdsOf(rows))
    }
  }

  if (errorsSignature !== adoptedErrors) {
    const bound = bindRowErrors(rows, nestedErrors)
    setAdoptedErrors(errorsSignature)
    setRowErrors(bound)
    setCollapsed((prev) => openRowsWithErrors(prev, bound))
  }

  // Which `value` the last emission started from. Several row fields can
  // emit before the form hands the rows back (every stored row whose slug
  // generates itself while it mounts), and each of their handlers still sees
  // the `rows` of the last render. While the input sees that same `value`,
  // the emitted rows are the latest ones and the next update builds on them;
  // once another value reaches it (the rows handed back, a reset), it builds
  // on that value's rows. Each value the input receives gets its own token,
  // compared instead of the value: a form cleared back to `null` after an
  // emission that started from `null` ("Create & add another") hands in a
  // value equal to the one the emission started from, and the rows of the
  // previous record would come back.
  const valueToken = useMemo(() => ({ value }), [value])
  const emittedFrom = useRef<object | null>(null)

  const [pendingRemoval, setPendingRemoval] = useState<{ index: number; label: string } | null>(null)
  const [showAddMenu, setShowAddMenu] = useState(false)
  const [draggingIndex, setDraggingIndex] = useState<number | null>(null)
  const [bulkPasteOpen, setBulkPasteOpen] = useState(false)
  const [bulkPasteType, setBulkPasteType] = useState<string>(() => repeatables[0]?.shortName ?? '')
  const [bulkPasteText, setBulkPasteText] = useState('')
  const [bulkPasteError, setBulkPasteError] = useState<string | null>(null)
  const addMenuRef = useRef<HTMLDivElement>(null)

  // Dismiss the Add-row dropdown when the user clicks outside of it.
  useEffect(() => {
    if (!showAddMenu) return
    function onPointer(e: MouseEvent) {
      if (addMenuRef.current && !addMenuRef.current.contains(e.target as Node)) {
        setShowAddMenu(false)
      }
    }
    document.addEventListener('mousedown', onPointer)
    return () => {
      document.removeEventListener('mousedown', onPointer)
    }
  }, [showAddMenu])

  // Escape closes the menu only, not a drawer the form is in.
  useEscapeLayer(showAddMenu, () => setShowAddMenu(false))

  const atMax = meta.maxRows != null && rows.length >= meta.maxRows
  const belowMin = meta.minRows != null && rows.length < meta.minRows

  const repeatableFor = useCallback((type: string): RepeatableDef | undefined => {
    return repeatables.find((r) => r.shortName === type) ?? repeatables[0]
  }, [repeatables])

  const commit = (next: RepeaterRow[]) => {
    // Also holds back a row input that ignores `readonly` (a custom one).
    if (field.readonly) return
    emittedFrom.current = valueToken
    emitted.current = next
    onChange(next)
  }

  // A fresh copy of the rows every update builds on, so updates emitted
  // before the form hands the rows back add up instead of the last one
  // replacing the others.
  const latestRows = (): RepeaterRow[] => (valueToken === emittedFrom.current ? normalizeRows(emitted.current) : rows.slice())

  const addRow = (type: string, seedFields?: Record<string, unknown>) => {
    const rep = repeatableFor(type)
    if (!rep) return
    const row: RepeaterRow = {
      id: randomId(),
      type: rep.shortName,
      fields: newRowFields(rep, seedFields),
    }
    commit([...latestRows(), row])
    setShowAddMenu(false)
  }

  const addFromTemplate = (tpl: RepeaterTemplate) => {
    addRow(tpl.type, tpl.fields)
  }

  const duplicateRow = (index: number) => {
    const next = latestRows()
    const source = next[index]
    if (!source) return
    const rep = repeatableFor(source.type)
    // The copy is a new row: a readonly field takes its default, not the
    // stored row's value.
    const copy: RepeaterRow = {
      id: randomId(),
      type: source.type,
      fields: rep ? newRowFields(rep, source.fields) : { ...source.fields },
    }
    next.splice(index + 1, 0, copy)
    commit(next)
  }

  const removeRow = (index: number) => {
    const confirmIt = meta.confirmRemoval === true
    if (confirmIt) {
      const rep = repeatableFor(rows[index]?.type ?? '')
      const row = rows[index]
      const title = (row ? resolveRowTitle(rep, row, index) : null) ?? rep?.label ?? `#${index + 1}`
      setPendingRemoval({ index, label: title })
      return
    }
    commit(latestRows().filter((_, i) => i !== index))
  }

  const confirmRemove = () => {
    if (pendingRemoval === null) return
    commit(latestRows().filter((_, i) => i !== pendingRemoval.index))
    setPendingRemoval(null)
  }

  const updateRowField = (index: number, attribute: string, fieldValue: unknown) => {
    const next = latestRows()
    next[index] = {
      ...next[index],
      fields: { ...next[index].fields, [attribute]: fieldValue },
    }
    commit(next)
    const key = rowKeyOf(next[index], index)
    setRowErrors((prev) => withoutFieldError(prev, key, attribute))
  }

  const toggleCollapse = (rowId: string) => {
    setCollapsed((prev) => ({ ...prev, [rowId]: !prev[rowId] }))
  }

  // Drag & drop (native HTML5 — no external lib required)
  const onDragStart = (index: number) => (e: DragEvent<HTMLDivElement>) => {
    if (!canReorder) return
    setDraggingIndex(index)
    e.dataTransfer.effectAllowed = 'move'
  }

  const onDragOver = (index: number) => (e: DragEvent<HTMLDivElement>) => {
    if (!canReorder || draggingIndex === null) return
    e.preventDefault()
    e.dataTransfer.dropEffect = 'move'
    if (draggingIndex === index) return
  }

  const onDrop = (index: number) => (e: DragEvent<HTMLDivElement>) => {
    if (!canReorder || draggingIndex === null) return
    e.preventDefault()
    const next = latestRows()
    const [moved] = next.splice(draggingIndex, 1)
    next.splice(index, 0, moved)
    commit(next)
    setDraggingIndex(null)
  }

  const onDragEnd = () => setDraggingIndex(null)

  return (
    <div className="flex flex-col gap-3">
      {rows.map((row, index) => {
        const rep = repeatableFor(row.type)
        if (!rep) return null

        const rowKey = rowKeyOf(row, index)
        const isCollapsed = meta.collapsible === true && !!collapsed[rowKey]
        const errorsOfRow = rowErrors[rowKey]
        const isStoredRow = context === 'update' && row.id !== null && storedRowIds.has(String(row.id))
        const rowFields = isStoredRow ? (storedRowFields.get(rep.shortName) ?? rep.fields) : rep.fields

        // The "#N" badge below already renders the row number for a static
        // label, so the text carries it only inside a dynamic title's fallback.
        const titleText = resolveRowTitle(rep, row, index) ?? rep.label

        const accent = tokenColor(rep.color) ?? 'var(--martis-border)'

        return (
          <div
            key={rowKey}
            draggable={canReorder}
            onDragStart={onDragStart(index)}
            onDragOver={onDragOver(index)}
            onDrop={onDrop(index)}
            onDragEnd={onDragEnd}
            className="rounded-lg border border-solid"
            style={{
              borderColor: 'var(--martis-border)',
              backgroundColor: 'var(--martis-surface)',
              borderLeft: `3px solid ${accent}`,
              opacity: draggingIndex === index ? 0.5 : 1,
            }}
          >
            {/* Header */}
            <div
              className="flex items-center gap-2 border-0 border-b border-solid px-3 py-2"
              style={{ borderColor: 'var(--martis-border)', backgroundColor: 'var(--martis-surface-alt)' }}
            >
              {canReorder && (
                <span
                  className="flex cursor-grab items-center active:cursor-grabbing"
                  style={{ color: 'var(--martis-text-muted)' }}
                  data-pr-tooltip={tAct('reorder', 'Reorder')}
                  data-pr-position="top"
                >
                  <DotsSixVerticalIcon size={16} />
                </span>
              )}
              {rep.icon && (
                <span style={{ color: tokenColor(rep.color) ?? 'var(--martis-accent)' }}>
                  <ResourceIcon iconName={rep.icon} size={16} />
                </span>
              )}
              <span className="flex-1 text-sm font-medium" style={{ color: 'var(--martis-text)' }}>
                {titleText}
              </span>
              {rep.badgeCount && !hasDynamicTitle(rep) && (
                <span
                  className="rounded-full px-2 py-0.5 text-xs font-semibold"
                  style={{
                    backgroundColor: 'color-mix(in oklab, var(--martis-accent) 12%, transparent)',
                    color: 'var(--martis-accent)',
                  }}
                >
                  #{index + 1}
                </span>
              )}
              {meta.collapsible && (
                <button
                  type="button"
                  onClick={() => toggleCollapse(rowKey)}
                  className="rounded p-1 hover:bg-[color:var(--martis-hover)]"
                  data-pr-tooltip={isCollapsed ? tAct('expand', 'Expand') : tAct('collapse', 'Collapse')}
                  data-pr-position="top"
                  style={{ color: 'var(--martis-text-muted)' }}
                >
                  {isCollapsed ? <CaretDownIcon size={14} /> : <CaretUpIcon size={14} />}
                </button>
              )}
              {!meta.hideDuplicate && !field.readonly && (
                <button
                  type="button"
                  onClick={() => duplicateRow(index)}
                  disabled={atMax}
                  className="rounded p-1 hover:bg-[color:var(--martis-hover)] disabled:cursor-not-allowed disabled:opacity-50"
                  style={{ color: 'var(--martis-text-muted)' }}
                  data-pr-tooltip={tAct('duplicate_row', 'Duplicate row')}
                  data-pr-position="top"
                >
                  <CopyIcon size={14} />
                </button>
              )}
              {!field.readonly && (
                <button
                  type="button"
                  onClick={() => removeRow(index)}
                  className="rounded p-1 hover:bg-[color:var(--martis-hover)]"
                  style={{ color: 'var(--martis-danger)' }}
                  data-pr-tooltip={tAct('remove', 'Remove')}
                  data-pr-position="top"
                >
                  <TrashIcon size={14} />
                </button>
              )}
            </div>

            {/* Errors of the row itself (a row type the server rejected), shown even when the row is collapsed */}
            {errorsOfRow && errorsOfRow.row.length > 0 && (
              <div className="flex flex-col gap-1 px-4 pt-3">
                {errorsOfRow.row.map((message, messageIndex) => (
                  <small key={messageIndex} style={{ color: 'var(--martis-danger)' }}>{message}</small>
                ))}
              </div>
            )}

            {/* Body */}
            {!isCollapsed && (
              <div className="space-y-3 p-4">
                {rowFields.map((childField, fieldIndex) => {
                  return (
                    <div key={`${childField.attribute}-${fieldIndex}`} className="grid grid-cols-3 gap-4">
                      <div>
                        <label
                          htmlFor={`${field.attribute}-${index}-${childField.attribute}`}
                          className="block text-sm font-medium"
                          style={{ color: 'var(--martis-text-muted)' }}
                        >
                          {childField.label}
                          {childField.required && (
                            <span className="ml-1 text-red-500" aria-hidden="true">*</span>
                          )}
                        </label>
                      </div>
                      <div className="col-span-2">
                        <FieldInput
                          field={childField}
                          value={row.fields[childField.attribute] ?? null}
                          onChange={(v) => updateRowField(index, childField.attribute, v)}
                          error={errorsOfRow?.fields[childField.attribute]}
                          nestedErrors={nestedErrorsOf(errorsOfRow?.fields, childField.attribute)}
                          // The form's scope, plus the row: server-backed row
                          // fields (relation pickers, remote Select search)
                          // name it, and the server reads the field from it.
                          resourceKey={resourceKey}
                          recordId={recordId}
                          context={context}
                          toolKey={toolKey}
                          actionEndpoint={actionEndpoint}
                          pivotEndpoint={pivotEndpoint}
                          repeaterRow={{ repeater: field.attribute, repeatable: rep.shortName }}
                          formValues={{
                            ...formValues,
                            ...row.fields,
                            // Martis-diff: expose the parent's dependsOn attributes to the row.
                            ...(meta.dependsOn ?? []).reduce<Record<string, unknown>>((acc, attr) => {
                              if (formValues && attr in formValues) acc[attr] = formValues[attr]
                              return acc
                            }, {}),
                          }}
                        />
                        {childField.helpText && (
                          <p className="mt-1 text-xs" style={{ color: 'var(--martis-text-muted)' }}>
                            {childField.helpText}
                          </p>
                        )}
                      </div>
                    </div>
                  )
                })}
              </div>
            )}
          </div>
        )
      })}

      {error && <small style={{ color: 'var(--martis-danger)' }}>{error}</small>}

      {/* Footer — Add row + cardinality feedback */}
      <div className="flex items-center justify-between">
        <div className="text-xs" style={{ color: 'var(--martis-text-muted)' }}>
          {meta.minRows != null && belowMin && (
            <span style={{ color: 'var(--martis-danger)' }}>
              {t('repeater_min_rows', { count: meta.minRows, defaultValue: `Minimum ${meta.minRows} row(s).` })}
            </span>
          )}
          {meta.maxRows != null && (
            <span className="ml-2">
              {t('repeater_count', { count: rows.length, max: meta.maxRows, defaultValue: `${rows.length} / ${meta.maxRows}` })}
            </span>
          )}
        </div>
        {!field.readonly && (
          <div className="flex items-center gap-2">
            {!meta.hideBulkPaste && (
              <button
                type="button"
                onClick={() => {
                  setBulkPasteType(repeatables[0]?.shortName ?? '')
                  setBulkPasteText('')
                  setBulkPasteError(null)
                  setBulkPasteOpen(true)
                }}
                disabled={atMax || repeatables.length === 0}
                className="martis-btn-secondary inline-flex items-center gap-1.5"
                data-pr-tooltip={tAct('paste_rows', 'Paste rows (CSV/TSV/JSON)')}
                data-pr-position="top"
              >
                <ClipboardIcon size={14} />
                {tAct('paste_rows', 'Paste rows')}
              </button>
            )}
            <div className="relative" ref={addMenuRef}>
              {isMultiType || hasTemplates ? (
                <>
                  <button
                    type="button"
                    disabled={atMax}
                    onClick={() => setShowAddMenu((s) => !s)}
                    className="martis-btn-secondary inline-flex items-center gap-1.5"
                  >
                    <PlusIcon size={14} />
                    {tAct('add_row', 'Add row')}
                  </button>
                  {showAddMenu && (
                    <div
                      className="absolute right-0 z-10 mt-1 min-w-[220px] overflow-hidden rounded-md border border-solid shadow-lg"
                      style={{ borderColor: 'var(--martis-border)', backgroundColor: 'var(--martis-surface)' }}
                    >
                      {/* The row types come first, so a blank row can be added next to the templates. */}
                      {repeatables.map((rep) => (
                        <button
                          key={rep.shortName}
                          type="button"
                          onClick={() => addRow(rep.shortName)}
                          className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-[color:var(--martis-hover)]"
                          style={{ color: 'var(--martis-text)' }}
                        >
                          {rep.icon && (
                            <span style={{ color: tokenColor(rep.color) ?? 'var(--martis-accent)' }}>
                              <ResourceIcon iconName={rep.icon} size={14} />
                            </span>
                          )}
                          {rep.label}
                        </button>
                      ))}
                      {meta.rowTemplates && meta.rowTemplates.length > 0 && (
                        <>
                          <div
                            className="px-3 py-1 text-[10px] uppercase tracking-wide"
                            style={{ borderTop: '1px solid var(--martis-border)', color: 'var(--martis-text-muted)' }}
                          >
                            {t('repeater_templates', 'Templates')}
                          </div>
                          {meta.rowTemplates.map((tpl, tIdx) => (
                            <button
                              key={`tpl-${tIdx}`}
                              type="button"
                              onClick={() => addFromTemplate(tpl)}
                              className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-[color:var(--martis-hover)]"
                              style={{ color: 'var(--martis-text)' }}
                            >
                              {tpl.icon && (
                                <span style={{ color: tokenColor(tpl.color ?? null) ?? 'var(--martis-accent)' }}>
                                  <ResourceIcon iconName={tpl.icon} size={14} />
                                </span>
                              )}
                              <span>{tpl.label}</span>
                              <span
                                className="ml-auto rounded px-1.5 py-0.5 text-[10px]"
                                style={{
                                  backgroundColor: 'color-mix(in oklab, var(--martis-text-muted) 12%, transparent)',
                                  color: 'var(--martis-text-muted)',
                                }}
                              >
                                {repeatableFor(tpl.type)?.label ?? tpl.type}
                              </span>
                            </button>
                          ))}
                        </>
                      )}
                    </div>
                  )}
                </>
              ) : (
                <button
                  type="button"
                  disabled={atMax}
                  onClick={() => addRow(primaryType)}
                  className="martis-btn-secondary inline-flex items-center gap-1.5"
                >
                  <PlusIcon size={14} />
                  {repeatables[0]?.label
                    ? t('repeater_add_named', { label: repeatables[0].label, defaultValue: `Add ${repeatables[0].label}` })
                    : tAct('add_row', 'Add row')}
                </button>
              )}
            </div>
          </div>
        )}
      </div>

      {/* Bulk-paste modal — parses TSV / CSV / JSON into rows */}
      {bulkPasteOpen && createPortal(
        <div
          className="fixed inset-0 flex items-center justify-center"
          style={{ zIndex: 10000, backgroundColor: 'rgba(0,0,0,0.55)' }}
          onClick={() => setBulkPasteOpen(false)}
        >
          <div
            className="rounded-lg p-5 shadow-2xl"
            style={{
              backgroundColor: 'var(--martis-card)',
              border: '1px solid var(--martis-border)',
              color: 'var(--martis-text)',
              width: '540px',
              maxWidth: '92vw',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            <h2 className="text-base font-semibold">{t('repeater_paste_title', 'Bulk paste rows')}</h2>
            <p className="mt-1 text-sm" style={{ color: 'var(--martis-text-muted)' }}>
              {t('repeater_paste_help', 'Paste TSV/CSV (with or without header) or a JSON array. The first non-empty row is detected as the header automatically when its column names match the field attributes.')}
            </p>

            {isMultiType && (
              <div className="mt-3">
                <label className="block text-xs font-medium" style={{ color: 'var(--martis-text-muted)' }}>
                  {t('repeater_paste_type', 'Row type')}
                </label>
                <select
                  value={bulkPasteType}
                  onChange={(e) => setBulkPasteType(e.target.value)}
                  className="mt-1 w-full rounded-md border border-solid px-3 py-2 text-sm"
                  style={{
                    borderColor: 'var(--martis-border)',
                    backgroundColor: 'var(--martis-surface)',
                    color: 'var(--martis-text)',
                  }}
                >
                  {repeatables.map((rep) => (
                    <option key={rep.shortName} value={rep.shortName}>{rep.label}</option>
                  ))}
                </select>
              </div>
            )}

            <textarea
              value={bulkPasteText}
              onChange={(e) => setBulkPasteText(e.target.value)}
              placeholder={`${repeatables[0]?.fields.slice(0, 3).map((f) => f.attribute).join(',') ?? ''}\nvalor1,valor2,valor3`}
              className="mt-3 h-40 w-full rounded-md border border-solid px-3 py-2 font-mono text-xs"
              style={{
                borderColor: 'var(--martis-border)',
                backgroundColor: 'var(--martis-surface)',
                color: 'var(--martis-text)',
              }}
            />

            {bulkPasteError && (
              <p className="mt-2 text-xs" style={{ color: 'var(--martis-danger)' }}>{bulkPasteError}</p>
            )}

            <div className="mt-4 flex items-center justify-end gap-2">
              <button
                type="button"
                className="martis-btn-secondary"
                onClick={() => setBulkPasteOpen(false)}
              >
                <XIcon size={14} />
                {tAct('cancel', 'Cancel')}
              </button>
              <button
                type="button"
                className="martis-btn-primary"
                onClick={() => {
                  const targetType = isMultiType ? bulkPasteType : primaryType
                  const rep = repeatableFor(targetType)
                  if (!rep) {
                    setBulkPasteError(t('repeater_paste_unknown_type', 'Unknown row type.') as string)
                    return
                  }
                  const parsed = parseBulkRows(bulkPasteText, rep)
                  if (parsed instanceof Error) {
                    setBulkPasteError(parsed.message)
                    return
                  }
                  if (parsed.length === 0) {
                    setBulkPasteError(t('repeater_paste_empty', 'Nothing detected to import.') as string)
                    return
                  }
                  const current = latestRows()
                  const remaining = meta.maxRows != null ? meta.maxRows - current.length : Infinity
                  const slice = parsed.slice(0, remaining)
                  const toInsert: RepeaterRow[] = slice.map((fields) => ({
                    id: randomId(),
                    type: rep.shortName,
                    fields: newRowFields(rep, fields),
                  }))
                  commit([...current, ...toInsert])
                  setBulkPasteOpen(false)
                }}
              >
                <ClipboardIcon size={14} />
                {t('repeater_paste_submit', 'Import')}
              </button>
            </div>
          </div>
        </div>,
        document.body,
      )}

      {/* Confirm removal modal */}
      {pendingRemoval && createPortal(
        <div
          className="fixed inset-0 flex items-center justify-center"
          style={{ zIndex: 10000, backgroundColor: 'rgba(0,0,0,0.55)' }}
          onClick={() => setPendingRemoval(null)}
        >
          <div
            className="rounded-lg p-5 shadow-2xl"
            style={{
              backgroundColor: 'var(--martis-card)',
              border: '1px solid var(--martis-border)',
              color: 'var(--martis-text)',
              width: '400px',
              maxWidth: '92vw',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            <h2 className="text-base font-semibold">{t('repeater_confirm_title', 'Remove row?')}</h2>
            <p className="mt-1 text-sm" style={{ color: 'var(--martis-text-muted)' }}>
              {t('repeater_confirm_body', { label: pendingRemoval.label, defaultValue: `Remove ${pendingRemoval.label}?` })}
            </p>
            <div className="mt-5 flex items-center justify-end gap-2">
              <button type="button" className="martis-btn-secondary" onClick={() => setPendingRemoval(null)}>
                <XIcon size={14} />
                {tAct('cancel', 'Cancel')}
              </button>
              <button type="button" className="martis-btn-danger" onClick={confirmRemove}>
                <TrashIcon size={14} />
                {tAct('remove', 'Remove')}
              </button>
            </div>
          </div>
        </div>,
        document.body,
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Parse a pasted block of text into repeater rows. Tries JSON first (an
 * array of objects), then tab-separated, then comma-separated. If the
 * first row looks like a header (all cells match field attributes), the
 * remaining rows are mapped by name; otherwise we pair cells positionally
 * with the Repeatable's fields.
 *
 * Returns an Error on parse failure so the caller can surface the message
 * without throwing.
 */
function parseBulkRows(text: string, rep: RepeatableDef): Array<Record<string, unknown>> | Error {
  const trimmed = text.trim()
  if (!trimmed) return []

  // JSON array path — forgiving about extra wrapping whitespace.
  if (trimmed.startsWith('[') || trimmed.startsWith('{')) {
    try {
      const parsed = JSON.parse(trimmed)
      const asArray = Array.isArray(parsed) ? parsed : [parsed]
      const attributes = new Set(rep.fields.map((f) => f.attribute))
      return asArray
        .filter((item): item is Record<string, unknown> => item != null && typeof item === 'object' && !Array.isArray(item))
        .map((item) => {
          const row: Record<string, unknown> = {}
          Object.entries(item).forEach(([k, v]) => { if (attributes.has(k)) row[k] = v })
          return row
        })
    } catch (e) {
      return new Error(`Invalid JSON: ${(e as Error).message}`)
    }
  }

  // Delimiter detection — tab wins if present on the first non-empty line,
  // otherwise comma. Semicolon as fallback for pt-locale spreadsheets.
  const lines = trimmed.split(/\r?\n/).map((l) => l.trim()).filter((l) => l.length > 0)
  if (lines.length === 0) return []
  const firstLine = lines[0]
  const delim = firstLine.includes('\t') ? '\t' : firstLine.includes(';') ? ';' : ','

  const splitRow = (line: string): string[] => line.split(delim).map((c) => c.trim())

  const attributeList = rep.fields.map((f) => f.attribute)
  const firstCells = splitRow(lines[0])
  const headerIsMap = firstCells.every((cell) => attributeList.includes(cell))

  const dataLines = headerIsMap ? lines.slice(1) : lines
  const headerOrder = headerIsMap ? firstCells : attributeList.slice(0, firstCells.length)

  return dataLines.map((line) => {
    const cells = splitRow(line)
    const row: Record<string, unknown> = {}
    headerOrder.forEach((attr, i) => {
      if (cells[i] !== undefined && cells[i] !== '') row[attr] = cells[i]
    })
    return row
  })
}

function tokenColor(color?: string | null): string | undefined {
  if (!color) return undefined
  const key = color.trim().toLowerCase()
  const map: Record<string, string> = {
    success: 'var(--martis-success)',
    warning: 'var(--martis-warning)',
    danger: 'var(--martis-danger)',
    info: 'var(--martis-info)',
    muted: 'var(--martis-text-muted)',
    accent: 'var(--martis-accent)',
    primary: 'var(--martis-accent)',
  }
  if (map[key]) return map[key]
  return color
}
