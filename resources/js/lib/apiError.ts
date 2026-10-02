// The error `api` throws for a failed request, and the validation-error shape
// it carries. Kept in a module of its own (no imports) so `apiPath` can refuse
// a value with the same error `request()` answers a dot segment with, without
// a cycle through `api.ts`. `api.ts` re-exports both, so every existing import
// of `ApiError` / `ValidationError` from `@/lib/api` is unchanged.

export interface ValidationError {
  field: string
  message: string
  code: string
}

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly errors?: ValidationError[],
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /** Whether the server denied the action on authorization grounds (HTTP 403). */
  isForbidden(): boolean {
    return this.status === 403
  }

  /** Group errors by field name for inline display. */
  errorsByField(): Record<string, string> {
    const result: Record<string, string> = {}
    if (this.errors) {
      for (const err of this.errors) {
        if (err.field && !result[err.field]) {
          result[err.field] = err.message
        }
      }
    }
    return result
  }

  /** Get all error messages as a single string for toast display. */
  errorSummary(): string {
    if (!this.errors || this.errors.length === 0) return this.message
    return this.errors.map(e => e.message).join('. ')
  }
}
