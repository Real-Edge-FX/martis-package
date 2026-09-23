/** A row of a relatable endpoint (`/api/resources/.../relatable/...`). */
export interface RelatedRecordLike {
  id: number | string
  _title?: string
  [key: string]: unknown
}

/**
 * The text of an attribute value as a picker can show it: strings, numbers
 * and booleans as is, the heading (or first) entry of a Stack
 * (`{ __martisStack: true, entries: [...] }`), and `null` for anything else.
 */
function textOf(value: unknown): string | null {
  if (value === undefined || value === null) return null
  if (typeof value === 'string') return value
  if (typeof value === 'number' || typeof value === 'boolean') return String(value)

  if (typeof value === 'object') {
    const stack = value as { __martisStack?: unknown; entries?: unknown }
    if (stack.__martisStack && Array.isArray(stack.entries)) {
      const entries = stack.entries as Array<{ text?: unknown; variant?: string }>
      const text = entries.find((entry) => entry.variant === 'heading')?.text ?? entries[0]?.text
      return text != null ? String(text) : null
    }
  }

  return null
}

/**
 * Label of a related record in the relation pickers (BelongsTo, MorphTo,
 * Tag). Relatable rows carry the related resource's index values, so the
 * title attribute can arrive formatted (a Stack on that index); the label
 * reads the text out of it, then falls back to the resource's resolved
 * `_title`, a few common attributes and finally `#id`, and never prints
 * "[object Object]".
 */
export function relatedRecordLabel(record: RelatedRecordLike, titleAttribute?: string): string {
  if (titleAttribute) {
    const title = textOf(record[titleAttribute])
    if (title !== null) return title
  }

  if (record._title) return record._title

  for (const attribute of ['name', 'title', 'label', 'email']) {
    const text = textOf(record[attribute])
    if (text !== null) return text
  }

  return `#${record.id}`
}
