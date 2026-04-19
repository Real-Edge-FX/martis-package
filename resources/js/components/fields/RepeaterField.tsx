import { useCallback, useEffect, useMemo, useRef, useState, type DragEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { PlusIcon, TrashIcon, CaretUpIcon, CaretDownIcon, DotsSixVerticalIcon, XIcon, CopyIcon, ClipboardIcon } from '@phosphor-icons/react'
import { createPortal } from 'react-dom'
import { FieldInput } from './FieldRenderer'
import { ResourceIcon } from '@/components/ResourceIcon'
import type { FieldDefinition } from '@/types'
import type { FieldDisplayProps, FieldInputProps } from './types'

/**
 * Row payload shape used in the React layer — mirrors the PHP payload:
 *   { id, type, fields: { attr -> value } }
 */
interface RepeaterRow {
  id: string | number | null
  type: string
  fields: Record<string, unknown>
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
      }
    })
    .filter((r): r is RepeaterRow => r !== null)
}

/** Apply a `{attr}` template against the row's field values. */
function applyTitleTemplate(template: string, rowFields: Record<string, unknown>): string {
  return template.replace(/\{([a-zA-Z0-9_]+)\}/g, (_m, key) => {
    const val = rowFields[key]
    if (val === null || val === undefined) return ''
    return String(val)
  }).trim()
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
        const title = rep?.titleTemplate ? applyTitleTemplate(rep.titleTemplate, row.fields) : null
        return (
          <div
            key={String(row.id ?? index)}
            className="rounded-md border px-3 py-2 text-sm"
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
              {rep?.badgeCount && (
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

export function RepeaterFieldInput({ field, value, onChange, error, resourceKey, recordId, formValues }: FieldInputProps) {
  const { t } = useTranslation('messages')
  const { t: tAct } = useTranslation('actions')

  const meta = field as unknown as RepeaterMeta
  const repeatables = meta.repeatables ?? []
  const isMultiType = repeatables.length > 1
  const primaryType = repeatables[0]?.shortName ?? ''

  const rows = useMemo(() => normalizeRows(value), [value])

  const [collapsed, setCollapsed] = useState<Record<string, boolean>>(() => {
    if (!meta.collapsible || !meta.collapsedByDefault) return {}
    const map: Record<string, boolean> = {}
    rows.forEach((r) => {
      if (r.id !== null) map[String(r.id)] = true
    })
    return map
  })

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
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') setShowAddMenu(false)
    }
    document.addEventListener('mousedown', onPointer)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onPointer)
      document.removeEventListener('keydown', onKey)
    }
  }, [showAddMenu])

  const atMax = meta.maxRows != null && rows.length >= meta.maxRows
  const belowMin = meta.minRows != null && rows.length < meta.minRows

  const repeatableFor = useCallback((type: string): RepeatableDef | undefined => {
    return repeatables.find((r) => r.shortName === type) ?? repeatables[0]
  }, [repeatables])

  const commit = (next: RepeaterRow[]) => onChange(next)

  const addRow = (type: string, seedFields?: Record<string, unknown>) => {
    const rep = repeatableFor(type)
    if (!rep) return
    const blankFields: Record<string, unknown> = {}
    rep.fields.forEach((f) => { blankFields[f.attribute] = f.defaultValue ?? null })
    const row: RepeaterRow = {
      id: randomId(),
      type: rep.shortName,
      fields: { ...blankFields, ...(seedFields ?? {}) },
    }
    commit([...rows, row])
    setShowAddMenu(false)
  }

  const addFromTemplate = (tpl: RepeaterTemplate) => {
    addRow(tpl.type, tpl.fields)
  }

  const duplicateRow = (index: number) => {
    const source = rows[index]
    if (!source) return
    const copy: RepeaterRow = {
      id: randomId(),
      type: source.type,
      fields: { ...source.fields },
    }
    const next = rows.slice()
    next.splice(index + 1, 0, copy)
    commit(next)
  }

  const removeRow = (index: number) => {
    const confirmIt = meta.confirmRemoval === true
    if (confirmIt) {
      const rep = repeatableFor(rows[index]?.type ?? '')
      const title = rep?.titleTemplate
        ? applyTitleTemplate(rep.titleTemplate, rows[index]?.fields ?? {}) || rep.label
        : rep?.label ?? `#${index + 1}`
      setPendingRemoval({ index, label: title })
      return
    }
    commit(rows.filter((_, i) => i !== index))
  }

  const confirmRemove = () => {
    if (pendingRemoval === null) return
    commit(rows.filter((_, i) => i !== pendingRemoval.index))
    setPendingRemoval(null)
  }

  const updateRowField = (index: number, attribute: string, fieldValue: unknown) => {
    const next = rows.slice()
    next[index] = {
      ...next[index],
      fields: { ...next[index].fields, [attribute]: fieldValue },
    }
    commit(next)
  }

  const toggleCollapse = (rowId: string) => {
    setCollapsed((prev) => ({ ...prev, [rowId]: !prev[rowId] }))
  }

  // Drag & drop (native HTML5 — no external lib required)
  const onDragStart = (index: number) => (e: DragEvent<HTMLDivElement>) => {
    if (!meta.reorderable) return
    setDraggingIndex(index)
    e.dataTransfer.effectAllowed = 'move'
  }

  const onDragOver = (index: number) => (e: DragEvent<HTMLDivElement>) => {
    if (!meta.reorderable || draggingIndex === null) return
    e.preventDefault()
    e.dataTransfer.dropEffect = 'move'
    if (draggingIndex === index) return
  }

  const onDrop = (index: number) => (e: DragEvent<HTMLDivElement>) => {
    if (!meta.reorderable || draggingIndex === null) return
    e.preventDefault()
    const next = rows.slice()
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

        const rowKey = String(row.id ?? index)
        const isCollapsed = meta.collapsible === true && !!collapsed[rowKey]

        const titleText = rep.titleTemplate
          ? applyTitleTemplate(rep.titleTemplate, row.fields) || `${rep.label} #${index + 1}`
          : `${rep.label}${rep.badgeCount ? ` #${index + 1}` : ''}`

        const accent = tokenColor(rep.color) ?? 'var(--martis-border)'

        return (
          <div
            key={rowKey}
            draggable={meta.reorderable === true}
            onDragStart={onDragStart(index)}
            onDragOver={onDragOver(index)}
            onDrop={onDrop(index)}
            onDragEnd={onDragEnd}
            className="rounded-lg border"
            style={{
              borderColor: 'var(--martis-border)',
              backgroundColor: 'var(--martis-surface)',
              borderLeft: `3px solid ${accent}`,
              opacity: draggingIndex === index ? 0.5 : 1,
            }}
          >
            {/* Header */}
            <div
              className="flex items-center gap-2 border-b px-3 py-2"
              style={{ borderColor: 'var(--martis-border)', backgroundColor: 'var(--martis-surface-alt)' }}
            >
              {meta.reorderable && (
                <span
                  className="flex cursor-grab items-center active:cursor-grabbing"
                  style={{ color: 'var(--martis-text-muted)' }}
                  title={tAct('reorder', 'Reorder')}
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
              {rep.badgeCount && !rep.titleTemplate && (
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
                  title={isCollapsed ? tAct('expand', 'Expand') : tAct('collapse', 'Collapse')}
                  style={{ color: 'var(--martis-text-muted)' }}
                >
                  {isCollapsed ? <CaretDownIcon size={14} /> : <CaretUpIcon size={14} />}
                </button>
              )}
              {!meta.hideDuplicate && (
                <button
                  type="button"
                  onClick={() => duplicateRow(index)}
                  disabled={atMax}
                  className="rounded p-1 hover:bg-[color:var(--martis-hover)] disabled:cursor-not-allowed disabled:opacity-50"
                  style={{ color: 'var(--martis-text-muted)' }}
                  title={tAct('duplicate_row', 'Duplicate row')}
                >
                  <CopyIcon size={14} />
                </button>
              )}
              <button
                type="button"
                onClick={() => removeRow(index)}
                className="rounded p-1 hover:bg-[color:var(--martis-hover)]"
                style={{ color: 'var(--martis-danger)' }}
                title={tAct('remove', 'Remove')}
              >
                <TrashIcon size={14} />
              </button>
            </div>

            {/* Body */}
            {!isCollapsed && (
              <div className="space-y-3 p-4">
                {rep.fields.map((childField, fieldIndex) => {
                  const rowError = error && typeof error === 'object'
                    ? ((error as unknown as Record<string, Record<string, string>>)[String(index)]?.[childField.attribute])
                    : undefined
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
                          error={rowError}
                          resourceKey={resourceKey}
                          recordId={recordId}
                          context="update"
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

      {/* Footer — Add row + cardinality feedback */}
      <div className="flex items-center justify-between">
        <div className="text-xs" style={{ color: 'var(--martis-text-muted)' }}>
          {meta.minRows != null && belowMin && (
            <span style={{ color: 'var(--martis-danger)' }}>
              {t('repeater_min_rows', { count: meta.minRows, defaultValue: `Mínimo ${meta.minRows} linha(s).` })}
            </span>
          )}
          {meta.maxRows != null && (
            <span className="ml-2">
              {t('repeater_count', { count: rows.length, max: meta.maxRows, defaultValue: `${rows.length} / ${meta.maxRows}` })}
            </span>
          )}
        </div>
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
              title={tAct('paste_rows', 'Paste rows (CSV/TSV/JSON)')}
            >
              <ClipboardIcon size={14} />
              {tAct('paste_rows', 'Colar linhas')}
            </button>
          )}
          <div className="relative" ref={addMenuRef}>
            {isMultiType || (meta.rowTemplates && meta.rowTemplates.length > 0) ? (
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
                    className="absolute right-0 z-10 mt-1 min-w-[220px] overflow-hidden rounded-md border shadow-lg"
                    style={{ borderColor: 'var(--martis-border)', backgroundColor: 'var(--martis-surface)' }}
                  >
                    {isMultiType && repeatables.map((rep) => (
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
                        {isMultiType && (
                          <div
                            className="px-3 py-1 text-[10px] uppercase tracking-wide"
                            style={{ borderTop: '1px solid var(--martis-border)', color: 'var(--martis-text-muted)' }}
                          >
                            {t('repeater_templates', 'Templates')}
                          </div>
                        )}
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
                  ? t('repeater_add_named', { label: repeatables[0].label, defaultValue: `Adicionar ${repeatables[0].label}` })
                  : tAct('add_row', 'Add row')}
              </button>
            )}
          </div>
        </div>
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
            <h2 className="text-base font-semibold">{t('repeater_paste_title', 'Colar linhas em lote')}</h2>
            <p className="mt-1 text-sm" style={{ color: 'var(--martis-text-muted)' }}>
              {t('repeater_paste_help', 'Colar TSV/CSV (com ou sem cabeçalho) ou JSON array. A primeira linha não-vazia é detetada automaticamente como cabeçalho se os nomes baterem com os campos.')}
            </p>

            {isMultiType && (
              <div className="mt-3">
                <label className="block text-xs font-medium" style={{ color: 'var(--martis-text-muted)' }}>
                  {t('repeater_paste_type', 'Tipo de linha')}
                </label>
                <select
                  value={bulkPasteType}
                  onChange={(e) => setBulkPasteType(e.target.value)}
                  className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
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
              className="mt-3 h-40 w-full rounded-md border px-3 py-2 font-mono text-xs"
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
                {tAct('cancel', 'Cancelar')}
              </button>
              <button
                type="button"
                className="martis-btn-primary"
                onClick={() => {
                  const targetType = isMultiType ? bulkPasteType : primaryType
                  const rep = repeatableFor(targetType)
                  if (!rep) {
                    setBulkPasteError(t('repeater_paste_unknown_type', 'Tipo desconhecido.') as string)
                    return
                  }
                  const parsed = parseBulkRows(bulkPasteText, rep)
                  if (parsed instanceof Error) {
                    setBulkPasteError(parsed.message)
                    return
                  }
                  if (parsed.length === 0) {
                    setBulkPasteError(t('repeater_paste_empty', 'Nada detetado para importar.') as string)
                    return
                  }
                  const remaining = meta.maxRows != null ? meta.maxRows - rows.length : Infinity
                  const slice = parsed.slice(0, remaining)
                  const blankFields: Record<string, unknown> = {}
                  rep.fields.forEach((f) => { blankFields[f.attribute] = f.defaultValue ?? null })
                  const toInsert: RepeaterRow[] = slice.map((fields) => ({
                    id: randomId(),
                    type: rep.shortName,
                    fields: { ...blankFields, ...fields },
                  }))
                  commit([...rows, ...toInsert])
                  setBulkPasteOpen(false)
                }}
              >
                <ClipboardIcon size={14} />
                {t('repeater_paste_submit', 'Importar')}
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
            <h2 className="text-base font-semibold">{t('repeater_confirm_title', 'Remover linha?')}</h2>
            <p className="mt-1 text-sm" style={{ color: 'var(--martis-text-muted)' }}>
              {t('repeater_confirm_body', { label: pendingRemoval.label, defaultValue: `Remover ${pendingRemoval.label}?` })}
            </p>
            <div className="mt-5 flex items-center justify-end gap-2">
              <button type="button" className="martis-btn-secondary" onClick={() => setPendingRemoval(null)}>
                <XIcon size={14} />
                {tAct('cancel', 'Cancelar')}
              </button>
              <button type="button" className="martis-btn-danger" onClick={confirmRemove}>
                <TrashIcon size={14} />
                {tAct('remove', 'Remover')}
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
      return new Error(`JSON inválido: ${(e as Error).message}`)
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
