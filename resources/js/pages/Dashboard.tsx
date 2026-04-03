import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { NavigationGroup } from '@/types'
import { useAuth } from '@/contexts/AuthContext'
import { CardSkeleton } from '@/components/LoadingSkeleton'
import { Database } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

export function DashboardPage() {
  const { user } = useAuth()
  const { t } = useTranslation('resources')
  const { data: groups = [], isLoading } = useQuery<NavigationGroup[]>({
    queryKey: ['navigation'],
    queryFn: () => api.get('/api/navigation'),
    staleTime: 1000 * 60,
  })

  const totalResources = groups.reduce((n, g) => n + g.resources.length, 0)
  const name = user?.name ?? user?.email ?? ''

  return (
    <div>
      <h1 className="mb-1 text-2xl font-bold text-gray-900 dark:text-white">
        {t('hello', { name })} 👋
      </h1>
      <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
        {t('welcome')}
      </p>

      {isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[1, 2, 3].map((i) => <CardSkeleton key={i} />)}
        </div>
      ) : (
        <>
          <div className="mb-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p className="text-sm text-gray-600 dark:text-gray-400">{t('registered')}</p>
            <p className="text-3xl font-bold text-brand">{totalResources}</p>
          </div>

          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {groups.flatMap((g) =>
              g.resources.map((r) => (
                <Link
                  key={r.uriKey}
                  to={`/resources/${r.uriKey}`}
                  className="flex items-center gap-3 rounded-lg border border-gray-200 bg-white p-4 transition hover:border-brand hover:shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:hover:border-brand"
                >
                  <Database size={20} className="text-brand" />
                  <div>
                    <p className="font-medium text-gray-900 dark:text-white">{r.label}</p>
                    {r.group && (
                      <p className="text-xs text-gray-500 dark:text-gray-400">{r.group}</p>
                    )}
                  </div>
                </Link>
              )),
            )}
          </div>
        </>
      )}
    </div>
  )
}
