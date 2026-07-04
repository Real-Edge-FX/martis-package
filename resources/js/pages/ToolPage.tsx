import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { WrenchIcon } from '@phosphor-icons/react'
import { api, ApiError } from '@/lib/api'
import { componentRegistry } from '@/lib/componentRegistry'
import { useToast } from '@/contexts/ToastContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useDynamicCrumb } from '@/contexts/DynamicCrumbContext'
import { useGateOptional } from '@/contexts/GateContext'
import { MartisLoader } from '@/components/Loader'
import type { GateLock } from '@/types'

interface ToolDescriptor {
  type: 'tool'
  name: string
  /**
   * Optional breadcrumb override. When non-null, the panel shell uses
   * this label for the deepest crumb instead of `name`. Set on the
   * PHP side via `Tool::withBreadcrumb(...)` (v1.10.3+).
   */
  breadcrumb: string | null
  uriKey: string
  icon: string | null
  component: string | null
  menuSection: string | null
  /** Decorative pill (v1.11+). Set via `Tool::withBadge(...)`. */
  badge?: { text: string; tone: string } | null
  /** Soft-gate state (v1.11+). Non-null = the user is locked out. */
  lock?: GateLock | null
  meta: Record<string, unknown>
}

/** Locked-tool response shape from the route guard. */
interface LockedToolResponse {
  locked: true
  lock: GateLock
  tool: ToolDescriptor
}

interface ToolPageProps {
  /** Optional pre-resolved descriptor, useful when the menu already has the metadata. */
  descriptor?: ToolDescriptor
}

/**
 * Map a registry key like `tool:my-charts-tool` to the canonical
 * filename `MyChartsTool` the v1.9 auto-discovery entry expects under
 * `resources/js/martis-extensions/tools/`. Keys without a `tool:`
 * prefix or with an unknown shape fall back to literal `<Component>`
 * so the placeholder never renders an empty placeholder.
 */
function filenameHintFor(componentKey: string): string {
  const stripped = componentKey.startsWith('tool:') ? componentKey.slice('tool:'.length) : componentKey
  if (stripped === '' || /[^a-z0-9-]/.test(stripped)) return '<Component>'
  return stripped
    .split('-')
    .map((part) => (part.length > 0 ? part[0]!.toUpperCase() + part.slice(1) : part))
    .join('')
}

/**
 * Custom Tools shell (v0.10).
 *
 * Renders a free-form admin page registered through `Martis::tools([...])`.
 * The page fetches `/api/tools/{uriKey}` to learn the tool's identity,
 * then looks up the React component bound to the tool's `component()`
 * key in `componentRegistry`.
 *
 * Wiring on the consumer side:
 *
 *   componentRegistry.register('tool:my-page', MyPageComponent)
 *
 * The component receives the `ToolDescriptor` as its single prop so it
 * can read the meta bag and react to authorisation changes.
 *
 * Failure modes:
 *  - 404 on the API → renders "Tool not found" empty state. Same UI for
 *    "this tool's canSee() denies you" — the API does not distinguish.
 *  - Unknown component key → renders a developer-friendly warning so the
 *    consumer knows they forgot the `componentRegistry.register(...)` call.
 */
/**
 * Thin wrapper that forces a clean unmount/remount of `ToolPageInner`
 * whenever the sidebar navigates to a different tool. Without this,
 * `ToolPageInner` stays mounted across a `uriKey` change (React Router
 * does not remount on param changes by default), so its `descriptor`
 * state — and any in-flight effects, including the previous tool's own
 * `setSearchParams` calls — kept leaking into the newly selected tool.
 * Keying on `uriKey` is a no-op for the `prefilled` drawer-hosted path
 * (the key is harmless there since the drawer usually mounts one tool
 * at a time), so that caller keeps working unchanged.
 */
export function ToolPage(props: ToolPageProps = {}) {
  const { uriKey } = useParams<{ uriKey: string }>()
  return <ToolPageInner key={uriKey ?? '__none__'} {...props} />
}

function ToolPageInner({ descriptor: prefilled }: ToolPageProps = {}) {
  const { uriKey } = useParams<{ uriKey: string }>()
  const { t } = useTranslation('messages')
  const { addToast } = useToast()
  const gate = useGateOptional()
  const [descriptor, setDescriptor] = useState<ToolDescriptor | null>(prefilled ?? null)
  const [error, setError] = useState<'not-found' | 'unknown-component' | null>(null)
  // v1.11.0+ soft-gate full-page state.
  const [lockedPayload, setLockedPayload] = useState<LockedToolResponse | null>(null)

  usePageTitle(descriptor?.name ?? t('tool_page_title', 'Tool'))
  // Publish the resolved tool name to the breadcrumb so the trail reads
  // "Home › Charts" instead of the literal route handle key "tool". The
  // hook resets to null on unmount; static i18n key is the fallback while
  // the descriptor is still loading. v1.10.3+: tools can override the
  // breadcrumb label independently from `name` via `Tool::withBreadcrumb`,
  // so the trail and the page heading no longer have to match.
  useDynamicCrumb(descriptor?.breadcrumb ?? descriptor?.name)

  useEffect(() => {
    if (!uriKey || prefilled) return

    let cancelled = false
    const ac = new AbortController()
    setError(null)

    api
      .get<ToolDescriptor | LockedToolResponse>(`/api/tools/${encodeURIComponent(uriKey)}`, ac.signal)
      .then((data) => {
        if (cancelled) return
        if ('locked' in data && data.locked === true) {
          setLockedPayload(data)
          if (gate !== null) gate.open(data.lock)
          return
        }
        setDescriptor(data as ToolDescriptor)
      })
      .catch((e: unknown) => {
        if (cancelled) return
        // The remount wrapper (`ToolPage`) aborts this fetch whenever the
        // sidebar navigates away before it resolves. That is expected
        // teardown, not a user-facing failure — swallow it silently.
        if (ac.signal.aborted || (e instanceof DOMException && e.name === 'AbortError')) return
        if (e instanceof ApiError && e.status === 404) {
          setError('not-found')
          return
        }
        const message = e instanceof ApiError ? e.errorSummary() : t('tool_load_failed', 'Could not load this tool.')
        addToast('error', message)
      })

    return () => {
      cancelled = true
      ac.abort()
    }
  }, [uriKey, prefilled, addToast, t, gate])

  if (error === 'not-found') {
    return (
      <div className="martis-tool-empty" role="status">
        <WrenchIcon size={36} weight="duotone" />
        <h1>{t('tool_not_found_title', 'Tool not found')}</h1>
        <p>{t('tool_not_found_body', 'This tool does not exist or you do not have permission to see it.')}</p>
      </div>
    )
  }

  // v1.11.0+ soft-gate full-page state. The route guard answered
  // `{ locked: true, lock, tool }`; render the locked card with the
  // upsell CTA. The GateModal also opened automatically on mount.
  if (lockedPayload !== null) {
    const modal = lockedPayload.lock.modal
    return (
      <div
        className="flex flex-col items-center justify-center rounded-lg border border-dashed py-16 text-center"
        style={{
          backgroundColor: 'var(--martis-surface)',
          borderColor: 'var(--martis-border)',
          color: 'var(--martis-text-muted)',
        }}
      >
        <WrenchIcon size={36} weight="duotone" />
        <h1 className="mt-3 text-lg font-semibold" style={{ color: 'var(--martis-text)' }}>
          {modal?.title ?? t('gate.default_title', 'Locked feature')}
        </h1>
        <p className="mt-2 max-w-md text-sm">
          {modal?.message ?? t('gate.default_message', 'This tool is not available on your current plan.')}
        </p>
        {modal?.cta && (
          <a
            href={modal.cta.url}
            target={modal.cta.target ?? '_self'}
            rel={modal.cta.target === '_blank' ? 'noopener noreferrer' : undefined}
            className="mt-4 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium"
            style={{ backgroundColor: 'var(--martis-accent)', color: '#fff' }}
          >
            {modal.cta.label}
          </a>
        )}
      </div>
    )
  }

  if (!descriptor) {
    return <MartisLoader />
  }

  const componentKey = descriptor.component
  const Component = componentKey ? componentRegistry.resolve(componentKey) : null

  if (componentKey && !Component) {
    // Developer ergonomics — distinct from "tool denied". The PHP
    // side ships the tool but the consumer-extension bundle did not
    // register the matching React component. v1.9 message points at
    // the canonical bucket + build script (boot.ts is gone since
    // v1.8.19).
    return (
      <div className="martis-tool-missing-component" role="alert">
        <WrenchIcon size={36} weight="duotone" />
        <h1>{descriptor.name}</h1>
        <p>
          {t('tool_component_missing', {
            defaultValue:
              'No React component is registered for the key "{{key}}". Drop a default-exported component at resources/js/martis-extensions/tools/{{filenameHint}}.tsx and run `npm run build:extensions`.',
            key: componentKey,
            filenameHint: filenameHintFor(componentKey),
          })}
        </p>
      </div>
    )
  }

  if (!Component) {
    // Tool with no component() — shows just the header so consumers
    // can ship config-only tools (rare; mostly for debugging).
    return (
      <div className="martis-tool-page">
        <header className="martis-tool-header">
          <h1>{descriptor.name}</h1>
        </header>
      </div>
    )
  }

  // The registered React component is rendered with the descriptor as
  // its single prop. Consumer components can declare their props as
  // { tool: ToolDescriptor } to read the meta bag.
  const ConcreteComponent = Component as React.ComponentType<{ tool: ToolDescriptor }>
  return <ConcreteComponent tool={descriptor} />
}
