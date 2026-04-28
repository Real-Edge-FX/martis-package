import { useTranslation } from 'react-i18next'
import { CompassIcon, WarningIcon, PlugsConnectedIcon } from '@phosphor-icons/react'
import { ApiError } from '@/lib/api'
import { ErrorScreen } from '@/components/auth/ErrorScreen'

/**
 * Triaged error page for resource-page query failures.
 *
 * Replaces the previous catch-all `<NotFoundPage />` rendered whenever
 * a schema or record fetch failed for any reason. The original
 * behaviour conflated three distinct cases and pushed every one of
 * them through a 404 "Resource not found" UI:
 *
 *   - The resource really did not exist (404).
 *   - The user was not authorised (403).
 *   - The server crashed (5xx).
 *
 * The 5xx case is the most damaging to hide: an operator hitting a
 * production bug saw the exact same screen as someone mistyping a URL,
 * with no signal that the backend was actually broken.
 *
 * This component looks at the error object and routes to a status-
 * appropriate variant of `ErrorScreen`. Non-`ApiError` errors (network
 * failures, timeouts, etc.) get their own variant since the user
 * cannot do anything about them.
 */
export interface ResourceErrorPageProps {
  /**
   * The error thrown by the failing query. Typically an `ApiError`
   * from `@/lib/api`, but any thrown value is accepted — non-`ApiError`
   * values are treated as network/transport failures.
   */
  error: unknown
}

export function ResourceErrorPage({ error }: ResourceErrorPageProps) {
  const { t } = useTranslation('messages')

  // Network / transport / programmer error — the request never landed
  // a status code, so we cannot triage by HTTP. Show a connection-
  // oriented page so the user understands a retry might fix it.
  if (!(error instanceof ApiError)) {
    return (
      <ErrorScreen
        code="—"
        icon={<PlugsConnectedIcon size={32} weight="regular" />}
        title={t('error_network_title', { defaultValue: 'Cannot reach the server' })}
        description={t('error_network_desc', {
          defaultValue: 'The page could not finish loading because the request never reached the server. Check your connection and try again.',
        })}
      />
    )
  }

  // 404 — keep the original copy verbatim so existing screenshots /
  // tests / muscle memory still match.
  if (error.status === 404) {
    return (
      <ErrorScreen
        code="404"
        icon={<CompassIcon size={32} weight="regular" />}
        title={t('not_found_title', { defaultValue: 'Resource not found' })}
        description={t('not_found_desc', {
          defaultValue: "The page you're looking for doesn't exist or you don't have permission to see it.",
        })}
      />
    )
  }

  // 403 — explicit, actionable copy (different from 404 because the
  // user knows the page exists but is denied).
  if (error.status === 403) {
    return (
      <ErrorScreen
        code="403"
        icon={<CompassIcon size={32} weight="regular" />}
        title={t('forbidden_title', { defaultValue: 'Access denied' })}
        description={t('forbidden_desc', {
          defaultValue: 'You do not have permission to view this page. Contact an administrator if you believe this is a mistake.',
        })}
      />
    )
  }

  // 5xx — surface the status code so the operator knows the backend
  // is broken rather than the URL is wrong. The error message from
  // the response is intentionally NOT rendered here: in production
  // Laravel returns a generic "Server Error" string anyway, and in
  // development mode the operator should be reading
  // `storage/logs/laravel.log` for the full stack trace, not relying
  // on the UI to leak it.
  if (error.status >= 500) {
    return (
      <ErrorScreen
        code={String(error.status)}
        icon={<WarningIcon size={32} weight="regular" />}
        title={t('server_error_title', { defaultValue: 'Server error' })}
        description={t('server_error_desc', {
          defaultValue: 'The page failed to load because the server returned an error. Check the application logs for the full stack trace, then try again.',
        })}
      />
    )
  }

  // Any other 4xx (400, 405, 408, 410, 422, ...) — fall back to the
  // 404 page since none of the resource pages can sensibly hit them
  // and the generic "page not available" message is honest enough.
  return (
    <ErrorScreen
      code={String(error.status)}
      icon={<CompassIcon size={32} weight="regular" />}
      title={t('not_found_title', { defaultValue: 'Resource not found' })}
      description={t('not_found_desc', {
        defaultValue: "The page you're looking for doesn't exist or you don't have permission to see it.",
      })}
    />
  )
}
