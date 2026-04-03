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
  if (lastPage <= 1) return null

  // PrimeReact Paginator uses 0-based first (row offset)
  const first = (currentPage - 1) * perPage

  function handlePageChange(e: PaginatorPageChangeEvent) {
    onPageChange(e.page + 1)
  }

  return (
    <div className="border-t border-gray-200 dark:border-gray-800">
      <div className="flex items-center justify-between px-4 py-1">
        <span className="text-sm text-gray-500 dark:text-gray-400">
          {from !== null && to !== null ? (
            <>
              Showing <strong>{from}</strong>–<strong>{to}</strong> of{' '}
              <strong>{total}</strong>
            </>
          ) : (
            <>{total} records</>
          )}
        </span>
        <Paginator
          first={first}
          rows={perPage}
          totalRecords={total}
          onPageChange={handlePageChange}
          template="PrevPageLink PageLinks NextPageLink"
          className="p-0"
        />
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
