import { useState, useMemo } from 'react'
import { componentRegistry } from '@/lib/componentRegistry'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useTranslation } from 'react-i18next'

/**
 * Component Inspector — `/dev/components`.
 *
 * Developer-only catalogue of every component currently registered in
 * `componentRegistry`. Pick a key, feed a JSON payload, and see the
 * component render in isolation. Useful for visual QA on overrides
 * before exercising them through a real Resource page.
 *
 * Distinct from the BelongsTo / MorphTo **peek** popover (which
 * surfaces real records of a related model). This is a tooling
 * surface, end-users should never see it. Gated on
 * `config('martis.preview.enabled')` (default: true in `local`,
 * false elsewhere).
 *
 * The page intentionally renders inside a single component so it
 * can mount on a small route without the resource layout chrome.
 */
export function ComponentInspectorPage() {
  const { t } = useTranslation('navigation')
  usePageTitle('Component Inspector')

  const allKeys = useMemo(() => componentRegistry.keys().sort(), [])
  const [activeKey, setActiveKey] = useState<string | null>(allKeys[0] ?? null)
  const [payloadJson, setPayloadJson] = useState<string>(() =>
    JSON.stringify(samplePayloadFor(allKeys[0] ?? ''), null, 2),
  )
  const [parseError, setParseError] = useState<string | null>(null)

  // When the user picks a new key, reset the payload to that key's
  // default sample so they don't have to hand-author one. Edits to
  // the textarea are preserved within the same key.
  const onPickKey = (key: string) => {
    setActiveKey(key)
    setPayloadJson(JSON.stringify(samplePayloadFor(key), null, 2))
  }

  const parsedPayload = useMemo<Record<string, unknown>>(() => {
    try {
      const parsed = JSON.parse(payloadJson) as unknown
      setParseError(null)
      return (parsed && typeof parsed === 'object') ? (parsed as Record<string, unknown>) : {}
    } catch (e) {
      setParseError((e as Error).message)
      return {}
    }
  }, [payloadJson])

  // The registry stores components as `ComponentType<never>` to allow
  // any prop shape at registration time. Cast to a permissive shape
  // for the inspector, since the user supplies the payload.
  const Active = (activeKey ? componentRegistry.resolve(activeKey) : undefined) as
    | ((p: Record<string, unknown>) => JSX.Element)
    | undefined

  return (
    <div className="grid grid-cols-12 gap-4 p-6">
      <aside className="col-span-3 max-h-[80vh] overflow-y-auto rounded-martis-md border border-martis-border bg-martis-surface p-3">
        <h2 className="mb-2 text-martis-sm font-martis-semibold text-martis-text-muted">
          {t('inspector_keys', { defaultValue: 'Registered keys' })} ({allKeys.length})
        </h2>
        <ul className="flex flex-col gap-0.5">
          {allKeys.map((key) => (
            <li key={key}>
              <button
                type="button"
                onClick={() => onPickKey(key)}
                className={
                  'block w-full rounded px-2 py-1 text-left font-mono text-martis-xs ' +
                  (activeKey === key
                    ? 'bg-martis-accent text-martis-accent-contrast'
                    : 'text-martis-text hover:bg-martis-hover')
                }
              >
                {key}
              </button>
            </li>
          ))}
        </ul>
      </aside>

      <section className="col-span-9 flex flex-col gap-4">
        <header className="rounded-martis-md border border-martis-border bg-martis-surface p-4">
          <div className="text-martis-xs text-martis-text-muted">
            {t('inspector_active', { defaultValue: 'Active component' })}
          </div>
          <h1 className="font-mono text-martis-lg font-martis-semibold text-martis-text">
            {activeKey ?? '—'}
          </h1>
        </header>

        <div className="grid grid-cols-2 gap-4">
          <div className="rounded-martis-md border border-martis-border bg-martis-surface p-4">
            <div className="mb-2 text-martis-xs text-martis-text-muted">
              {t('inspector_payload', { defaultValue: 'Props (JSON)' })}
            </div>
            <textarea
              value={payloadJson}
              onChange={(e) => setPayloadJson(e.target.value)}
              spellCheck={false}
              className="h-72 w-full resize-y rounded-martis-sm border border-martis-border bg-martis-input-bg p-2 font-mono text-martis-xs text-martis-text"
            />
            {parseError && (
              <div className="mt-2 text-martis-xs text-martis-danger">
                {t('inspector_parse_error', { defaultValue: 'Invalid JSON' })}: {parseError}
              </div>
            )}
          </div>

          <div className="rounded-martis-md border border-martis-border bg-martis-surface p-4">
            <div className="mb-2 flex items-baseline justify-between gap-2">
              <span className="text-martis-xs text-martis-text-muted">
                {t('inspector_render', { defaultValue: 'Render' })}
              </span>
              {activeKey && (
                <button
                  type="button"
                  onClick={() => setPayloadJson(JSON.stringify(samplePayloadFor(activeKey), null, 2))}
                  className="text-martis-xs text-martis-accent hover:underline"
                >
                  {t('inspector_reset_payload', { defaultValue: 'Reset payload' })}
                </button>
              )}
            </div>
            {activeKey && (
              <p className="mb-2 text-martis-xs text-martis-text-muted">
                {payloadHintFor(activeKey)}
              </p>
            )}
            <div className="rounded-martis-sm border border-martis-border bg-martis-bg p-4">
              {Active
                ? (
                  <ErrorBoundary>
                    <Active {...parsedPayload} />
                  </ErrorBoundary>
                )
                : (
                  <span className="text-martis-text-muted text-martis-sm">
                    {t('inspector_empty', { defaultValue: 'Pick a key from the left.' })}
                  </span>
                )}
            </div>
          </div>
        </div>
      </section>
    </div>
  )
}

/**
 * Pick a sensible default payload for a registry key based on its
 * naming convention. The inspector uses this when the user selects
 * a new component, so most "click + see" flows skip the manual JSON
 * authoring step.
 *
 * Three cohorts are recognised:
 *
 *   - `field:display:*` and `field:input:*` → `{ field, value }`
 *   - `martis:drawer-*` → a partial `OverrideProps` shape with a
 *     stub schema + onClose / navigate handlers
 *   - everything else (custom action keys, tools, ad-hoc overrides)
 *     → an empty object plus a comment hint string. The user is
 *     expected to fill in the right shape from the component source.
 */
function samplePayloadFor(key: string): Record<string, unknown> {
  if (!key) return {}

  if (key.startsWith('field:display:') || key.startsWith('field:input:')) {
    // Take the trailing segment to seed a plausible field type.
    const tail = key.split(':').slice(-1)[0] ?? 'demo'
    return {
      field: { attribute: 'demo', label: 'Demo', type: tail },
      value: 'Sample value',
    }
  }

  if (key.startsWith('martis:drawer-')) {
    // Minimal OverrideProps stub. Real drawers also receive `record`
    // / `recordId` / a list of callbacks; `() => undefined` keeps the
    // panel functional under a sample render.
    return {
      schema: { uriKey: 'sample', label: 'Sample', singularLabel: 'Sample', fields: [], fieldsForDetail: [] },
      resource: 'sample',
      params: {},
      record: null,
      recordId: null,
      // Inspector renders synthetic data, so the navigation / lifecycle
      // hooks are stubs. No-op them so the component doesn't crash on
      // user interaction.
      navigate: () => undefined,
      onClose: () => undefined,
      onCreated: () => undefined,
      onUpdated: () => undefined,
      onDeleted: () => undefined,
      onEdit: () => undefined,
      onView: () => undefined,
      addToast: () => undefined,
    }
  }

  // Generic fallback: empty object. The user reads the component's
  // source and fills in whatever shape it needs.
  return {}
}

function payloadHintFor(key: string): string | null {
  if (!key) return null
  if (key.startsWith('field:display:')) {
    return 'Field display components receive `{ field, value, resourceKey?, context? }`.'
  }
  if (key.startsWith('field:input:')) {
    return 'Field input components receive `{ field, value, onChange, error? }`.'
  }
  if (key.startsWith('martis:drawer-')) {
    return 'Drawer overrides receive an `OverrideProps` payload — see customization/overrides.md.'
  }
  return 'Custom key — inspect the registered component\'s source for its prop shape.'
}

/**
 * Local error boundary so a misshapen payload renders a readable
 * error instead of crashing the whole inspector.
 */
import { Component, type ReactNode } from 'react'

class ErrorBoundary extends Component<{ children: ReactNode }, { error: Error | null }> {
  state = { error: null as Error | null }

  static getDerivedStateFromError(error: Error) {
    return { error }
  }

  componentDidUpdate(prevProps: { children: ReactNode }) {
    if (prevProps.children !== this.props.children && this.state.error) {
      this.setState({ error: null })
    }
  }

  render() {
    if (this.state.error) {
      return (
        <pre className="whitespace-pre-wrap rounded-martis-sm border border-martis-danger bg-martis-danger-bg p-3 text-martis-xs text-martis-danger">
          {String(this.state.error.message)}
        </pre>
      )
    }
    return <>{this.props.children}</>
  }
}
