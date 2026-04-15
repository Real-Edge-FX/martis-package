import { useCallback } from "react"
import { registry } from "@/lib/registry"
import type { FieldDefinition, ResourceRecord } from "@/types"
import type { ActionMeta } from "@/components/Actions"
import { FieldDisplay } from "@/components/fields/FieldRenderer"
import { DataTable, type DataTableSelectionMultipleChangeEvent, type DataTableSortEvent } from "primereact/datatable"
import { Column } from "primereact/column"
import { CaretUpIcon, CaretDownIcon, CaretUpDownIcon, LightningIcon, WarningIcon, DotsThreeVerticalIcon, CaretRightIcon } from "@phosphor-icons/react"
import { ResourceIcon } from "@/components/ResourceIcon"
import { useTranslation } from "react-i18next"
import { useState, useRef, useEffect } from "react"
import { createPortal } from "react-dom"

export interface TableColumn {
  field: FieldDefinition
}

export interface TableProps {
  columns: TableColumn[]
  rows: ResourceRecord[]
  sortBy: string | null
  sortDir: "asc" | "desc"
  onSort: (attribute: string) => void
  selectedIds: Set<string | number>
  onToggleSelect: (id: string | number) => void
  onToggleAll: () => void
  onSetSelection?: (ids: Set<string | number>) => void
  onClickRow?: (row: ResourceRecord) => void
  resourceKey?: string
  selectable?: boolean
  inlineActions?: ActionMeta[]
  onInlineAction?: (action: ActionMeta, row: ResourceRecord) => void
  tableConfig?: {
    striped?: boolean
    showGridlines?: boolean
    size?: "normal" | "small" | "large"
    rowHover?: boolean
  }
}

function SortIcon({ active, dir }: { active: boolean; dir: "asc" | "desc" }) {
  if (!active) return <CaretUpDownIcon size={14} className="text-gray-400" />
  return dir === "asc"
    ? <CaretUpIcon size={14} className="text-indigo-600" />
    : <CaretDownIcon size={14} className="text-indigo-600" />
}

/* ── Inline Action Menu (3-dot grouped) with submenu support ──────── */

interface InlineGroupNode {
  label: string
  children: Array<ActionMeta | InlineGroupNode>
}

function isInlineGroup(item: ActionMeta | InlineGroupNode): item is InlineGroupNode {
  return "label" in item && "children" in item && !("uriKey" in item)
}

function buildInlineGroupTree(actions: ActionMeta[]): Array<ActionMeta | InlineGroupNode> {
  const ungrouped: ActionMeta[] = []
  const groupMap = new Map<string, ActionMeta[]>()

  for (const action of actions) {
    if (!action.group) {
      ungrouped.push(action)
    } else {
      const existing = groupMap.get(action.group) ?? []
      existing.push(action)
      groupMap.set(action.group, existing)
    }
  }

  const result: Array<ActionMeta | InlineGroupNode> = [...ungrouped]
  const topGroups = new Map<string, InlineGroupNode>()

  for (const [key, items] of groupMap.entries()) {
    const parts = key.split(".")
    if (parts.length === 1) {
      if (!topGroups.has(parts[0])) {
        topGroups.set(parts[0], { label: parts[0], children: [] })
      }
      topGroups.get(parts[0])!.children.push(...items)
    } else {
      const topKey = parts[0]
      const subLabel = parts.slice(1).join(".")
      if (!topGroups.has(topKey)) {
        topGroups.set(topKey, { label: topKey, children: [] })
      }
      const top = topGroups.get(topKey)!
      let sub = top.children.find(
        (c): c is InlineGroupNode => isInlineGroup(c) && c.label === subLabel,
      )
      if (!sub) {
        sub = { label: subLabel, children: [] }
        top.children.push(sub)
      }
      sub.children.push(...items)
    }
  }

  for (const group of topGroups.values()) {
    result.push(group)
  }
  return result
}

function InlineSubMenu({
  group,
  parentRect,
  onAction,
  row,
  canRunAction,
  canRunDestructive,
}: {
  group: InlineGroupNode
  parentRect: DOMRect | null
  onAction: (action: ActionMeta, row: ResourceRecord) => void
  row: ResourceRecord
  canRunAction: boolean
  canRunDestructive: boolean
}) {
  const [openChild, setOpenChild] = useState<string | null>(null)
  const [childRects, setChildRects] = useState<Map<string, DOMRect>>(new Map())
  const closeTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  function clearCloseTimer() {
    if (closeTimer.current) { clearTimeout(closeTimer.current); closeTimer.current = null }
  }
  function startCloseTimer(key: string) {
    closeTimer.current = setTimeout(() => { if (openChild === key) setOpenChild(null) }, 180)
  }

  if (!parentRect) return null

  const menuWidth = 220
  const vw = window.innerWidth
  const vh = window.innerHeight
  let left = parentRect.right + 2
  let top = parentRect.top
  if (left + menuWidth > vw) left = parentRect.left - menuWidth - 2
  if (left < 0) { left = Math.max(4, vw - menuWidth - 4); top = parentRect.bottom + 4 }
  if (top + 200 > vh) top = Math.max(4, vh - 200)

  return createPortal(
    <div
      data-action-submenu="true"
      className="rounded-lg border shadow-lg py-1"
      style={{ position: "fixed", top, left, minWidth: 200, maxWidth: "calc(100vw - 16px)", zIndex: 9992, backgroundColor: "var(--martis-card)", borderColor: "var(--martis-border)" }}
      onMouseEnter={clearCloseTimer}
    >
      {group.children.map((child, idx) => {
        if (isInlineGroup(child)) {
          const key = `isub-${child.label}-${idx}`
          return (
            <div
              key={key}
              className="relative"
              onMouseEnter={(e) => { clearCloseTimer(); const rect = (e.currentTarget as HTMLElement).getBoundingClientRect(); setOpenChild(key); setChildRects(prev => new Map(prev).set(key, rect)) }}
              onMouseLeave={() => startCloseTimer(key)}
              onClick={(e) => { e.stopPropagation(); const rect = (e.currentTarget as HTMLElement).getBoundingClientRect(); setChildRects(prev => new Map(prev).set(key, rect)); setOpenChild(p => p === key ? null : key) }}
            >
              <div className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition-colors cursor-pointer" style={{ color: "var(--martis-text)" }}
                onMouseEnter={e => (e.currentTarget.style.backgroundColor = "var(--martis-hover)")}
                onMouseLeave={e => (e.currentTarget.style.backgroundColor = "transparent")}
              >
                <span className="font-medium">{child.label}</span>
                <CaretRightIcon size={12} />
              </div>
              {openChild === key && <InlineSubMenu group={child} parentRect={childRects.get(key) ?? null} onAction={onAction} row={row} canRunAction={canRunAction} canRunDestructive={canRunDestructive} />}
            </div>
          )
        }
        const childPerAction = (row as Record<string, unknown>)._actionAuthorization as Record<string, boolean> | undefined
        const isDisabled = childPerAction && child.uriKey in childPerAction ? !childPerAction[child.uriKey] : (child.destructive ? !canRunDestructive : !canRunAction)
        return (
          <button key={child.uriKey} type="button" disabled={isDisabled}
            onClick={e => { e.stopPropagation(); if (!isDisabled) onAction(child, row) }}
            className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
            style={{ color: isDisabled ? "var(--martis-text-muted)" : child.destructive ? "#dc2626" : "var(--martis-text)" }}
            onMouseEnter={e => { if (!isDisabled) e.currentTarget.style.backgroundColor = "var(--martis-hover)" }}
            onMouseLeave={e => { e.currentTarget.style.backgroundColor = "transparent" }}
          >
            {child.showIcon !== false && (child.icon ? <ResourceIcon iconName={child.icon} size={16} color={child.iconColor ?? undefined} /> : child.destructive ? <WarningIcon size={16} weight="fill" color={child.iconColor ?? undefined} /> : <LightningIcon size={16} color={child.iconColor ?? undefined} />)}
            <span>{child.name}</span>
          </button>
        )
      })}
    </div>,
    document.body,
  )
}

function InlineActionMenu({
  actions,
  row,
  onAction,
}: {
  actions: ActionMeta[]
  row: ResourceRecord
  onAction: (action: ActionMeta, row: ResourceRecord) => void
}) {
  const [open, setOpen] = useState(false)
  const btnRef = useRef<HTMLButtonElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const [openGroup, setOpenGroup] = useState<string | null>(null)
  const [groupRects, setGroupRects] = useState<Map<string, DOMRect>>(new Map())
  const closeTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  function clearCloseTimer() {
    if (closeTimer.current) { clearTimeout(closeTimer.current); closeTimer.current = null }
  }

  useEffect(() => {
    if (!open) return
    function handleClick(e: MouseEvent) {
      const target = e.target as HTMLElement
      if (menuRef.current?.contains(target)) return
      if (btnRef.current?.contains(target)) return
      if (target.closest("[data-action-submenu]")) return
      setOpen(false)
    }
    function handleKey(e: KeyboardEvent) { if (e.key === "Escape") setOpen(false) }
    document.addEventListener("mousedown", handleClick)
    document.addEventListener("keydown", handleKey)
    return () => { document.removeEventListener("mousedown", handleClick); document.removeEventListener("keydown", handleKey) }
  }, [open])

  const rect = btnRef.current?.getBoundingClientRect()
  // Per-action canRun helper for inline grouped actions
  const perAction = (row as Record<string, unknown>)._actionAuthorization as Record<string, boolean> | undefined
  const canRunAction = row._authorization?.authorizedToRunAction !== false
  const canRunDestructive = row._authorization?.authorizedToRunDestructiveAction !== false
  const tree = buildInlineGroupTree(actions)

  return (
    <>
      <button
        ref={btnRef}
        type="button"
        onClick={e => { e.stopPropagation(); setOpen(!open) }}
        className="martis-action-btn inline-flex items-center justify-center rounded p-1.5 transition-colors hover:opacity-80"
        style={{ color: "var(--martis-text-muted)", backgroundColor: open ? "var(--martis-hover)" : "transparent" }}
        data-pr-tooltip="Actions"
        data-pr-position="top"
      >
        <DotsThreeVerticalIcon size={18} weight="bold" />
      </button>
      {open && rect && createPortal(
        <div
          ref={menuRef}
          className="rounded-lg border shadow-lg py-1"
          style={{ position: "fixed", top: rect.bottom + 4, left: Math.min(rect.left, window.innerWidth - 220), minWidth: 180, zIndex: 9991, backgroundColor: "var(--martis-card)", borderColor: "var(--martis-border)" }}
        >
          {tree.map((item, idx) => {
            if (isInlineGroup(item)) {
              const key = `igrp-${item.label}-${idx}`
              return (
                <div
                  key={key}
                  data-action-submenu="true"
                  className="relative"
                  onMouseEnter={e => { clearCloseTimer(); const rect = (e.currentTarget as HTMLElement).getBoundingClientRect(); setOpenGroup(key); setGroupRects(prev => new Map(prev).set(key, rect)) }}
                  onMouseLeave={() => { closeTimer.current = setTimeout(() => setOpenGroup(prev => prev === key ? null : prev), 180) }}
                  onClick={e => { e.stopPropagation(); const rect = (e.currentTarget as HTMLElement).getBoundingClientRect(); setGroupRects(prev => new Map(prev).set(key, rect)); setOpenGroup(p => p === key ? null : key) }}
                >
                  <div className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition-colors cursor-pointer" style={{ color: "var(--martis-text)" }}
                    onMouseEnter={e => (e.currentTarget.style.backgroundColor = "var(--martis-hover)")}
                    onMouseLeave={e => (e.currentTarget.style.backgroundColor = "transparent")}
                  >
                    <span className="font-medium">{item.label}</span>
                    <CaretRightIcon size={12} />
                  </div>
                  {openGroup === key && <InlineSubMenu group={item} parentRect={groupRects.get(key) ?? null} onAction={(a, r) => { setOpen(false); onAction(a, r) }} row={row} canRunAction={canRunAction} canRunDestructive={canRunDestructive} />}
                </div>
              )
            }
            const isItemDisabled = perAction && item.uriKey in perAction ? !perAction[item.uriKey] : (item.destructive ? !canRunDestructive : !canRunAction)
            return (
              <button key={item.uriKey} type="button" disabled={isItemDisabled}
                onClick={e => { e.stopPropagation(); if (!isItemDisabled) { setOpen(false); onAction(item, row) } }}
                className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                style={{ color: isItemDisabled ? "var(--martis-text-muted)" : item.destructive ? "#dc2626" : "var(--martis-text)" }}
                onMouseEnter={e => { if (!isItemDisabled) e.currentTarget.style.backgroundColor = "var(--martis-hover)" }}
                onMouseLeave={e => { e.currentTarget.style.backgroundColor = "transparent" }}
              >
                {item.showIcon !== false && (item.icon ? <ResourceIcon iconName={item.icon} size={16} color={item.iconColor ?? undefined} /> : item.destructive ? <WarningIcon size={16} weight="fill" color={item.iconColor ?? undefined} /> : <LightningIcon size={16} color={item.iconColor ?? undefined} />)}
                <span>{item.name}</span>
              </button>
            )
          })}
        </div>,
        document.body,
      )}
    </>
  )
}

/* ── Main Table Component ──────────────────────────────────────────── */

function DefaultTable({
  columns,
  rows,
  sortBy,
  sortDir,
  onSort,
  selectedIds,
  onToggleSelect,
  onToggleAll: _onToggleAll,
  onSetSelection,
  onClickRow,
  resourceKey,
  selectable = false,
  inlineActions = [],
  onInlineAction,
  tableConfig,
}: TableProps) {
  const { t } = useTranslation("resources")
  const { t: tMsg } = useTranslation("messages")

  const sortOrder = sortDir === "asc" ? 1 : -1

  const striped = tableConfig?.striped !== false
  const gridlines = tableConfig?.showGridlines === true
  const size = tableConfig?.size ?? "normal"
  const rowHover = tableConfig?.rowHover !== false

  const classNames = [
    "w-full",
    "martis-datatable",
    striped && "martis-datatable-striped",
    gridlines && "martis-datatable-gridlines",
    size === "small" && "martis-datatable-sm",
    size === "large" && "martis-datatable-lg",
    !rowHover && "martis-datatable-no-hover",
  ].filter(Boolean).join(" ")

  // Convert selectedIds Set to array of row objects for PrimeReact native selection
  const selectedRows = rows.filter(r => selectedIds.has(r.id))

  function handleSelectionChange(e: DataTableSelectionMultipleChangeEvent<ResourceRecord[]>) {
    const newSelection = e.value as ResourceRecord[]
    const newIds = new Set(newSelection.map(r => r.id))
    // Direct set — avoids stale-closure issues with toggle-based approach
    if (onSetSelection) {
      onSetSelection(newIds)
    } else {
      // Fallback: compute diff
      const currentIds = new Set(selectedIds)
      for (const id of newIds) {
        if (!currentIds.has(id)) onToggleSelect(id)
      }
      for (const id of currentIds) {
        if (!newIds.has(id)) onToggleSelect(id)
      }
    }
  }

  const ungroupedInline = inlineActions.filter(a => !a.group)
  const groupedInline = inlineActions.filter(a => !!a.group)
  const hasGroupedInline = groupedInline.length > 0

  const canRunForRow = useCallback(
    (row: ResourceRecord, action: ActionMeta): boolean => {
      // Per-action canRun from backend
      const perAction = (row as Record<string, unknown>)._actionAuthorization as Record<string, boolean> | undefined
      if (perAction && action.uriKey in perAction) {
        return perAction[action.uriKey]
      }
      // Fallback to resource-level authorization
      if (action.destructive) return row._authorization?.authorizedToRunDestructiveAction !== false
      return row._authorization?.authorizedToRunAction !== false
    },
    [],
  )

  return (
    <>
      <DataTable
        key={resourceKey}
        value={rows}
        dataKey="id"
        removableSort
        sortField={sortBy ?? undefined}
        sortOrder={sortOrder}
        onSort={(e: DataTableSortEvent) => {
          if (e.sortField) onSort(String(e.sortField))
        }}
        onRowClick={e => onClickRow?.(e.data as ResourceRecord)}
        rowClassName={(row: ResourceRecord) => {
          const classes: string[] = []
          if (row._authorization?.authorizedToView === false) classes.push("cursor-default opacity-70")
          if ("deleted_at" in row && row["deleted_at"] !== null) classes.push("opacity-60")
          if (selectable && selectedIds.has(row.id)) classes.push("martis-row-selected")
          return classes.join(" ")
        }}
        emptyMessage={
          <div className="py-8 text-center text-sm text-gray-400">
            {t("no_records")}
          </div>
        }
        className={classNames}
        tableClassName="min-w-full"
        selection={selectable ? selectedRows : []}
        onSelectionChange={selectable ? handleSelectionChange : undefined as never}
        selectionMode={selectable ? "checkbox" : null}
      >
        {selectable && (
          <Column
            selectionMode="multiple"
            headerStyle={{ width: "3rem" }}
            headerClassName="martis-select-column"
            bodyClassName="martis-select-column"
          />
        )}
        {rows.some(r => "deleted_at" in r && r["deleted_at"] !== null) && (
          <Column
            header=""
            body={(row: ResourceRecord) =>
              "deleted_at" in row && row["deleted_at"] !== null ? (
                <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400">
                  {tMsg("archived")}
                </span>
              ) : null
            }
            style={{ width: "5rem" }}
          />
        )}
        {columns.map(({ field }) => (
          <Column
            key={field.attribute}
            field={field.attribute}
            header={
              field.sortable ? (
                <button type="button"
                  className="flex items-center gap-1 font-bold uppercase tracking-wider text-xs"
                  style={{ color: "var(--martis-table-header-text)" }}
                  onClick={() => onSort(field.attribute)}
                >
                  {field.label}
                  <SortIcon active={sortBy === field.attribute} dir={sortDir} />
                </button>
              ) : (
                <span className="text-xs font-bold uppercase tracking-wider" style={{ color: "var(--martis-table-header-text)" }}>
                  {field.label}
                </span>
              )
            }
            body={(row: ResourceRecord) => (
              <FieldDisplay field={field} value={row[field.attribute]} resourceKey={resourceKey} context="index" />
            )}
            sortable={false}
          />
        ))}
        {inlineActions.length > 0 && (
          <Column
            header=""
            body={(row: ResourceRecord) => (
              <div className="flex items-center justify-end gap-1" onClick={e => e.stopPropagation()}>
                {ungroupedInline.map(action => {
                  const isDisabled = !canRunForRow(row, action)
                  return (
                    <button
                      key={action.uriKey}
                      type="button"
                      disabled={isDisabled}
                      onClick={e => { e.stopPropagation(); if (!isDisabled) onInlineAction?.(action, row) }}
                      className="martis-action-btn inline-flex items-center justify-center rounded p-1.5 transition-colors hover:opacity-80 disabled:opacity-30 disabled:cursor-default"
                      style={{
                        color: isDisabled ? "var(--martis-text-muted)" : action.destructive ? "#dc2626" : "var(--martis-accent)",
                        backgroundColor: isDisabled ? "transparent" : action.destructive ? "rgba(220,38,38,0.08)" : "rgba(99,102,241,0.08)",
                      }}
                      data-pr-tooltip={action.name}
                      data-pr-position="top"
                    >
                      {action.showIcon !== false && (action.icon ? <ResourceIcon iconName={action.icon} size={18} color={action.iconColor ?? undefined} /> : action.destructive ? <WarningIcon size={18} weight="fill" color={action.iconColor ?? undefined} /> : <LightningIcon size={18} color={action.iconColor ?? undefined} />)}
                    </button>
                  )
                })}
                {hasGroupedInline && (
                  <InlineActionMenu
                    actions={groupedInline}
                    row={row}
                    onAction={(action, r) => onInlineAction?.(action, r)}
                  />
                )}
              </div>
            )}
            style={{ width: "auto", textAlign: "right" }}
          />
        )}
      </DataTable>
    </>
  )
}

if (!registry.has("component:Table")) {
  registry.register("component:Table", DefaultTable)
}

export function Table(props: TableProps) {
  const Component = registry.resolve<TableProps>("component:Table", DefaultTable)
  return <Component {...props} />
}
