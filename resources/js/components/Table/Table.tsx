import { registry } from '@/lib/registry'
import type { FieldDefinition, ResourceRecord } from '@/types'
import { FieldDisplay } from '@/components/fields'
import { DataTable, type DataTableSelectionMultipleChangeEvent, type DataTableSortEvent } from 'primereact/datatable'
import { Column } from 'primereact/column'
import { CaretUp, CaretDown, CaretUpDown } from '@phosphor-icons/react'
import { useTranslation } from 'react-i18next'

export interface TableColumn {
  field: FieldDefinition
}

export interface TableProps {
  columns: TableColumn[]
  rows: ResourceRecord[]
  sortBy: string | null
  sortDir: 'asc' | 'desc'
  onSort: (attribute: string) => void
  selectedIds: Set<string | number>
  onToggleSelect: (id: string | number) => void
  onToggleAll: () => void
  onClickRow?: (row: ResourceRecord) => void
  /** Resource URI key — enables per-resource field display overrides */
  resourceKey?: string
  /** Whether to show row selection checkboxes (default: false) */
  selectable?: boolean
  /** DataTable configuration from Resource schema */
  tableConfig?: {
    striped?: boolean
    showGridlines?: boolean
    size?: 'normal' | 'small' | 'large'
    rowHover?: boolean
  }
}

function SortIcon({ active, dir }: { active: boolean; dir: 'asc' | 'desc' }) {
  if (!active) return <CaretUpDown size={14} className="text-gray-400" />
  return dir === 'asc'
    ? <CaretUp size={14} className="text-indigo-600" />
    : <CaretDown size={14} className="text-indigo-600" />
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
  tableConfig,
}: TableProps) {
  const { t } = useTranslation('resources')
  const allSelected = rows.length > 0 && rows.every((r) => selectedIds.has(r.id))
  const selectedRows = rows.filter((r) => selectedIds.has(r.id))

  function handleSelectionChange(e: DataTableSelectionMultipleChangeEvent<ResourceRecord[]>) {
    const next = new Set((e.value as ResourceRecord[]).map((r) => r.id))
    const prev = selectedIds
    for (const row of rows) {
      if (next.has(row.id) !== prev.has(row.id)) {
        onToggleSelect(row.id)
      }
    }
    if (allSelected && next.size === 0) onToggleAll()
    if (!allSelected && next.size === rows.length) onToggleAll()
  }

  const sortOrder = sortDir === 'asc' ? 1 : -1

  // Build dynamic class list from table configuration
  const striped = tableConfig?.striped !== false // default true
  const gridlines = tableConfig?.showGridlines === true
  const size = tableConfig?.size ?? 'normal'
  const rowHover = tableConfig?.rowHover !== false // default true

  const classNames = [
    'w-full',
    'martis-datatable',
    striped && 'martis-datatable-striped',
    gridlines && 'martis-datatable-gridlines',
    size === 'small' && 'martis-datatable-sm',
    size === 'large' && 'martis-datatable-lg',
    !rowHover && 'martis-datatable-no-hover',
  ].filter(Boolean).join(' ')

  return (
    <DataTable
      value={rows}
      selection={selectable ? selectedRows : []}
      onSelectionChange={handleSelectionChange}
      selectionMode={selectable ? 'checkbox' : null}
      dataKey="id"
      removableSort
      sortField={sortBy ?? undefined}
      sortOrder={sortOrder}
      onSort={(e: DataTableSortEvent) => {
        if (e.sortField) onSort(String(e.sortField))
      }}
      onRowClick={(e) => onClickRow?.(e.data as ResourceRecord)}
      rowClassName={(row: ResourceRecord) =>
        selectable && selectedIds.has(row.id) ? 'bg-indigo-50 dark:bg-indigo-950/20' : ''
      }
      emptyMessage={
        <div className="py-8 text-center text-sm text-gray-400">
          {t('no_records')}
        </div>
      }
      className={classNames}
      tableClassName="min-w-full"
    >
      {selectable && (
        <Column selectionMode="multiple" headerStyle={{ width: '2.5rem' }} />
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
            <FieldDisplay field={field} value={row[field.attribute]} resourceKey={resourceKey} />
          )}
          sortable={false}
        />
      ))}
    </DataTable>
  )
}

// Register into global registry
if (!registry.has('component:Table')) {
  registry.register('component:Table', DefaultTable)
}

/** Resolved Table component — overridable via registry.register('component:Table', ...) */
export function Table(props: TableProps) {
  const Component = registry.resolve<TableProps>('component:Table', DefaultTable)
  return <Component {...props} />
}
