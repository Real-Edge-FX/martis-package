import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ArrowsClockwiseIcon, BroomIcon, DatabaseIcon, ToggleLeftIcon, ToggleRightIcon, TrashIcon } from '@phosphor-icons/react'
import { api, ApiError } from '@/lib/api'
import { useToast } from '@/contexts/ToastContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { MartisLoader } from '@/components/Loader'

type CacheType = 'metrics' | 'navigation' | 'dashboards' | 'schema'

interface CacheRow {
  type: CacheType
  enabled: boolean
  ttl: number | null
  config_enabled: boolean
  runtime_override: boolean | null
  version: number
  cleared_at: string | null
}

interface CacheStatusResponse {
  data: CacheRow[]
  meta: {
    master_enabled: boolean
    types: CacheType[]
  }
}

/**
 * "Sistema → Cache" admin page (Task 17 ⭐).
 *
 * Shows every Martis cache layer (metrics, navigation, dashboards,
 * schema) with its effective state and three control levers:
 *  • Clear (bumps the per-type version key, atomic invalidation).
 *  • Disable / Enable (runtime override, persisted in cache).
 *  • Reset to config (drops the override, falls back to config).
 *
 * Visibility is gated by the `manage-martis-cache` Gate on the server —
 * an unauthorized response renders the empty state and the user is
 * redirected by the global API error handler.
 */
export function CacheAdminPage() {
  const { t } = useTranslation('messages')
  const { addToast } = useToast()
  const [rows, setRows] = useState<CacheRow[] | null>(null)
  const [masterEnabled, setMasterEnabled] = useState(true)
  const [busy, setBusy] = useState<CacheType | 'all' | null>(null)

  usePageTitle(t('cache_admin_title', 'System cache'))

  const refresh = useCallback(async () => {
    try {
      const res = await api.get<CacheStatusResponse>('/api/cache')
      setRows(res.data)
      setMasterEnabled(res.meta.master_enabled)
    } catch (e) {
      const message = e instanceof ApiError ? e.errorSummary() : t('cache_save_failed', 'Could not update cache state.')
      addToast('error', message)
    }
  }, [t, addToast])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const post = useCallback(
    async (path: string, type: CacheType | 'all', body?: { type?: CacheType }) => {
      setBusy(type)
      try {
        const res = await api.post<CacheStatusResponse>(path, body ?? {})
        setRows(res.data)
      } catch (e) {
        const message = e instanceof ApiError ? e.errorSummary() : t('cache_save_failed', 'Could not update cache state.')
        addToast('error', message)
      } finally {
        setBusy(null)
      }
    },
    [t, addToast],
  )

  const onClear = useCallback(
    (type: CacheType) => {
      const message = t('cache_clear_confirm', { type, defaultValue: `Clear the ${type} cache now?` })
      if (!window.confirm(message)) return
      void post('/api/cache/clear', type, { type })
    },
    [post, t],
  )

  const onClearAll = useCallback(() => {
    if (!window.confirm(t('cache_clear_all_confirm', 'Clear every Martis cache?'))) return
    void post('/api/cache/clear', 'all')
  }, [post, t])

  const onToggle = useCallback(
    (row: CacheRow) => {
      const path = row.enabled ? '/api/cache/disable' : '/api/cache/enable'
      void post(path, row.type, { type: row.type })
    },
    [post],
  )

  const onResetOverride = useCallback(
    (type: CacheType) => {
      void post('/api/cache/reset-override', type, { type })
    },
    [post],
  )

  if (rows === null) {
    return (
      <div className="p-6">
        <MartisLoader message={t('cache_loading', 'Loading cache state…')} />
      </div>
    )
  }

  return (
    <div className="martis-cache-admin">
      <header className="martis-cache-admin__head">
        <div>
          <h1 className="text-xl font-semibold" style={{ color: 'var(--martis-text)' }}>
            {t('cache_admin_title', 'System cache')}
          </h1>
          <p className="text-sm" style={{ color: 'var(--martis-text-muted)' }}>
            {t('cache_admin_subtitle', 'Inspect, clear and toggle Martis cache layers without redeploying.')}
          </p>
        </div>
        <button
          type="button"
          className="martis-btn martis-btn-primary"
          onClick={onClearAll}
          disabled={busy !== null}
          data-pr-tooltip={t('cache_clear_all_tip', '<strong>Clear all</strong><br>Bumps the version key of every layer at once. Every cached entry becomes orphaned and the next request recomputes.')}
          data-pr-tooltip-html="true"
          data-pr-position="left"
        >
          <BroomIcon size={14} weight="bold" />
          <span>{t('cache_clear_all', 'Clear all')}</span>
        </button>
      </header>

      <div
        className={`martis-cache-banner ${masterEnabled ? 'is-on' : 'is-off'}`}
        role="status"
        aria-live="polite"
      >
        <DatabaseIcon size={16} weight="fill" />
        <span>
          {masterEnabled
            ? t('cache_master_on', 'Master cache is ON')
            : t('cache_master_off', 'Master cache is OFF — every layer is bypassed.')}
        </span>
      </div>

      <div className="martis-card martis-cache-table-wrap">
        <table className="martis-cache-table">
          <thead>
            <tr>
              <th
                data-pr-tooltip={t('cache_type_tip', '<strong>Type</strong><br>Layer identifier — <code>metrics</code>, <code>navigation</code>, <code>dashboards</code>, <code>schema</code>, or any custom layer registered via <code>MartisCache::extend()</code>.')}
                data-pr-tooltip-html="true"
                data-pr-position="top"
              >
                {t('cache_type', 'Type')}
              </th>
              <th
                data-pr-tooltip={t('cache_effective_tip', '<strong>Effective</strong><br>Live state taking the master switch, config and runtime override into account.<br><br>This is what is actually applied right now.')}
                data-pr-tooltip-html="true"
                data-pr-position="top"
              >
                {t('cache_effective', 'Effective')}
              </th>
              <th
                data-pr-tooltip={t('cache_ttl_tip', '<strong>TTL</strong><br>How long an entry stays cached before it expires.<br><br><em>No expiration</em> means it lives until cleared — version-key invalidation handles the wipe.')}
                data-pr-tooltip-html="true"
                data-pr-position="top"
              >
                {t('cache_ttl', 'TTL')}
              </th>
              <th
                data-pr-tooltip={t('cache_config_tip', '<strong>Config</strong><br>Static value declared in <code>config/martis.php</code> (or its env override).<br><br>Ignores runtime toggles.')}
                data-pr-tooltip-html="true"
                data-pr-position="top"
              >
                {t('cache_config', 'Config')}
              </th>
              <th
                data-pr-tooltip={t('cache_runtime_tip', '<strong>Runtime</strong><br>Persistent override that survives restarts.<br><br><strong>Inherit</strong> — no override, the config wins.<br><strong>Forced ON / OFF</strong> — beats the config until reset.')}
                data-pr-tooltip-html="true"
                data-pr-position="top"
              >
                {t('cache_runtime', 'Runtime')}
              </th>
              <th
                data-pr-tooltip={t('cache_version_tip', '<strong>Version</strong><br>Per-layer counter included in every cache key. Clearing the layer increments it and orphans all old keys atomically — works on any cache backend.')}
                data-pr-tooltip-html="true"
                data-pr-position="top"
              >
                {t('cache_version', 'Version')}
              </th>
              <th
                data-pr-tooltip={t('cache_cleared_at_tip', '<strong>Last cleared</strong><br>ISO timestamp of the last clear operation for this layer.<br><br>A dash means it has never been cleared since the application started.')}
                data-pr-tooltip-html="true"
                data-pr-position="top"
              >
                {t('cache_cleared_at', 'Last cleared')}
              </th>
              <th aria-label="actions" />
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const isBusy = busy === row.type || busy === 'all'
              return (
                <tr key={row.type} data-state={row.enabled ? 'on' : 'off'}>
                  <td>
                    <strong>{row.type}</strong>
                  </td>
                  <td>
                    <span className={`martis-cache-pill ${row.enabled ? 'is-on' : 'is-off'}`}>
                      {row.enabled ? t('cache_status_enabled', 'Enabled') : t('cache_status_disabled', 'Disabled')}
                    </span>
                  </td>
                  <td>
                    {row.ttl === null
                      ? t('cache_ttl_forever', 'No expiration')
                      : `${row.ttl}m`}
                  </td>
                  <td>{row.config_enabled ? t('cache_status_enabled', 'Enabled') : t('cache_status_disabled', 'Disabled')}</td>
                  <td>
                    {row.runtime_override === null && (
                      <span
                        className="martis-cache-runtime is-default"
                        data-pr-tooltip={t('cache_runtime_default_tip', '<strong>Inherit</strong><br>No runtime override — the effective state matches the config.')}
                        data-pr-tooltip-html="true"
                        data-pr-position="top"
                      >
                        {t('cache_runtime_default', 'Inherit')}
                      </span>
                    )}
                    {row.runtime_override === true && (
                      <span
                        className="martis-cache-runtime is-on"
                        data-pr-tooltip={t('cache_runtime_enabled_tip', '<strong>Forced ON</strong><br>Runtime override forces this layer ON regardless of the config.<br><br>Reset the override to fall back to config.')}
                        data-pr-tooltip-html="true"
                        data-pr-position="top"
                      >
                        {t('cache_runtime_enabled', 'Forced ON')}
                      </span>
                    )}
                    {row.runtime_override === false && (
                      <span
                        className="martis-cache-runtime is-off"
                        data-pr-tooltip={t('cache_runtime_disabled_tip', '<strong>Forced OFF</strong><br>Runtime override forces this layer OFF regardless of the config.<br><br>Reset the override to fall back to config.')}
                        data-pr-tooltip-html="true"
                        data-pr-position="top"
                      >
                        {t('cache_runtime_disabled', 'Forced OFF')}
                      </span>
                    )}
                  </td>
                  <td>
                    <code>v{row.version}</code>
                  </td>
                  <td className="martis-cache-cleared-at">{row.cleared_at ?? '—'}</td>
                  <td className="martis-cache-actions-cell">
                    <div className="martis-cache-actions">
                      <button
                        type="button"
                        className={`martis-cache-toggle ${row.enabled ? 'is-on' : 'is-off'}`}
                        aria-label={row.enabled ? t('cache_disable', 'Disable') : t('cache_enable', 'Enable')}
                        aria-pressed={row.enabled}
                        data-pr-tooltip={
                          row.enabled
                            ? t('cache_toggle_off_tip', '<strong>Disable</strong><br>Persistently disable this layer at runtime.<br><br>Bypasses the config without redeploy. Survives restarts.')
                            : t('cache_toggle_on_tip', '<strong>Enable</strong><br>Force this layer ON at runtime, overriding the config.<br><br>Useful when a config-disabled layer needs to be re-enabled live.')
                        }
                        data-pr-tooltip-html="true"
                        data-pr-position="left"
                        onClick={() => onToggle(row)}
                        disabled={isBusy}
                      >
                        {row.enabled ? <ToggleRightIcon size={18} weight="fill" /> : <ToggleLeftIcon size={18} weight="fill" />}
                      </button>
                      {row.runtime_override !== null && (
                        <button
                          type="button"
                          className="martis-btn-ghost martis-btn-sm"
                          aria-label={t('cache_inherit_config', 'Reset to config')}
                          data-pr-tooltip={t('cache_reset_tip', '<strong>Reset to config</strong><br>Drop the runtime override for this layer.<br><br>Effective state falls back to whatever the config file says.')}
                          data-pr-tooltip-html="true"
                          data-pr-position="left"
                          onClick={() => onResetOverride(row.type)}
                          disabled={isBusy}
                        >
                          <ArrowsClockwiseIcon size={14} weight="bold" />
                        </button>
                      )}
                      <button
                        type="button"
                        className="martis-btn-ghost martis-btn-sm"
                        aria-label={t('cache_clear', 'Clear')}
                        data-pr-tooltip={t('cache_clear_tip', '<strong>Clear</strong><br>Invalidate every entry in this layer right now.<br><br>Bumps the version key — atomic and O(1) on any cache backend.')}
                        data-pr-tooltip-html="true"
                        data-pr-position="left"
                        onClick={() => onClear(row.type)}
                        disabled={isBusy}
                      >
                        <TrashIcon size={14} weight="bold" />
                      </button>
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}
