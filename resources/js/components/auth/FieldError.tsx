import { WarningCircleIcon } from '@phosphor-icons/react'

/**
 * Inline form error for the unauthenticated auth surfaces.
 *
 * Replaces the bare `<p className="martis-field-error">` pattern that
 * shipped through v1.8.0 — the danger-coloured text alone read as
 * "browser native validation" rather than Martis. v1.8.2 wraps the
 * message with the standard PhosphorIcons warning glyph and a tighter
 * vertical rhythm so the whole error reads as one coherent unit aligned
 * with the rest of the design system.
 *
 * Renders nothing when `message` is falsy so callers can mount it
 * unconditionally inside the field block.
 */
export function FieldError({ message }: { message: string | undefined | null }) {
  if (!message) return null
  return (
    <div className="martis-auth-field-error" role="alert">
      <WarningCircleIcon size={14} weight="fill" aria-hidden="true" />
      <span>{message}</span>
    </div>
  )
}
