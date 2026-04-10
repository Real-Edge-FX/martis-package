import { createBrowserRouter } from 'react-router-dom'
import { Layout } from '@/components/Layout'
import { LoginPage } from '@/pages/Login'
import { DashboardPage } from '@/pages/Dashboard'
import { NotFoundPage } from '@/pages/NotFound'
import { TwoFactorChallengePage } from '@/pages/TwoFactorChallenge'
import { BASE_PATH } from '@/lib/config'

export const router = createBrowserRouter([
  {
    path: '/login',
    element: <LoginPage />,
  },
  {
    path: '/2fa/challenge',
    element: <TwoFactorChallengePage />,
  },
  {
    path: '/',
    element: <Layout />,
    children: [
      {
        index: true,
        element: <DashboardPage />,
        handle: { crumb: 'dashboard' },
      },
      {
        path: 'profile',
        lazy: async () => {
          const { ProfilePage } = await import('@/pages/Profile')
          return { element: <ProfilePage />, handle: { crumb: 'profile' } }
        },
      },
      {
        path: 'resources/:resource',
        lazy: async () => {
          const { ResourceIndexPage } = await import('@/pages/ResourceIndex')
          return { element: <ResourceIndexPage />, handle: { crumb: 'resources' } }
        },
      },
      {
        path: 'resources/:resource/create',
        lazy: async () => {
          const { ResourceCreatePage } = await import('@/pages/ResourceCreate')
          return { element: <ResourceCreatePage />, handle: { crumb: 'create' } }
        },
      },
      {
        path: 'resources/:resource/:id',
        lazy: async () => {
          const { ResourceDetailPage } = await import('@/pages/ResourceDetail')
          return { element: <ResourceDetailPage />, handle: { crumb: 'detail' } }
        },
      },
      {
        path: 'resources/:resource/:id/edit',
        lazy: async () => {
          const { ResourceUpdatePage } = await import('@/pages/ResourceUpdate')
          return { element: <ResourceUpdatePage />, handle: { crumb: 'edit' } }
        },
      },
      {
        path: '*',
        element: <NotFoundPage />,
        handle: { crumb: '404' },
      },
    ],
  },
], { basename: BASE_PATH })
