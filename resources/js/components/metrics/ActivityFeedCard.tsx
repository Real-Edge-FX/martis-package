import { ResourceIcon } from '@/components/ResourceIcon'

interface ActivityItem {
  /** Actor name displayed in bold at the start of the line. */
  actor: string
  /** Verb/description rendered in muted colour after the actor. */
  verb: string
  /** Target identifier rendered in mono font. Optional — e.g. commit sha. */
  target?: string
  /** Relative timestamp shown on the secondary line ("2m ago"). */
  time: string
  /** Phosphor icon name for the avatar square. */
  icon?: string
  /**
   * Accent colour for the icon tile. Accepts any CSS colour or token
   * (`var(--martis-chart-1)`). Falls back to `--martis-accent`.
   */
  color?: string
}

interface ActivityFeedCardProps {
  data: Record<string, unknown>
}

/**
 * F7-18 — ActivityFeed card. Renders a chronological stream of events:
 * coloured Phosphor tile + actor/verb/target line + mono timestamp.
 * Mirrors the `ActivityFeed` component from Dashboard.html.
 */
export function ActivityFeedCard({ data }: ActivityFeedCardProps) {
  const items = (data.items as ActivityItem[] | undefined) ?? []

  if (items.length === 0) {
    return <p className="martis-text-muted text-sm">—</p>
  }

  return (
    <div className="martis-activity-feed">
      {items.map((item, i) => {
        const colour = item.color ?? 'var(--martis-accent)'
        return (
          <div className="martis-activity-item" key={i}>
            <span
              className="martis-activity-icon"
              style={{
                background: `color-mix(in srgb, ${colour} 18%, transparent)`,
                color: colour,
              }}
              aria-hidden="true"
            >
              {item.icon && <ResourceIcon iconName={item.icon} size={14} />}
            </span>
            <div className="martis-activity-body">
              <div>
                <span className="martis-activity-actor">{item.actor}</span>{' '}
                <span className="martis-activity-verb">{item.verb}</span>
                {item.target && (
                  <>
                    {' '}
                    <span className="martis-activity-target">{item.target}</span>
                  </>
                )}
              </div>
              <div className="martis-activity-time">{item.time}</div>
            </div>
          </div>
        )
      })}
    </div>
  )
}
