import { useCallback } from "react"
import { registry } from "@/lib/registry"
import type { FieldDefinition, ResourceRecord } from "@/types"
import type { ActionMeta } from "@/components/Actions"
import { FieldDisplay } from "@/components/fields"
import { DataTable, type DataTableSortEvent } from "primereact/datatable"
import { Column } from "primereact/column"
import { Checkbox } from "primereact/checkbox"
import { Tooltip } from "primereact/tooltip"
import { CaretUp, CaretDown, CaretUpDown, Lightning, Warning, DotsThreeVertical } from "@phosphor-icons/react"
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
  if (!active) return <CaretUpDown size={14} className="text-gray-400" />
  return dir === "asc"
    ? <CaretUp size={14} className="text-indigo-600" />
    : <CaretDown size={14} className="text-indigo-600" />
}

/** Grouped inline action menu — renders as a 3-dot button with dropdown */
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

  useEffect(() => {
    if (!open) return
    function handleClick(e: MouseEvent) {
      if (
        menuRef.current && !menuRef.current.contains(e.target as Node) &&
        btnRef.current && !btnRef.current.contains(e.target as Node)
      ) {
        setOpen(false)
      }
    }
    function handleKey(e: KeyboardEvent) {
      if (e.key === "Escape") setOpen(false)
    }
    document.addEventListener("mousedown", handleClick)
    document.addEventListener("keydown", handleKey)
    return () => {
      document.removeEventListener("mousedown", handleClick)
      document.removeEventListener("keydown", handleKey)
    }
  }, [open])

  const rect = btnRef.current?.getBoundingClientRect()

  const canRunAction = row._authorization?.authorizedToRunAction !== false
  const canRunDestructive = row._authorization?.authorizedToRunDestructiveAction !== false

  return (
    <>
      <button
        ref={btnRef}
        type="button"
        onClick={(e) => {
          e.stopPropagation()
          setOpen(!open)
        }}
        className="martis-action-btn inline-flex items-center justify-center rounded p-1.5 transition-colors hover:opacity-80"
        style={{
          color: "var(--martis-text-muted)",
          backgroundColor: open ? "var(--martis-hover)" : "transparent",
        }}
        data-pr-tooltip="Actions"
        data-pr-position="top"
      >
        <DotsThreeVertical size={18} weight="bold" />
      </button>
      {open && rect && createPortal(
        <div
          ref={menuRef}
          className="rounded-lg border shadow-lg py-1"
          style={{
            position: "fixed",
            top: rect.bottom + 4,
            left: Math.min(rect.left, window.innerWidth - 220),
            minWidth: 180,
            zIndex: 9990,
            backgroundColor: "var(--martis-card)",
            borderColor: "var(--martis-border)",
          }}
        >
          {actions.map((action) => {
            const isDisabled = action.destructive ? !canRunDestructive : !canRunAction
            return (
              <button
                key={action.uriKey}
                type="button"
                disabled={isDisabled}
                onClick={(e) => {
                  e.stopPropagation()
                  if (!isDisabled) {
                    setOpen(false)
                    onAction(action, row)
                  }
                }}
                className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                style={{
                  color: isDisabled
                    ? "var(--martis-text-muted)"
                    : action.destructive
                      ? "#dc2626"
                      : "var(--martis-text)",
                }}
                onMouseEnter={(e) => {
                  if (!isDisabled) (e.currentTarget as HTMLElement).style.backgroundColor = "var(--martis-hover)"
                }}
                onMouseLeave={(e) => {
                  (e.currentTarget as HTMLElement).style.backgroundColor = "transparent"
                }}
              >
                {action.icon
                  ? <ResourceIcon iconName={action.icon} size={16} />
                  : action.destructive
                    ? <Warning size={16} weight="fill" />
                    : <Lightning size={16} />}
                <span>{action.name}</span>
              </button>
            )
          })}
        </div>,
        document.body,
      )}
    </>
  )
}

function DefaultTable({
  columns,
  rows,
  sortBy,
  sortDir,
  onSort,
  selectedIds,
  onToggleSelect,
  onToggleAll,
  onClickRow,
  resourceKey,
  selectable = false,
  inlineActions = [],
  onInlineAction,
  tableConfig,
}: TableProps) {
  const { t } = useTranslation("resources")
  const { t: tMsg } = useTranslation("messages")
  const allSelected = rows.length > 0 && rows.every((r) => selectedIds.has(r.id))

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

  const ungroupedInline = inlineActions.filter((a) => !a.group)
  const groupedInline = inlineActions.filter((a) => !!a.group)
  const hasGroupedInline = groupedInline.length > 0

  const canRunForRow = useCallback(
    (row: ResourceRecord, action: ActionMeta): boolean => {
      if (action.destructive) {
        return row._authorization?.authorizedToRunDestructiveAction !== false
      }
      return row._authorization?.authorizedToRunAction !== false
    },
    [],
  )

  return (
    <>
      <Tooltip target=".martis-action-btn" position="top" showDelay={400} />
      <DataTable
        value={rows}
        dataKey="id"
        removableSort
        sortField={sortBy ?? undefined}
        sortOrder={sortOrder}
        onSort={(e: DataTableSortEvent) => {
          if (e.sortField) onSort(String(e.sortField))
        }}
        onRowClick={(e) => onClickRow?.(e.data as ResourceRecord)}
        rowClassName={(row: ResourceRecord) => {
          const classes: string[] = []
          if (row._authorization?.authorizedToView === false) classes.push("cursor-default opacity-70")
          if ("deleted_at" in row && row["deleted_at"] !== null) classes.push("opacity-60")
          if (selectable && selectedIds.has(row.id)) classes.push("bg-indigo-50 dark:bg-indigo-950/20")
          return classes.join(" ")
        }}
        emptyMessage={
          <div className="py-8 text-center text-sm text-gray-400">
            {t("no_records")}
          </div>
        }
        className={classNames}
        tableClassName="min-w-full"
      >
        {selectable && (
          <Column
            header={
              <Checkbox
                checked={allSelected}
                onChange={() => onToggleAll()}
                className="martis-table-checkbox"
              />
            }
            body={(row: ResourceRecord) => (
              <Checkbox
                checked={selectedIds.has(row.id)}
                onChange={() => onToggleSelect(row.id)}
                onClick={(e) => e.stopPropagation()}
                className="martis-table-checkbox"
              />
            )}
            headerStyle={{ width: "2.5rem" }}
          />
        )}
        {rows.some((r) => "deleted_at" in r && r["deleted_at"] !== null) && (
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
                <button
                  type="button"
                  className="flex items-center gap-1 font-medium uppercase tracking-wider text-xs text-gray-500 hover:text-gray-900 dark:hover:text-white"
                  onClick={() => onSort(field.attribute)}
                >
                  {field.label}
                  <SortIcon active={sortBy === field.attribute} dir={sortDir} />
                </button>
              ) : (
                <span className="text-xs font-medium uppercase tracking-wider text-gray-500">
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
              <div
                className="flex items-center justify-end gap-1"
                onClick={(e) => e.stopPropagation()}
              >
                {ungroupedInline.map((action) => {
                  const isDisabled = !canRunForRow(row, action)
                  return (
                    <button
                      key={action.uriKey}
                      type="button"
                      disabled={isDisabled}
                      onClick={(e) => {
                        e.stopPropagation()
                        if (!isDisabled) onInlineAction?.(action, row)
                      }}
                      className="martis-action-btn inline-flex items-center justify-center rounded p-1.5 transition-colors hover:opacity-80 disabled:opacity-30 disabled:cursor-not-allowed"
                      style={{
                        color: isDisabled
                          ? "var(--martis-text-muted)"
                          : action.destructive
                            ? "#dc2626"
                            : "var(--martis-accent)",
                        backgroundColor: isDisabled
                          ? "transparent"
                          : action.destructive
                            ? "rgba(220,38,38,0.08)"
                            : "rgba(99,102,241,0.08)",
                      }}
                      data-pr-tooltip={action.name}
                      data-pr-position="top"
                    >
                      {action.icon ? (
                        <ResourceIcon iconName={action.icon} size={18} />
                      ) : action.destructive ? (
                        <Warning size={18} weight="fill" />
                      ) : (
                        <Lightning size={18} />
                      )}
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
