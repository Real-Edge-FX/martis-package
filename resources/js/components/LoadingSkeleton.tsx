interface SkeletonProps {
  className?: string
}

function SkeletonLine({ className = '' }: SkeletonProps) {
  return (
    <div
      className={`animate-pulse rounded bg-gray-200 dark:bg-gray-700 ${className}`}
    />
  )
}

export function TableSkeleton() {
  return (
    <div className="space-y-3">
      <SkeletonLine className="h-10 w-full" />
      {Array.from({ length: 5 }).map((_, i) => (
        <SkeletonLine key={i} className="h-12 w-full" />
      ))}
    </div>
  )
}

export function CardSkeleton() {
  return (
    <div className="space-y-2 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
      <SkeletonLine className="h-5 w-1/3" />
      <SkeletonLine className="h-4 w-2/3" />
      <SkeletonLine className="h-4 w-1/2" />
    </div>
  )
}

