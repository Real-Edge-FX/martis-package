import { API_BASE_URL } from "@/lib/config"

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

function getCsrfToken(): string {
  // Prefer XSRF-TOKEN cookie — always fresh (updated on every Laravel response)
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  if (match) return decodeURIComponent(match[1])
  // Fallback to meta tag (may be stale after session regeneration)
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

/**
 * Normalize errors from either format:
 * - Martis format: [{field, message, code}]
 * - Laravel format: {email: ["msg1", "msg2"]}
 */
function normalizeErrors(raw: unknown): ValidationError[] | undefined {
  if (!raw) return undefined
  if (Array.isArray(raw)) {
    // Already Martis format (or empty)
    return raw as ValidationError[]
  }
  if (typeof raw === 'object') {
    // Laravel format: Record<string, string[]>
    const result: ValidationError[] = []
    for (const [field, messages] of Object.entries(raw as Record<string, unknown>)) {
      if (Array.isArray(messages)) {
        for (const msg of messages) {
          result.push({ field, message: String(msg), code: 'invalid' })
        }
      }
    }
    return result.length > 0 ? result : undefined
  }
  return undefined
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const csrfToken = getCsrfToken()

  const res = await fetch(`${API_BASE_URL}${path}`, {
    method,
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-XSRF-TOKEN': csrfToken,
    },
    ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
  })

  if (res.status === 204) return undefined as unknown as T

  const json: unknown = await res.json()

  if (!res.ok) {
    const err = json as { message?: string; errors?: unknown }
    throw new ApiError(res.status, err.message ?? 'Request failed', normalizeErrors(err.errors))
  }

  return json as T
}

/** Marker interface for multiple-file field values. */
interface MultiFileValue {
  __multiple: true
  items: Array<{
    id: string
    file?: File
    existing?: { path: string; url: string; name: string; thumbnailUrl?: string }
    previewUrl?: string
  }>
}

function isMultiFileValue(v: unknown): v is MultiFileValue {
  return v !== null && typeof v === 'object' && '__multiple' in (v as Record<string, unknown>)
}

/**
 * Check if form values contain any File objects that require multipart upload.
 */
export function hasFileValues(values: Record<string, unknown>): boolean {
  return Object.values(values).some((v) => {
    if (v instanceof File) return true
    if (isMultiFileValue(v)) {
      return v.items.some((item) => item.file instanceof File)
    }
    return false
  })
}

/**
 * Build a FormData from key-value pairs for multipart upload.
 * Handles File objects, null (skip), scalar values, and multiple-file fields.
 */
function buildFormData(values: Record<string, unknown>, methodOverride?: string): FormData {
  const fd = new FormData()
  if (methodOverride) {
    fd.append('_method', methodOverride)
  }
  Object.entries(values).forEach(([key, val]) => {
    if (val instanceof File) {
      fd.append(key, val)
    } else if (isMultiFileValue(val)) {
      // Multiple file field: separate new uploads from existing paths to keep
      let fileIndex = 0
      val.items.forEach((item) => {
        if (item.file instanceof File) {
          fd.append(`${key}[${fileIndex}]`, item.file)
          fileIndex++
        } else if (item.existing) {
          fd.append(`${key}_keep[]`, item.existing.path)
        }
      })
      // If no items at all, signal empty array
      if (val.items.length === 0) {
        fd.append(`${key}_keep`, '')
      }
    } else if (val === null || val === undefined) {
      // Send empty string so Laravel sees the field (allows clearing)
      fd.append(key, '')
    } else {
      fd.append(key, String(val))
    }
  })
  return fd
}

/**
 * Submit form data as multipart/form-data.
 * For PUT/PATCH methods, uses POST with _method spoofing (Laravel convention).
 */
async function uploadRequest<T>(method: string, path: string, values: Record<string, unknown>): Promise<T> {
  const csrfToken = getCsrfToken()
  const actualMethod = method === 'PUT' || method === 'PATCH' ? 'POST' : method
  const methodOverride = method === 'PUT' || method === 'PATCH' ? method : undefined

  const fd = buildFormData(values, methodOverride)

  const res = await fetch(`${API_BASE_URL}${path}`, {
    method: actualMethod,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'X-XSRF-TOKEN': csrfToken,
      // Do NOT set Content-Type — browser sets it with boundary for multipart
    },
    body: fd,
  })

  if (res.status === 204) return undefined as unknown as T

  const json: unknown = await res.json()

  if (!res.ok) {
    const err = json as { message?: string; errors?: unknown }
    throw new ApiError(res.status, err.message ?? 'Request failed', normalizeErrors(err.errors))
  }

  return json as T
}

export const api = {
  get: <T>(path: string) => request<T>('GET', path),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body),
  delete: <T>(path: string) => request<T>('DELETE', path),
  upload: <T>(method: string, path: string, values: Record<string, unknown>) =>
    uploadRequest<T>(method, path, values),
}
