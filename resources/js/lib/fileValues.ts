/*
 * Detecting a file among form values, on its own module so a test that
 * replaces '@/lib/api' wholesale does not lose it (the action run imports it
 * to choose between JSON and multipart).
 */

/** Marker interface for multiple-file field values. */
export interface MultiFileValue {
  __multiple: true
  items: Array<{
    id: string
    file?: File
    existing?: { path: string; url: string; name: string; thumbnailUrl?: string }
    previewUrl?: string
  }>
}

export function isMultiFileValue(v: unknown): v is MultiFileValue {
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
