import { Component, type ReactNode, type ErrorInfo } from 'react'
import { useTranslation } from 'react-i18next'

interface Props {
  children: ReactNode
  fallback?: ReactNode
  /** Optional label shown in the error UI (e.g. "Posts list") for better context. */
  label?: string
  /** Called when the user clicks "Try again". Defaults to resetting internal error state. */
  onReset?: () => void
}

interface State {
  hasError: boolean
  error: Error | null
}

/**
 * ErrorBoundary — catches uncaught render errors and displays a recovery UI.
 *
 * Supports both default and custom fallback rendering, i18n, and proper
 * light/dark theming via CSS variables.
 *
 * @example
 * ```tsx
 * <ErrorBoundary label="Posts list">
 *   <ResourceIndex />
 * </ErrorBoundary>
 * ```
 */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { hasError: false, error: null }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('[Martis] Uncaught render error:', error, info)
  }

  private handleReset = () => {
    if (this.props.onReset) {
      this.props.onReset()
    }
    this.setState({ hasError: false, error: null })
  }

  render() {
    if (this.state.hasError) {
      if (this.props.fallback) {
        return this.props.fallback
      }

      return (
        <ErrorFallback
          error={this.state.error}
          label={this.props.label}
          onReset={this.handleReset}
        />
      )
    }

    return this.props.children
  }
}

interface ErrorFallbackProps {
  error: Error | null
  label?: string
  onReset: () => void
}

/**
 * Default fallback UI for ErrorBoundary.
 * Reads the Martis theme tokens, so it follows the light and dark modes.
 */
function ErrorFallback({ error, label, onReset }: ErrorFallbackProps) {
  const { t } = useTranslation('messages')

  return (
    <div className="flex min-h-[200px] items-center justify-center p-8">
      <div
        className="w-full max-w-md rounded-lg border border-solid bg-martis-card p-6 text-center shadow-sm"
        style={{ borderColor: 'color-mix(in srgb, var(--martis-danger) 30%, transparent)' }}
      >
        <div className="mb-3 flex justify-center">
          <div
            className="flex h-12 w-12 items-center justify-center rounded-full"
            style={{ backgroundColor: 'color-mix(in srgb, var(--martis-danger) 10%, transparent)' }}
          >
            <svg
              className="h-6 w-6 text-martis-danger"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              strokeWidth={2}
              aria-hidden="true"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"
              />
            </svg>
          </div>
        </div>

        <h2 className="mb-1 text-base font-semibold text-martis-text">
          {t('error_boundary_title', 'Unexpected error')}
          {label && (
            <span className="ml-1 font-normal text-martis-text-muted">
              — {label}
            </span>
          )}
        </h2>

        <p className="mb-4 text-sm text-martis-text-muted">
          {error?.message ?? t('error_boundary_message', 'An error occurred while rendering this section.')}
        </p>

        <button
          type="button"
          onClick={onReset}
          className="martis-btn-primary"
        >
          {t('error_boundary_retry', 'Try again')}
        </button>
      </div>
    </div>
  )
}
