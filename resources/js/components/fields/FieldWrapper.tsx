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
 * The control a field's help describes: the element `htmlFor` names, else a
 * picker's trigger button (it holds no text input until it opens), else the
 * first data-entry element. A hidden input carries no value anyone reads.
 */
function findDescribedControl(root: HTMLElement, htmlFor?: string): HTMLElement | null {
  const byId = htmlFor ? (Array.from(root.querySelectorAll<HTMLElement>('[id]')).find((el) => el.id === htmlFor) ?? null) : null
  return (
    byId ??
    root.querySelector<HTMLElement>('button.martis-belongs-to-trigger') ??
    root.querySelector<HTMLElement>('input:not([type="hidden"]), textarea, select, [role="combobox"], [contenteditable="true"]')
  )
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
  // own) and leaves it when the help goes away. Runs when the help appears,
  // goes or moves to another control, not on every render of the form: the
  // observer follows the control that mounts later (a picker's trigger, a
  // lazily rendered input) or whose own `aria-describedby` a re-render
  // rewrites, and is idle while the user types.
  useEffect(() => {
    if (!showsHelp) return
    const root = wrapRef.current
    if (!root) return

    let bound: HTMLElement | null = null
    const unbind = () => {
      if (!bound) return
      const rest = (bound.getAttribute('aria-describedby') ?? '').split(/\s+/).filter((token) => token && token !== helpId)
      if (rest.length > 0) bound.setAttribute('aria-describedby', rest.join(' '))
      else bound.removeAttribute('aria-describedby')
      bound = null
    }
    const bind = () => {
      const control = findDescribedControl(root, htmlFor)
      if (control !== bound) {
        unbind()
        bound = control
      }
      if (!control) return
      const tokens = (control.getAttribute('aria-describedby') ?? '').split(/\s+/).filter(Boolean)
      // Only written when missing, so the observer never reacts to itself.
      if (!tokens.includes(helpId)) control.setAttribute('aria-describedby', [...tokens, helpId].join(' '))
    }

    bind()
    const observer = new MutationObserver(bind)
    observer.observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ['id', 'aria-describedby'] })
    return () => {
      observer.disconnect()
      unbind()
    }
  }, [showsHelp, htmlFor, helpId])

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
