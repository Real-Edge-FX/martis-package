import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CheckCircleIcon, DatabaseIcon, GearIcon, GlobeIcon, PulseIcon, WarningIcon } from '@phosphor-icons/react'
import { api } from '@/lib/api'
import { MartisLoader } from '@/components/Loader'

interface ToolDescriptor {
  name: string
  uriKey: string
  meta: Record<string, unknown>
}

interface CacheRow {
  type: string
  enabled: boolean
  ttl: number | null
  version: number
}

interface CacheStatusResponse {
  data: CacheRow[]
  meta: { master_enabled: boolean; types: string[] }
}

/**
 * Built-in demo component for the v0.10 Custom Tools primitive.
 *
 * Registered under the key `martis:tool:system-status-demo`. Any
 * application can ship its own `Tool` subclass that returns this
 * key from `component()` to get a quick "system overview" page
 * without writing React.
 *
 * Pulls live data from:
 *   - GET /api/cache (cache health snapshot)
 *   - navigator.* (frontend runtime info)
 *
 * The whole point of this component is to make the Tools primitive
 * visually demoable in the playground. Real apps will build their
 * own — this is the equivalent of `DefaultDashboard`.
 */
export function SystemStatusDemo({ tool }: { tool: ToolDescriptor }) {
  const { t } = useTranslation('messages')
  const [cache, setCache] = useState<CacheStatusResponse | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api
      .get<CacheStatusResponse>('/api/cache')
      .then(setCache)
      .catch(() => setError(t('cache_save_failed', 'Could not read cache state.')))
  }, [t])

  return (
    <div className="martis-tool-system-status">
      <header className="martis-tool-header">
        <div className="martis-tool-header-icon">
          <PulseIcon size={28} weight="duotone" />
        </div>
        <div>
          <h1>{tool.name}</h1>
          <p className="martis-tool-subtitle">
            {t('tool_system_status_subtitle', 'Live snapshot of the Martis admin runtime.')}
          </p>
        </div>
      </header>

      <section className="martis-tool-grid">
        <article className="martis-tool-card">
          <header>
            <DatabaseIcon size={20} weight="duotone" />
            <h2>{t('tool_system_status_cache_title', 'Cache layers')}</h2>
          </header>
          {!cache && !error && <MartisLoader />}
          {error && (
            <p className="martis-tool-error" role="alert">
              <WarningIcon size={16} /> {error}
            </p>
          )}
          {cache && (
            <ul className="martis-tool-rows">
              <li className="martis-tool-row martis-tool-row-master">
                <span>{t('tool_system_status_master_switch', 'Master switch')}</span>
                <span className={cache.meta.master_enabled ? 'is-on' : 'is-off'}>
                  {cache.meta.master_enabled
                    ? t('tool_system_status_state_on', 'Enabled')
                    : t('tool_system_status_state_off', 'Disabled')}
                </span>
              </li>
              {cache.data.map((row) => (
                <li key={row.type} className="martis-tool-row">
                  <span>{row.type}</span>
                  <span className={row.enabled ? 'is-on' : 'is-off'}>
                    {row.enabled ? <CheckCircleIcon size={14} /> : <WarningIcon size={14} />}
                    {row.enabled
                      ? t('tool_system_status_state_on', 'Enabled')
                      : t('tool_system_status_state_off', 'Disabled')}
                    <small>v{row.version}</small>
                  </span>
                </li>
              ))}
            </ul>
          )}
        </article>

        <article className="martis-tool-card">
          <header>
            <GlobeIcon size={20} weight="duotone" />
            <h2>{t('tool_system_status_runtime_title', 'Runtime')}</h2>
          </header>
          <ul className="martis-tool-rows">
            <li className="martis-tool-row">
              <span>{t('tool_system_status_runtime_path', 'Mount path')}</span>
              <span>
                <code>/{tool.uriKey}</code>
              </span>
            </li>
            <li className="martis-tool-row">
              <span>{t('tool_system_status_runtime_locale', 'Locale')}</span>
              <span>
                <code>{document.documentElement.lang || 'en'}</code>
              </span>
            </li>
            <li className="martis-tool-row">
              <span>{t('tool_system_status_runtime_theme', 'Theme')}</span>
              <span>
                <code>{document.documentElement.getAttribute('data-theme') || 'auto'}</code>
              </span>
            </li>
            <li className="martis-tool-row">
              <span>{t('tool_system_status_runtime_viewport', 'Viewport')}</span>
              <span>
                <code>
                  {window.innerWidth}x{window.innerHeight}
                </code>
              </span>
            </li>
          </ul>
        </article>

        <article className="martis-tool-card">
          <header>
            <GearIcon size={20} weight="duotone" />
            <h2>{t('tool_system_status_meta_title', 'Tool meta')}</h2>
          </header>
          {Object.keys(tool.meta).length === 0 ? (
            <p className="martis-tool-empty-meta">
              {t(
                'tool_system_status_meta_empty',
                'No meta. Pass key/value pairs to ->withMeta([...]) on the PHP side.',
              )}
            </p>
          ) : (
            <ul className="martis-tool-rows">
              {Object.entries(tool.meta).map(([k, v]) => (
                <li key={k} className="martis-tool-row">
                  <span>{k}</span>
                  <span>
                    <code>{typeof v === 'string' ? v : JSON.stringify(v)}</code>
                  </span>
                </li>
              ))}
            </ul>
          )}
        </article>
      </section>
    </div>
  )
}
