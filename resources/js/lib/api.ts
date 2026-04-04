import { BASE_PATH } from '@/lib/config'

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly errors?: Record<string, { message: string; code: string }[]>,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

function getCsrfToken(): string {
  // Prefer XSRF-TOKEN cookie — always fresh (updated on every Laravel response)
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  if (match) return decodeURIComponent(match[1])
  // Fallback to meta tag (may be stale after session regeneration)
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const csrfToken = getCsrfToken()

  const res = await fetch(`${BASE_PATH}${path}`, {
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
    const err = json as { message?: string; errors?: Record<string, { message: string; code: string }[]> }
    throw new ApiError(res.status, err.message ?? 'Request failed', err.errors)
  }

  return json as T
}

/**
 * Check if form values contain any File objects that require multipart upload.
 */
export function hasFileValues(values: Record<string, unknown>): boolean {
  return Object.values(values).some((v) => v instanceof File)
}

/**
 * Build a FormData from key-value pairs for multipart upload.
 * Handles File objects, null (skip), and scalar values.
 */
function buildFormData(values: Record<string, unknown>, methodOverride?: string): FormData {
  const fd = new FormData()
  if (methodOverride) {
    fd.append('_method', methodOverride)
  }
  Object.entries(values).forEach(([key, val]) => {
    if (val instanceof File) {
      fd.append(key, val)
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

  const res = await fetch(`${BASE_PATH}${path}`, {
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
    const err = json as { message?: string; errors?: Record<string, { message: string; code: string }[]> }
    throw new ApiError(res.status, err.message ?? 'Request failed', err.errors)
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
