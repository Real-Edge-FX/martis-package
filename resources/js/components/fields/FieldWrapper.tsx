import { useEffect, useId, useMemo, useRef, type ReactNode } from 'react'
import { WarningCircleIcon } from '@phosphor-icons/react'
import { FieldLabelTooltip } from './FieldLabelTooltip'
import { sanitizeMarkup } from '@/lib/sanitizeHtml'

interface FieldWrapperProps {
  /** HTML id of the wrapped input — binds the label via `htmlFor`. */
  htmlFor?: string
  /** Field label shown above the input. Omit to hide the label row entirely (e.g. toggle-only fields). */
  label?: string
  /** When true, appends a red asterisk to the label. */
  required?: boolean
  /** Optional tooltip text — renders the `?` icon next to the label with the Martis tooltip styling. HTML allowed. */
  tooltip?: string | null
  /**
   * Help text rendered beneath the input, as HTML (`Field::help()` documents
   * links, bold and code). It goes through the sanitiser's `markup` profile,
   * which keeps that markup and removes what would execute. Hidden while
   * `error` is set so both never stack.
   */
  help?: string | null
  /** Error message rendered beneath the input with a leading warning icon. */
  error?: string | null
  /** Optional extra class on the wrapper. */
  className?: string
  /** The input element — single child is usual, but multiple allowed (e.g. input + inline trigger). */
  children: ReactNode
}

/**
 * Canonical form-field wrapper — see `Catalog.html` `.input-wrap` spec.
 *
 * Stacks label + input + help/error vertically with 6px gap. Accepts a
 * single required asterisk in `--martis-danger`, a muted 12px help line,
 * and an inline error message with a Phosphor `warning-circle`. When
 * `error` is present, `help` is suppressed so the space stays tight.
 *
 * The wrapper is unopinionated about the input element itself: drop any
 * input / select / textarea / PrimeReact widget as children.
 */
export function FieldWrapper({
  htmlFor,
  label,
  required,
  tooltip,
  help,
  error,
  className,
  children,
}: FieldWrapperProps) {
  const helpHtml = useMemo(() => (help ? sanitizeMarkup(help) : ''), [help])
  const wrapRef = useRef<HTMLDivElement>(null)
  const helpId = useId()
  // The help line is shown only while there is no error.
  const showsHelp = !error && !!help

  // Tie the help to its control for assistive tech: the id joins the
  // control's own `aria-describedby` (the control may carry tokens of its
  // own) and leaves it when the help goes away.
  useEffect(() => {
    if (!showsHelp) return
    const root = wrapRef.current
    if (!root) return
    const byId = htmlFor ? (Array.from(root.querySelectorAll<HTMLElement>('[id]')).find((el) => el.id === htmlFor) ?? null) : null
    const control =
      byId ?? root.querySelector<HTMLElement>('input, textarea, select, [role="combobox"], [contenteditable="true"]')
    if (!control) return
    const tokens = (control.getAttribute('aria-describedby') ?? '').split(/\s+/).filter(Boolean)
    if (!tokens.includes(helpId)) control.setAttribute('aria-describedby', [...tokens, helpId].join(' '))
    return () => {
      const rest = (control.getAttribute('aria-describedby') ?? '').split(/\s+/).filter((token) => token && token !== helpId)
      if (rest.length > 0) control.setAttribute('aria-describedby', rest.join(' '))
      else control.removeAttribute('aria-describedby')
    }
  }, [showsHelp, htmlFor, helpId, children])

  return (
    <div ref={wrapRef} className={className ? `martis-input-wrap ${className}` : 'martis-input-wrap'}>
      {label && (
        <label htmlFor={htmlFor}>
          <span>{label}</span>
          {required && (
            <span className="martis-input-required" aria-hidden="true">
              *
            </span>
          )}
          <FieldLabelTooltip text={tooltip} />
        </label>
      )}
      {children}
      {error ? (
        <span className="martis-input-error" role="alert">
          <WarningCircleIcon size={12} weight="fill" aria-hidden="true" />
          {error}
        </span>
      ) : help ? (
        <span
          id={helpId}
          className="martis-input-help"
          dangerouslySetInnerHTML={{ __html: helpHtml }}
        />
      ) : null}
    </div>
  )
}
