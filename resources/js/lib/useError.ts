import { useState, useCallback } from 'react'
import { ApiError } from '@/lib/api'

export interface ErrorState {
  /** General error message (for toast display). */
  message: string | null
  /** Per-field validation errors (for inline display). */
  fieldErrors: Record<string, string>
  /** Raw ApiError, if the error originated from an API call. */
  apiError: ApiError | null
}

const initialState: ErrorState = {
  message: null,
  fieldErrors: {},
  apiError: null,
}

/**
 * useError — centralised error state management for form and page components.
 *
 * Parses ApiError automatically into both general and field-level error state.
 * Provides a `clearErrors` helper to reset state after user interaction.
 *
 * @example
 * ```tsx
 * const { errors, setError, clearErrors } = useError()
 *
 * async function handleSubmit() {
 *   try {
 *     await api.post('/api/posts', data)
 *   } catch (err) {
 *     setError(err)
 *   }
 * }
 *
 * // In JSX:
 * {errors.message && <p className="text-destructive">{errors.message}</p>}
 * {errors.fieldErrors.email && <p className="text-destructive">{errors.fieldErrors.email}</p>}
 * ```
 */
export function useError() {
  const [errors, setErrors] = useState<ErrorState>(initialState)

  /**
   * Parse any error into the unified ErrorState.
   * Handles: ApiError (with field errors), Error, and unknown values.
   */
  const setError = useCallback((err: unknown): void => {
    if (err instanceof ApiError) {
      setErrors({
        message: err.message,
        fieldErrors: err.errorsByField(),
        apiError: err,
      })
      return
    }

    if (err instanceof Error) {
      setErrors({ message: err.message, fieldErrors: {}, apiError: null })
      return
    }

    setErrors({
      message: typeof err === 'string' ? err : 'An unexpected error occurred.',
      fieldErrors: {},
      apiError: null,
    })
  }, [])

  /**
   * Clear all error state (e.g. when user starts editing a field).
   */
  const clearErrors = useCallback((): void => {
    setErrors(initialState)
  }, [])

  /**
   * Clear a specific field error.
   */
  const clearFieldError = useCallback((field: string): void => {
    setErrors((prev) => {
      const next = { ...prev.fieldErrors }
      delete next[field]
      return { ...prev, fieldErrors: next }
    })
  }, [])

  /**
   * True if there are any errors (general or field-level).
   */
  const hasErrors = errors.message !== null || Object.keys(errors.fieldErrors).length > 0

  return { errors, setError, clearErrors, clearFieldError, hasErrors }
}
