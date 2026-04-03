import { Component, type ReactNode, type ErrorInfo } from 'react'

interface Props {
  children: ReactNode
  fallback?: ReactNode
}

interface State {
  hasError: boolean
  error: Error | null
}

export class ErrorBoundary extends Component<Props, State> {
  state: State = { hasError: false, error: null }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('[Martis] Uncaught error:', error, info)
  }

  render() {
    if (this.state.hasError) {
      return (
        this.props.fallback ?? (
          <div className="flex min-h-screen items-center justify-center bg-gray-50 dark:bg-gray-900">
            <div className="rounded-lg border border-red-200 bg-white p-8 text-center shadow-md dark:border-red-800 dark:bg-gray-800">
              <h2 className="mb-2 text-lg font-semibold text-red-600 dark:text-red-400">
                Erro inesperado
              </h2>
              <p className="text-sm text-gray-600 dark:text-gray-400">
                {this.state.error?.message ?? 'Ocorreu um erro ao renderizar esta página.'}
              </p>
              <button
                onClick={() => this.setState({ hasError: false, error: null })}
                className="mt-4 rounded bg-brand px-4 py-2 text-sm text-white hover:bg-brand-dark"
              >
                Tentar novamente
              </button>
            </div>
          </div>
        )
      )
    }
    return this.props.children
  }
}

