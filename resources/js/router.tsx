import { createBrowserRouter } from 'react-router-dom'
import { Layout } from '@/components/Layout'
import { LoginPage } from '@/pages/Login'
import { DashboardPage } from '@/pages/Dashboard'

export const router = createBrowserRouter([
  {
    path: '/martis/login',
    element: <LoginPage />,
  },
  {
    path: '/martis',
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
    ],
  },
  {
    path: '*',
    element: <LoginPage />,
  },
])

