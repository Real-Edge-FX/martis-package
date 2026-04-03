import { registry } from '@/lib/registry'
import type { FieldDefinition, ResourceRecord } from '@/types'
import { FieldDisplay } from '@/components/fields'

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
}: TableProps) {
  const allSelected = rows.length > 0 && rows.every((r) => selectedIds.has(r.id))
  const someSelected = rows.some((r) => selectedIds.has(r.id))

  return (
    <div className="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
      <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
        <thead className="bg-gray-50 dark:bg-gray-900">
          <tr>
            {/* Checkbox column */}
            <th className="w-10 px-4 py-3">
              <input
                type="checkbox"
                checked={allSelected}
                ref={(el) => {
                  if (el) el.indeterminate = someSelected && !allSelected
                }}
                onChange={onToggleAll}
                className="h-4 w-4 rounded border-gray-300 text-blue-600 dark:border-gray-600"
                aria-label="Selecionar todos"
              />
            </th>
            {columns.map(({ field }) => (
              <th
                key={field.attribute}
                className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
              >
                {field.sortable ? (
                  <button
                    type="button"
                    onClick={() => onSort(field.attribute)}
                    className="flex items-center gap-1 hover:text-gray-900 dark:hover:text-white"
                  >
                    {field.label}
                    <SortIcon active={sortBy === field.attribute} dir={sortDir} />
                  </button>
                ) : (
                  field.label
                )}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-950">
          {rows.length === 0 ? (
            <tr>
              <td
                colSpan={columns.length + 1}
                className="px-4 py-8 text-center text-sm text-gray-400 dark:text-gray-500"
              >
                Nenhum registro encontrado.
              </td>
            </tr>
          ) : (
            rows.map((row) => (
              <tr
                key={row.id}
                onClick={() => onClickRow?.(row)}
                className={[
                  'transition-colors',
                  onClickRow ? 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-900' : '',
                  selectedIds.has(row.id) ? 'bg-blue-50 dark:bg-blue-950/20' : '',
                ].join(' ')}
              >
                <td
                  className="w-10 px-4 py-3"
                  onClick={(e) => e.stopPropagation()}
                >
                  <input
                    type="checkbox"
                    checked={selectedIds.has(row.id)}
                    onChange={() => onToggleSelect(row.id)}
                    className="h-4 w-4 rounded border-gray-300 text-blue-600 dark:border-gray-600"
                    aria-label={`Selecionar registro ${row.id}`}
                  />
                </td>
                {columns.map(({ field }) => (
                  <td key={field.attribute} className="px-4 py-3 text-sm">
                    <FieldDisplay field={field} value={row[field.attribute]} resourceKey={resourceKey} />
                  </td>
                ))}
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  )
}

function SortIcon({ active, dir }: { active: boolean; dir: 'asc' | 'desc' }) {
  return (
    <span className={active ? 'text-blue-600 dark:text-blue-400' : 'text-gray-300 dark:text-gray-600'}>
      {active && dir === 'desc' ? '↓' : '↑'}
    </span>
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
