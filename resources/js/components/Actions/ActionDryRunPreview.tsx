import { useTranslation } from 'react-i18next'

/**
 * The answer of an action's `dryRun()`, shown in its modal after Preview
 * (`withDryRun()`). `dryRun()` returns an array: its `preview` text leads,
 * and every other key is listed below it with its value.
 */
export function ActionDryRunPreview({ preview }: { preview: unknown }) {
  const { t } = useTranslation('actions')

  const record = preview !== null && typeof preview === 'object' && !Array.isArray(preview)
    ? (preview as Record<string, unknown>)
    : null
  const text = record && typeof record.preview === 'string'
    ? record.preview
    : typeof preview === 'string' ? preview : null
  const details = record ? Object.entries(record).filter(([key]) => key !== 'preview') : []
  const raw = record === null && text === null && preview !== null && preview !== undefined

  return (
    <div
      className="martis-action-preview mt-4 rounded-md p-3 text-sm"
      role="status"
      aria-live="polite"
      style={{
        background: 'var(--martis-surface-alt, var(--martis-surface))',
        border: '1px solid var(--martis-border)',
        color: 'var(--martis-text)',
      }}
    >
      <p className="mb-1 text-xs font-medium" style={{ color: 'var(--martis-text-muted)' }}>
        {t('preview_result', 'Preview (nothing was changed)')}
      </p>
      {text !== null && <p>{text}</p>}
      {details.length > 0 && (
        <dl className="mt-2 space-y-1">
          {details.map(([key, value]) => (
            <div key={key} className="flex gap-2">
              <dt className="font-medium" style={{ color: 'var(--martis-text-muted)' }}>{key}</dt>
              <dd className="min-w-0 break-words font-mono text-xs">
                {typeof value === 'string' ? value : JSON.stringify(value)}
              </dd>
            </div>
          ))}
        </dl>
      )}
      {raw && <pre className="mt-2 whitespace-pre-wrap font-mono text-xs">{JSON.stringify(preview, null, 2)}</pre>}
    </div>
  )
}
