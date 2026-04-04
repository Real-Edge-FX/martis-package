import type { FieldDisplayProps, FieldInputProps } from './types'

// ---------------------------------------------------------------------------
// Status state resolution
// ---------------------------------------------------------------------------

type StatusState = 'loading' | 'failed' | 'finished'

function resolveStatusState(
  value: unknown,
  loadingWhen: string[],
  failedWhen: string[],
): StatusState {
  const str = String(value ?? '')
  if (loadingWhen.includes(str)) return 'loading'
  if (failedWhen.includes(str)) return 'failed'
  return 'finished'
}

// ---------------------------------------------------------------------------
// Loading spinner (pure CSS — no external icon dependency)
// ---------------------------------------------------------------------------

function LoadingSpinner() {
  return (
    <svg
      className="animate-spin"
      style={{ width: '0.875rem', height: '0.875rem', color: 'var(--martis-accent, #6366f1)' }}
      viewBox="0 0 24 24"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
    >
      <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="3" strokeOpacity="0.25" />
      <path
        d="M12 2a10 10 0 0 1 10 10"
        stroke="currentColor"
        strokeWidth="3"
        strokeLinecap="round"
      />
    </svg>
  )
}

// ---------------------------------------------------------------------------
// Display
// ---------------------------------------------------------------------------

export function StatusFieldDisplay({ field, value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="martis-text-muted">—</span>
  }

  const loadingWhen = ((field as Record<string, unknown>).loadingWhen as string[] | undefined) ?? []
  const failedWhen = ((field as Record<string, unknown>).failedWhen as string[] | undefined) ?? []

  const state = resolveStatusState(value, loadingWhen, failedWhen)
  const label = String(value)

  if (state === 'loading') {
    return (
      <span className="inline-flex items-center gap-1.5">
        <LoadingSpinner />
        <span className="text-sm" style={{ color: 'var(--martis-text-muted)' }}>
          {label}
        </span>
      </span>
    )
  }

  if (state === 'failed') {
    return (
      <span className="inline-flex items-center gap-1.5">
        <span
          className="inline-flex items-center justify-center rounded-full text-xs font-bold"
          style={{
            width: '1rem',
            height: '1rem',
            backgroundColor: '#fee2e2',
            color: '#b91c1c',
          }}
          aria-label="Failed"
        >
          ✕
        </span>
        <span className="text-sm" style={{ color: '#b91c1c' }}>
          {label}
        </span>
      </span>
    )
  }

  // Finished / success state
  return (
    <span className="inline-flex items-center gap-1.5">
      <span
        className="inline-flex items-center justify-center rounded-full text-xs"
        style={{
          width: '1rem',
          height: '1rem',
          backgroundColor: '#dcfce7',
          color: '#15803d',
          fontWeight: 700,
        }}
        aria-label="Done"
      >
        ✓
      </span>
      <span className="text-sm" style={{ color: 'var(--martis-text)' }}>
        {label}
      </span>
    </span>
  )
}

// ---------------------------------------------------------------------------
// Input — Status is primarily display-only.
// If shown in form context, renders read-only display. Not a form input.
// Developer should use Select field to edit status values in forms.
// ---------------------------------------------------------------------------

export function StatusFieldInput({ field, value }: FieldInputProps) {
  return (
    <div className="flex items-center gap-2">
      <StatusFieldDisplay field={field} value={value} />
      <span className="text-xs" style={{ color: 'var(--martis-text-muted)' }}>
        (read-only)
      </span>
    </div>
  )
}
