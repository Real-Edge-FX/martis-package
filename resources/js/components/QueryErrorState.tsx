import { useTranslation } from 'react-i18next'
import { ArrowClockwiseIcon, CompassIcon, PlugsConnectedIcon, WarningIcon } from '@phosphor-icons/react'
import { ApiError } from '@/lib/api'

/**
 * Inline error state for a failed *listing* fetch (resource index, lens,
 * relationship panels).
 *
 * Sibling of `ResourceErrorPage`, scoped to the table instead of the
 * whole page: the schema loaded fine and the toolbar (search, filters,
 * per-page, trashed) stays usable, so the user can fix a filter or hit
 * Retry without leaving the page. Before it existed, a failing index
 * query fed the table `[]` and the empty state ("No records found.")
 * rendered underneath the loader while the request retried, so a 500
 * was indistinguishable from an empty resource, on screen and on a
 * support screenshot.
 *
 * Triage mirrors `ResourceErrorPage` (network / 403 / 404 / 5xx / other)
 * but the copy is about the records, not the page. The HTTP status and
 * the server message are rendered as a small detail line on purpose:
 * the status is what lets support tell a failure from an empty list on
 * a screenshot, and in production Laravel's message is the generic
 * "Server Error" anyway (the stack trace belongs in the logs).
 */
export interface QueryErrorStateProps {
  /** The error thrown by the failing query (typically an `ApiError`). */
  error: unknown
  /** Refetch handler for the Retry button. Omit to hide the button. */
  onRetry?: () => void
  /** True while a retry is in flight; disables the button. */
  retrying?: boolean
  /** Tighter padding for embedded surfaces (relationship panels). */
  compact?: boolean
}

type Triage = {
  status: string
  icon: JSX.Element
  description: string
}

export function QueryErrorState({ error, onRetry, retrying = false, compact = false }: QueryErrorStateProps) {
  const { t } = useTranslation('messages')

  const triage = triageError(error, t)
  const title = t('query_error_title', { defaultValue: 'Records could not be loaded' })
  const detail = describeError(error)

  return (
    <div
      role="alert"
      data-status={triage.status}
      className={compact ? 'martis-query-error martis-query-error-compact' : 'martis-query-error'}
    >
      <div className="martis-query-error-icon" aria-hidden="true">{triage.icon}</div>
      <div className="martis-query-error-body">
        <h3 className="martis-query-error-title">{title}</h3>
        <p className="martis-query-error-desc">{triage.description}</p>
        {detail && <code className="martis-query-error-detail">{detail}</code>}
      </div>
      {onRetry && (
        <button
          type="button"
          className="martis-btn-secondary martis-query-error-retry"
          onClick={onRetry}
          disabled={retrying}
        >
          <ArrowClockwiseIcon size={14} weight="bold" />
          {t('error_try_again', { defaultValue: 'Try again' })}
        </button>
      )}
    </div>
  )
}

/**
 * Human-facing title for a failed listing fetch. Shared with the toast
 * the pages fire on the transition to the error state so both surfaces
 * say the same thing.
 */
export function queryErrorTitle(t: (key: string, opts: { defaultValue: string }) => string): string {
  return t('query_error_title', { defaultValue: 'Records could not be loaded' })
}

function triageError(error: unknown, t: (key: string, opts: { defaultValue: string }) => string): Triage {
  // Network / transport / programmer error: no status code to triage by.
  if (!(error instanceof ApiError)) {
    return {
      status: 'network',
      icon: <PlugsConnectedIcon size={22} weight="regular" />,
      description: t('query_error_network', {
        defaultValue: 'The request never reached the server. Check your connection and try again.',
      }),
    }
  }

  if (error.status === 403) {
    return {
      status: String(error.status),
      icon: <CompassIcon size={22} weight="regular" />,
      description: t('query_error_forbidden', {
        defaultValue: 'You do not have permission to list these records.',
      }),
    }
  }

  if (error.status === 404) {
    return {
      status: String(error.status),
      icon: <CompassIcon size={22} weight="regular" />,
      description: t('query_error_not_found', { defaultValue: 'This listing is not available.' }),
    }
  }

  if (error.status >= 500) {
    return {
      status: String(error.status),
      icon: <WarningIcon size={22} weight="regular" />,
      description: t('query_error_server', {
        defaultValue: 'The server returned an error while loading these records. Check the application logs for the full stack trace, then try again.',
      }),
    }
  }

  return {
    status: String(error.status),
    icon: <WarningIcon size={22} weight="regular" />,
    description: t('query_error_generic', {
      defaultValue: 'The request failed. Try again, or adjust the filters and search.',
    }),
  }
}

/** `HTTP 500 · Server Error` for API errors, the bare message otherwise. */
function describeError(error: unknown): string | null {
  if (error instanceof ApiError) {
    const message = error.message.trim()
    return message ? `HTTP ${error.status} · ${message}` : `HTTP ${error.status}`
  }
  if (error instanceof Error && error.message.trim()) return error.message.trim()
  return null
}
