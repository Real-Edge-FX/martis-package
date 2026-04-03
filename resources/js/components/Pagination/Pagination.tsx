import { registry } from '@/lib/registry'

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
  from,
  to,
  onPageChange,
}: PaginationProps) {
  if (lastPage <= 1) return null

  const pages = buildPageNumbers(currentPage, lastPage)

  return (
    <div className="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-950">
      <div className="text-sm text-gray-500 dark:text-gray-400">
        {from !== null && to !== null ? (
          <>
            Mostrando <strong>{from}</strong>–<strong>{to}</strong> de{' '}
            <strong>{total}</strong> registros
          </>
        ) : (
          <>{total} registros</>
        )}
      </div>
      <nav className="flex items-center gap-1" aria-label="Paginação">
        <PaginationButton
          disabled={currentPage <= 1}
          onClick={() => onPageChange(currentPage - 1)}
          aria-label="Página anterior"
        >
          ‹
        </PaginationButton>
        {pages.map((p, i) =>
          p === '...' ? (
            <span key={`ellipsis-${i}`} className="px-2 text-gray-400">
              …
            </span>
          ) : (
            <PaginationButton
              key={p}
              active={p === currentPage}
              onClick={() => onPageChange(p as number)}
            >
              {p}
            </PaginationButton>
          ),
        )}
        <PaginationButton
          disabled={currentPage >= lastPage}
          onClick={() => onPageChange(currentPage + 1)}
          aria-label="Próxima página"
        >
          ›
        </PaginationButton>
      </nav>
    </div>
  )
}

function PaginationButton({
  children,
  active = false,
  disabled = false,
  onClick,
  'aria-label': ariaLabel,
}: {
  children: React.ReactNode
  active?: boolean
  disabled?: boolean
  onClick: () => void
  'aria-label'?: string
}) {
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      aria-label={ariaLabel}
      aria-current={active ? 'page' : undefined}
      className={[
        'inline-flex h-8 min-w-[2rem] items-center justify-center rounded px-2 text-sm font-medium transition-colors',
        active
          ? 'bg-blue-600 text-white'
          : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800',
        disabled ? 'cursor-not-allowed opacity-40' : 'cursor-pointer',
      ].join(' ')}
    >
      {children}
    </button>
  )
}

function buildPageNumbers(current: number, last: number): (number | '...')[] {
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1)
  const pages: (number | '...')[] = [1]
  if (current > 3) pages.push('...')
  for (let p = Math.max(2, current - 1); p <= Math.min(last - 1, current + 1); p++) {
    pages.push(p)
  }
  if (current < last - 2) pages.push('...')
  pages.push(last)
  return pages
}

if (!registry.has('component:Pagination')) {
  registry.register('component:Pagination', DefaultPagination)
}

export function Pagination(props: PaginationProps) {
  const Component = registry.resolve<PaginationProps>('component:Pagination', DefaultPagination)
  return <Component {...props} />
}
