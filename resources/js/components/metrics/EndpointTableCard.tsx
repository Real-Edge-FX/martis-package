import { useTranslation } from 'react-i18next'

type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE' | string

interface EndpointRow {
  /** HTTP verb. Drives the coloured chip in the first column. */
  method: HttpMethod
  /** Route path, rendered in mono. */
  path: string
  /** Requests per minute. Optional numeric column. */
  rpm?: number
  /** P95 latency in ms. Optional. */
  latencyMs?: number
  /** Error ratio 0..1. Highlighted when above `errorWarnThreshold`. */
  errorRate?: number
  /**
   * Share of total traffic 0..100. Rendered as a thin progress bar.
   * Optional — omit to hide the share column.
   */
  share?: number
}

interface EndpointTableCardProps {
  data: Record<string, unknown>
}

function methodClass(method: string): string {
  const key = method.toLowerCase()
  if (['get', 'post', 'put', 'patch', 'delete'].includes(key)) {
    return `martis-http-method is-${key}`
  }
  return 'martis-http-method'
}

/**
 * F7-18 — EndpointTable card. Compact table of top routes with coloured
 * HTTP method chips, mono numeric columns and a thin share bar.
 * Mirrors the `EndpointTable` component from Dashboard.html.
 */
export function EndpointTableCard({ data }: EndpointTableCardProps) {
  const { t } = useTranslation('resources')
  const rows = (data.rows as EndpointRow[] | undefined) ?? []
  const errorWarnThreshold = (data.errorWarnThreshold as number | undefined) ?? 0.2
  const showShare = rows.some((r) => r.share !== undefined)

  if (rows.length === 0) {
    return <p className="martis-text-muted text-sm">—</p>
  }

  return (
    <table className="martis-endpoint-table">
      <thead>
        <tr>
          <th style={{ width: 64 }}>{t('endpoint_method', 'Method')}</th>
          <th>{t('endpoint_path', 'Path')}</th>
          <th style={{ width: 100, textAlign: 'right' }}>{t('endpoint_rpm', 'Req/min')}</th>
          <th style={{ width: 80, textAlign: 'right' }}>{t('endpoint_latency', 'P95 lat')}</th>
          <th style={{ width: 80, textAlign: 'right' }}>{t('endpoint_errors', 'Err %')}</th>
          {showShare && <th style={{ width: 140 }}>{t('endpoint_share', 'Share')}</th>}
        </tr>
      </thead>
      <tbody>
        {rows.map((row, i) => {
          const errorPercent = row.errorRate !== undefined ? row.errorRate * 100 : null
          const errWarn = row.errorRate !== undefined && row.errorRate > errorWarnThreshold
          return (
            <tr key={i}>
              <td>
                <span className={methodClass(row.method)}>{row.method}</span>
              </td>
              <td className="is-mono">{row.path}</td>
              <td className="is-num">{row.rpm ?? '—'}</td>
              <td className="is-num">{row.latencyMs !== undefined ? `${row.latencyMs}ms` : '—'}</td>
              <td className={errWarn ? 'is-num is-warn' : 'is-num'}>
                {errorPercent !== null ? errorPercent.toFixed(2) : '—'}
              </td>
              {showShare && (
                <td>
                  {row.share !== undefined ? (
                    <div
                      className="martis-endpoint-share"
                      role="progressbar"
                      aria-valuemin={0}
                      aria-valuemax={100}
                      aria-valuenow={Math.round(row.share)}
                    >
                      <div
                        className="martis-endpoint-share-fill"
                        style={{ width: `${Math.min(100, Math.max(0, row.share))}%` }}
                      />
                    </div>
                  ) : (
                    '—'
                  )}
                </td>
              )}
            </tr>
          )
        })}
      </tbody>
    </table>
  )
}
