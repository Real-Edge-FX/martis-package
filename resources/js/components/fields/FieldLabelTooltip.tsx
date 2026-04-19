import { QuestionIcon } from '@phosphor-icons/react'

/**
 * Small (?) icon rendered inline next to a field label. Hovering it shows
 * the tooltip text the resource author set via `->tooltip('...')` on the
 * field. HTML is allowed in the tooltip content (line breaks, bold, lists)
 * and rendered by {@link MartisTooltip} via the `data-pr-tooltip-html`
 * opt-in so authors can build multi-line hints without losing safety on
 * fields that stick to plain text.
 */
export function FieldLabelTooltip({ text, position = 'top' }: { text?: string | null; position?: 'top' | 'bottom' | 'left' | 'right' }) {
  if (!text) return null
  return (
    <span
      className="inline-flex cursor-help align-middle"
      style={{ color: 'var(--martis-text-muted)', marginLeft: '0.25rem' }}
      data-pr-tooltip={text}
      data-pr-tooltip-html="true"
      data-pr-position={position}
      aria-label={text}
    >
      <QuestionIcon size={14} weight="regular" />
    </span>
  )
}
