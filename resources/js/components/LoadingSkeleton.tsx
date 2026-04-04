import { Skeleton } from 'primereact/skeleton'

export function TableSkeleton() {
  return (
    <div className="space-y-3">
      <Skeleton height="2.5rem" className="w-full" />
      {Array.from({ length: 5 }).map((_, i) => (
        <Skeleton key={i} height="3rem" className="w-full" />
      ))}
    </div>
  )
}

export function CardSkeleton() {
  return (
    <div className="space-y-2 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
      <Skeleton height="1.25rem" width="33%" />
      <Skeleton height="1rem" width="66%" />
      <Skeleton height="1rem" width="50%" />
    </div>
  )
}
