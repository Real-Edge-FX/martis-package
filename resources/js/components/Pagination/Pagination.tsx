import { registry } from '@/lib/registry'
import { Paginator, type PaginatorPageChangeEvent } from 'primereact/paginator'

export interface PaginationProps {
  currentPage: number
  lastPage: number
  total: number
  perPage: number
  from: number | null
  to: number | null
  onPageChange: (page: number) => void
}

function DefaultPagination({
  currentPage,
  lastPage,
  total,
  perPage,
  from,
  to,
  onPageChange,
}: PaginationProps) {
  if (total === 0) return null

  // PrimeReact Paginator uses 0-based first (row offset)
  const first = (currentPage - 1) * perPage

  function handlePageChange(e: PaginatorPageChangeEvent) {
    onPageChange(e.page + 1)
  }

  return (
    <div className="martis-pagination-wrapper">
      <div className="flex items-center justify-between py-1">
        <span className="text-sm martis-text-muted">
          {from !== null && to !== null ? (
            <>
              Showing <strong>{from}</strong>–<strong>{to}</strong> of{' '}
              <strong>{total}</strong>
            </>
          ) : (
            <>{total} records</>
          )}
        </span>
        {lastPage > 1 && (
          <Paginator
            first={first}
            rows={perPage}
            totalRecords={total}
            onPageChange={handlePageChange}
            template="PrevPageLink PageLinks NextPageLink"
            className="p-0"
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
