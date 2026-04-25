import { useEffect, useRef, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { BellIcon, CheckCircleIcon, WarningIcon, WarningCircleIcon, InfoIcon, TrashIcon } from '@phosphor-icons/react'
import { api } from '@/lib/api'
import { config } from '@/lib/config'
import { ResourceIcon } from '@/components/ResourceIcon'

interface NotificationItem {
  id: string
  type: string
  title: string
  message: string | null
  level: 'info' | 'success' | 'warning' | 'danger'
  icon: string | null
  action_url: string | null
  action_label: string | null
  read_at: string | null
  created_at: string | null
}

interface ListResponse {
  data: NotificationItem[]
  meta: { total: number; unread: number }
}

interface UnreadCountResponse {
  unread: number
}

const POLL_DEFAULT = 60_000

/**
 * Bell button + dropdown panel for the in-app notifications subsystem.
 * Polls `/martis/api/notifications/unread-count` at the configured
 * interval (default 60s) so the badge stays in sync without a full
 * list fetch. The list itself is fetched only when the dropdown
 * opens — keeps the topbar lean.
 */
export function NotificationBell() {
  const { t } = useTranslation('messages')
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const wrapperRef = useRef<HTMLDivElement>(null)

  const enabled = config.notifications?.enabled !== false
  const pollInterval = config.notifications?.poll_interval ?? POLL_DEFAULT

  // Click-outside closes the panel.
  useEffect(() => {
    if (!open) return
    function onMouseDown(e: MouseEvent) {
      if (wrapperRef.current && !wrapperRef.current.contains(e.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', onMouseDown)
    return () => document.removeEventListener('mousedown', onMouseDown)
  }, [open])

  // Cheap badge polling — single COUNT query on the server.
  const unreadQuery = useQuery({
    queryKey: ['notifications', 'unread-count'],
    queryFn: () => api.get<UnreadCountResponse>('/api/notifications/unread-count'),
    refetchInterval: pollInterval > 0 ? pollInterval : false,
    enabled,
  })

  // Full list — fetched only when the dropdown opens.
  const listQuery = useQuery({
    queryKey: ['notifications', 'list'],
    queryFn: () => api.get<ListResponse>('/api/notifications'),
    enabled: enabled && open,
  })

  const unreadCount = unreadQuery.data?.unread ?? 0
  const items = listQuery.data?.data ?? []

  const markRead = useMutation({
    mutationFn: (id: string) => api.post(`/api/notifications/${id}/read`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['notifications'] })
    },
  })

  const markAllRead = useMutation({
    mutationFn: () => api.post('/api/notifications/read-all'),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['notifications'] })
    },
  })

  const dismiss = useMutation({
    mutationFn: (id: string) => api.delete(`/api/notifications/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['notifications'] })
    },
  })

  const clearAll = useMutation({
    mutationFn: () => api.delete('/api/notifications'),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['notifications'] })
    },
  })

  if (!enabled) return null

  return (
    <div ref={wrapperRef} className="martis-notif-wrap" style={{ position: 'relative' }}>
      <button
        type="button"
        className="martis-tb-icon-btn"
        onClick={() => setOpen((v) => !v)}
        aria-label={t('notifications', 'Notifications')}
        aria-haspopup="true"
        aria-expanded={open}
      >
        <BellIcon size={16} />
        {unreadCount > 0 && <span className="martis-notif-dot" />}
      </button>

      {open && (
        <div className="martis-notif-panel" role="dialog" aria-label={t('notifications', 'Notifications')}>
          <header className="martis-notif-head">
            <strong>{t('notifications', 'Notifications')}</strong>
            <div className="martis-notif-head-actions">
              {unreadCount > 0 && (
                <button
                  type="button"
                  className="martis-btn-ghost martis-btn-sm"
                  onClick={() => markAllRead.mutate()}
                  disabled={markAllRead.isPending}
                >
                  {t('notifications_mark_all_read', 'Mark all read')}
                </button>
              )}
              {items.length > 0 && (
                <button
                  type="button"
                  className="martis-btn-ghost martis-btn-sm"
                  onClick={() => clearAll.mutate()}
                  disabled={clearAll.isPending}
                  data-pr-tooltip={t('notifications_clear_all', 'Clear all')}
                  data-pr-position="top"
                  aria-label={t('notifications_clear_all', 'Clear all')}
                >
                  <TrashIcon size={13} weight="bold" />
                </button>
              )}
            </div>
          </header>

          <div className="martis-notif-list" role="list">
            {listQuery.isLoading && (
              <div className="martis-notif-empty">{t('loading', 'Loading…')}</div>
            )}

            {!listQuery.isLoading && items.length === 0 && (
              <div className="martis-notif-empty">
                {t('notifications_empty', 'No notifications yet.')}
              </div>
            )}

            {items.map((item) => {
              const isUnread = item.read_at === null
              const Icon = pickIcon(item)
              const onClick = () => {
                if (isUnread) markRead.mutate(item.id)
                if (item.action_url) {
                  setOpen(false)
                  if (item.action_url.startsWith('/')) {
                    window.location.href = item.action_url
                  } else {
                    window.open(item.action_url, '_blank', 'noopener,noreferrer')
                  }
                }
              }
              return (
                <article
                  key={item.id}
                  role="listitem"
                  className={`martis-notif-item ${isUnread ? 'is-unread' : ''}`}
                  onClick={onClick}
                  style={{ cursor: item.action_url ? 'pointer' : 'default' }}
                >
                  <span className={`martis-notif-icon is-${item.level}`} aria-hidden="true">
                    <Icon size={16} weight="fill" />
                  </span>
                  <div className="martis-notif-body">
                    <div className="martis-notif-title">{item.title}</div>
                    {item.message && <div className="martis-notif-message">{item.message}</div>}
                    <div className="martis-notif-meta">
                      {formatRelative(item.created_at, t)}
                      {item.action_label && item.action_url && (
                        <span className="martis-notif-action-label">{item.action_label}</span>
                      )}
                    </div>
                  </div>
                  <button
                    type="button"
                    className="martis-notif-dismiss"
                    aria-label={t('notifications_dismiss', 'Dismiss')}
                    onClick={(e) => {
                      e.stopPropagation()
                      dismiss.mutate(item.id)
                    }}
                  >
                    <TrashIcon size={12} weight="bold" />
                  </button>
                </article>
              )
            })}
          </div>
        </div>
      )}
    </div>
  )
}

function pickIcon(item: NotificationItem) {
  if (item.icon) {
    return ({ size, weight }: { size: number; weight?: string }) => (
      <ResourceIcon iconName={item.icon} size={size} weight={weight as never} />
    )
  }
  switch (item.level) {
    case 'success': return CheckCircleIcon
    case 'warning': return WarningIcon
    case 'danger': return WarningCircleIcon
    default: return InfoIcon
  }
}

function formatRelative(iso: string | null, t: (key: string, fallback: string) => string): string {
  if (!iso) return ''
  const created = new Date(iso).getTime()
  const now = Date.now()
  const diff = Math.max(0, Math.floor((now - created) / 1000))

  if (diff < 60) return t('time_just_now', 'just now')
  const min = Math.floor(diff / 60)
  if (min < 60) return `${min}m`
  const hr = Math.floor(min / 60)
  if (hr < 24) return `${hr}h`
  const days = Math.floor(hr / 24)
  if (days < 7) return `${days}d`
  return new Date(iso).toLocaleDateString()
}
