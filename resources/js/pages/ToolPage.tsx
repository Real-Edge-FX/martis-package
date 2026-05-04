import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { WrenchIcon } from '@phosphor-icons/react'
import { api, ApiError } from '@/lib/api'
import { componentRegistry } from '@/lib/componentRegistry'
import { useToast } from '@/contexts/ToastContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useDynamicCrumb } from '@/contexts/DynamicCrumbContext'
import { MartisLoader } from '@/components/Loader'

interface ToolDescriptor {
  type: 'tool'
  name: string
  uriKey: string
  icon: string | null
  component: string | null
  menuSection: string | null
  meta: Record<string, unknown>
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
export function ToolPage({ descriptor: prefilled }: ToolPageProps = {}) {
  const { uriKey } = useParams<{ uriKey: string }>()
  const { t } = useTranslation('messages')
  const { addToast } = useToast()
  const [descriptor, setDescriptor] = useState<ToolDescriptor | null>(prefilled ?? null)
  const [error, setError] = useState<'not-found' | 'unknown-component' | null>(null)

  usePageTitle(descriptor?.name ?? t('tool_page_title', 'Tool'))
  // Publish the resolved tool name to the breadcrumb so the trail reads
  // "Home › Charts" instead of the literal route handle key "tool". The
  // hook resets to null on unmount; static i18n key is the fallback while
  // the descriptor is still loading.
  useDynamicCrumb(descriptor?.name)

  useEffect(() => {
    if (!uriKey || prefilled) return

    let cancelled = false
    setError(null)

    api
      .get<ToolDescriptor>(`/api/tools/${encodeURIComponent(uriKey)}`)
      .then((data) => {
        if (cancelled) return
        setDescriptor(data)
      })
      .catch((e: unknown) => {
        if (cancelled) return
        if (e instanceof ApiError && e.status === 404) {
          setError('not-found')
          return
        }
        const message = e instanceof ApiError ? e.errorSummary() : t('tool_load_failed', 'Could not load this tool.')
        addToast('error', message)
      })

    return () => {
      cancelled = true
    }
  }, [uriKey, prefilled, addToast, t])

  if (error === 'not-found') {
    return (
      <div className="martis-tool-empty" role="status">
        <WrenchIcon size={36} weight="duotone" />
        <h1>{t('tool_not_found_title', 'Tool not found')}</h1>
        <p>{t('tool_not_found_body', 'This tool does not exist or you do not have permission to see it.')}</p>
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
