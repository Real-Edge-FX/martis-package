import type { FieldDisplayProps, FieldInputProps } from './types'

/**
 * Per-row payload emitted by PHP's Stack field — see
 * `src/Fields/Stack.php::resolveForDisplay()`. One entry per declared Line,
 * enriched with the variant and (optional) subtitle string resolved from
 * the model.
 */
interface StackEntry {
  text: string | null
  variant: string
  subtitle: string | null
}

interface StackPayload {
  __martisStack: true
  entries: StackEntry[]
  divider: boolean
}

function isStackPayload(v: unknown): v is StackPayload {
  return typeof v === 'object' && v !== null && (v as { __martisStack?: unknown }).__martisStack === true
}

/**
 * Variant → CSS class. Each variant has a `.martis-line-{name}` class
 * defined in martis.css so a custom theme can restyle every Line in
 * the package by overriding a handful of tokens.
 */
const VARIANT_CLASS: Record<string, string> = {
  heading: 'martis-line-heading',
  base: 'martis-line-base',
  small: 'martis-line-small',
  muted: 'martis-line-muted',
  code: 'martis-line-code',
}

function resolveLineClass(variant: string): string {
  return VARIANT_CLASS[variant] ?? VARIANT_CLASS.base
}

function StackEntryRow({ entry }: { entry: StackEntry }) {
  if (entry.text === null || entry.text === '') {
    if (entry.subtitle === null || entry.subtitle === '') return null
  }
  const lineClass = resolveLineClass(entry.variant)
  return (
    <div className="martis-line-row">
      {entry.text !== null && entry.text !== '' && (
        <span className={lineClass}>{entry.text}</span>
      )}
      {entry.subtitle !== null && entry.subtitle !== '' && (
        <span className="martis-line-subtitle">{entry.subtitle}</span>
      )}
    </div>
  )
}

export function StackFieldDisplay({ value }: FieldDisplayProps) {
  const payload = isStackPayload(value) ? value : null

  if (!payload || payload.entries.length === 0) {
    return <span className="martis-text-muted">—</span>
  }

  const withDivider = payload.divider

  return (
    <div className={`martis-stack${withDivider ? ' martis-stack--divided' : ''}`}>
      {payload.entries.map((entry, idx) => (
        <StackEntryRow entry={entry} key={idx} />
      ))}
    </div>
  )
}

// Stack is display-only. Hidden from forms by PHP default; input is a no-op
// placeholder rendered only if a dev explicitly `->showOnForms()`.
export function StackFieldInput(props: FieldInputProps) {
  return <StackFieldDisplay {...props} />
}
