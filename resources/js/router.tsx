import { createBrowserRouter } from 'react-router-dom'
import { Layout } from '@/components/Layout'
import { LoginPage } from '@/pages/Login'
import { DashboardPage } from '@/pages/Dashboard'
import { BASE_PATH } from '@/lib/config'

export const router = createBrowserRouter([
  {
    path: '/login',
    element: <LoginPage />,
  },
  {
    path: '/',
    element: <Layout />,
    children: [
      {
        index: true,
        element: <DashboardPage />,
        handle: { crumb: 'Dashboard' },
      },
      {
        path: 'resources/:resource',
        lazy: async () => {
          const { ResourceIndexPage } = await import('@/pages/ResourceIndex')
          return { element: <ResourceIndexPage />, handle: { crumb: 'Resources' } }
        },
      },
      {
        path: 'resources/:resource/create',
        lazy: async () => {
          const { ResourceCreatePage } = await import('@/pages/ResourceCreate')
          return { element: <ResourceCreatePage />, handle: { crumb: 'Create' } }
        },
      },
      {
        path: 'resources/:resource/:id',
        lazy: async () => {
          const { ResourceDetailPage } = await import('@/pages/ResourceDetail')
          return { element: <ResourceDetailPage />, handle: { crumb: 'Detail' } }
        },
      },
      {
        path: 'resources/:resource/:id/edit',
        lazy: async () => {
          const { ResourceUpdatePage } = await import('@/pages/ResourceUpdate')
          return { element: <ResourceUpdatePage />, handle: { crumb: 'Edit' } }
        },
      },
    ],
  },
  {
    path: '*',
    element: <LoginPage />,
  },
], { basename: BASE_PATH })
