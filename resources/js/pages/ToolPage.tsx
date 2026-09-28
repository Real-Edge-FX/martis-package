import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { WrenchIcon } from '@phosphor-icons/react'
import { componentRegistry } from '@/lib/componentRegistry'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useToolDescriptor } from '@/hooks/useToolDescriptor'
import { useDynamicCrumb } from '@/contexts/DynamicCrumbContext'
import { MartisLoader } from '@/components/Loader'
import { ToolHiddenState, ToolLockedState } from '@/components/tools/ToolStates'
import type { ToolDescriptor } from '@/types'

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
 * `useToolDescriptor` fetches `/api/tools/{uriKey}` to learn the tool's
 * identity, then the page looks up the React component bound to the
 * tool's `component()` key in `componentRegistry`.
 *
 * Wiring on the consumer side:
 *
 *   componentRegistry.register('tool:my-page', MyPageComponent)
 *
 * The component receives the `ToolDescriptor` as its single prop so it
 * can read the meta bag and react to authorisation changes.
 *
 * Failure modes:
 *  - 404 on the API → the "Tool not found" state (`ToolHiddenState`). Same
 *    UI for "this tool's canSee() denies you": the API does not distinguish.
 *  - A soft lock → the full-page lock state (`ToolLockedState`).
 *  - Unknown component key → a developer-friendly warning so the
 *    consumer knows they forgot the `componentRegistry.register(...)` call.
 */
/**
 * Thin wrapper that forces a clean unmount/remount of `ToolPageInner`
 * whenever the sidebar navigates to a different tool. Without this,
 * `ToolPageInner` stays mounted across a `uriKey` change (React Router
 * does not remount on param changes by default), so its resolved state
 * and any in-flight effects, including the previous tool's own
 * `setSearchParams` calls, kept leaking into the newly selected tool.
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
  const resolution = useToolDescriptor(uriKey, prefilled)
  const descriptor = resolution.status === 'ready' ? resolution.descriptor : null

  usePageTitle(descriptor?.name ?? t('tool_page_title', 'Tool'))
  // Publish the resolved tool name to the breadcrumb so the trail reads
  // "Home › Charts" instead of the literal route handle key "tool". The
  // hook resets to null on unmount; static i18n key is the fallback while
  // the descriptor is still loading. v1.10.3+: tools can override the
  // breadcrumb label independently from `name` via `Tool::withBreadcrumb`,
  // so the trail and the page heading no longer have to match.
  useDynamicCrumb(descriptor?.breadcrumb ?? descriptor?.name)

  if (resolution.status === 'hidden') return <ToolHiddenState />

  // v1.11.0+ soft-gate full-page state. The GateModal also opened on mount.
  if (resolution.status === 'locked') return <ToolLockedState payload={resolution.payload} />

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
