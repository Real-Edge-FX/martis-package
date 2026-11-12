import { useId } from 'react'
import { WarningCircleIcon } from '@phosphor-icons/react'

interface FieldErrorProps {
  /** The validation message. Nothing renders while it is empty. */
  error?: string | null
  /** Optional element id, for `aria-describedby` on the input (see `useFieldError`). */
  id?: string
}

/**
 * The inline validation message of a form field: the warning icon and the
 * message in the `martis-input-error` style, announced as an alert.
 *
 * `FieldWrapper` draws its `error` prop with it, and a custom input that
 * draws its own error uses it to look like the rest of the form.
 */
export function FieldError({ error, id }: FieldErrorProps) {
  if (!error) return null
  return (
    <span id={id} className="martis-input-error" role="alert">
      <WarningCircleIcon size={12} weight="fill" aria-hidden="true" />
      {error}
    </span>
  )
}

export interface UseFieldErrorResult {
  hasError: boolean
  /** The message, or `''` when there is none. */
  message: string
  /** Id to give `<FieldError id={errorId} />`. Stable for the component's lifetime. */
  errorId: string
  /** Spread onto the input: `aria-invalid` and `aria-describedby` while there is an error, nothing otherwise. */
  invalidProps: { 'aria-invalid'?: true; 'aria-describedby'?: string }
}

/**
 * State for a custom input that shows its validation error.
 *
 * ```tsx
 * const { error: message } = props            // the FieldInputProps `error`
 * const { errorId, invalidProps } = useFieldError(message)
 * return (
 *   <>
 *     <input {...invalidProps} />
 *     <FieldError error={message} id={errorId} />
 *   </>
 * )
 * ```
 */
export function useFieldError(error?: string | null): UseFieldErrorResult {
  const errorId = useId()
  const message = typeof error === 'string' ? error : ''
  const hasError = message !== ''

  return {
    hasError,
    message,
    errorId,
    invalidProps: hasError ? { 'aria-invalid': true, 'aria-describedby': errorId } : {},
  }
}
