import { useParams } from 'react-router-dom'
import { TableSkeleton } from '@/components/LoadingSkeleton'

export function ResourceIndexPage() {
  const { resource } = useParams<{ resource: string }>()

  return (
    <div>
      <h1 className="mb-4 text-2xl font-bold text-gray-900 capitalize dark:text-white">
        {resource}
      </h1>
      <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
        <TableSkeleton />
        <p className="mt-4 text-center text-sm text-gray-400">
          Resource index — implementado no Bloco 8
        </p>
      </div>
    </div>
  )
}

