import { Skeleton } from '@/components/ui/Skeleton'

export function TableSkeleton() {
  return (
    <div className="space-y-3">
      <Skeleton height="2.5rem" width="100%" />
      {Array.from({ length: 5 }).map((_, i) => (
        <Skeleton key={i} height="3rem" width="100%" />
      ))}
    </div>
  )
}

export function CardSkeleton() {
  return (
    <div
      className="space-y-2 rounded-lg p-4"
      style={{ border: '1px solid var(--martis-border)', backgroundColor: 'var(--martis-surface)' }}
    >
      <Skeleton height="1.25rem" width="33%" />
      <Skeleton height="1rem" width="66%" />
      <Skeleton height="1rem" width="50%" />
    </div>
  )
}
