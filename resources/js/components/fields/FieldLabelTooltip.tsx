import { QuestionIcon } from '@phosphor-icons/react'
import { htmlTooltip } from '@/lib/htmlTooltip'

/**
 * Small (?) icon rendered inline next to a field label. Hovering it shows
 * the tooltip text the resource author set via `->tooltip('...')` on the
 * field. HTML is allowed in the tooltip content (line breaks, bold, lists)
 * and rendered by {@link MartisTooltip} through `htmlTooltip()`, which
 * registers the element as a trigger that may show (sanitised) HTML, so
 * authors can build multi-line hints without losing safety on fields that
 * stick to plain text.
 */
/**
 * The tooltip as plain text, for the icon's accessible name: a screen reader
 * reads an `aria-label` literally, so the markup of an HTML tooltip would be
 * spelt out tag by tag. Parsed into an inert document (nothing runs, nothing
 * loads); a line break becomes a space.
 */
function plainText(html: string): string {
  const body = new DOMParser().parseFromString(html, 'text/html').body
  body.querySelectorAll('br').forEach((br) => br.replaceWith(' '))
  return (body.textContent ?? '').replace(/\s+/g, ' ').trim()
}

export function FieldLabelTooltip({ text, position = 'top' }: { text?: string | null; position?: 'top' | 'bottom' | 'left' | 'right' }) {
  if (!text) return null
  return (
    <span
      className="inline-flex cursor-help align-middle"
      style={{ color: 'var(--martis-text-muted)', marginLeft: '0.25rem' }}
      {...htmlTooltip(text, position)}
      aria-label={plainText(text)}
    >
      <QuestionIcon size={14} weight="regular" />
    </span>
  )
}
