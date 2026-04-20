import { registry } from '@/lib/registry'
import { Paginator, type PaginatorPageChangeEvent } from 'primereact/paginator'
import { useTranslation } from 'react-i18next'

export interface PaginationProps {
  currentPage: number
  lastPage: number
  total: number
  perPage: number
  from: number | null
  to: number | null
  onPageChange: (page: number) => void
  /**
   * Number of rows currently selected. When > 0, the left-hand summary
   * gains a "N selected · " prefix (matching the design-system table
   * footer spec). Defaults to 0.
   */
  selectedCount?: number
  /**
   * Resource label used in the empty-pagination fallback
   * ("42 {label}"). Pass the plural, already-localised form.
   */
  itemLabel?: string
}

function DefaultPagination({
  currentPage,
  lastPage,
  total,
  perPage,
  from,
  to,
  onPageChange,
  selectedCount = 0,
  itemLabel,
}: PaginationProps) {
  const { t } = useTranslation('resources')
  if (total === 0) return null

  // PrimeReact Paginator uses 0-based first (row offset)
  const first = (currentPage - 1) * perPage

  function handlePageChange(e: PaginatorPageChangeEvent) {
    onPageChange(e.page + 1)
  }

  const hasRange = from !== null && to !== null
  const resourceLabel = itemLabel ?? t('records', 'records')

  return (
    <div className="martis-pagination-wrapper">
      <div className="martis-pagination-row">
        <span className="martis-pagination-summary">
          {selectedCount > 0 && (
            <>
              <span className="martis-pagination-selected">
                {t('selected', '{{count}} selected', { count: selectedCount })}
              </span>
              <span className="martis-pagination-sep" aria-hidden="true"> · </span>
            </>
          )}
          {hasRange ? (
            <>
              {from}–{to} {t('of', 'of')} {total}{' '}
              <span className="martis-pagination-label">{resourceLabel}</span>
            </>
          ) : (
            <>
              {total}{' '}
              <span className="martis-pagination-label">{resourceLabel}</span>
            </>
          )}
        </span>
        {lastPage > 1 && (
          <Paginator
            first={first}
            rows={perPage}
            totalRecords={total}
            onPageChange={handlePageChange}
            template="PrevPageLink PageLinks NextPageLink"
            className="martis-paginator"
          />
        )}
      </div>
    </div>
  )
}

if (!registry.has('component:Pagination')) {
  registry.register('component:Pagination', DefaultPagination)
}

export function Pagination(props: PaginationProps) {
  const Component = registry.resolve<PaginationProps>('component:Pagination', DefaultPagination)
  return <Component {...props} />
}
