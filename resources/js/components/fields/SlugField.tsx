import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { InputText } from 'primereact/inputtext'
import { CheckCircleIcon, XCircleIcon, LockSimpleIcon, WarningCircleIcon } from '@phosphor-icons/react'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { api, ApiError } from '@/lib/api'
import { ClearButton } from '@/components/ClearButton'

// -----------------------------------------------------------------------------
// Display
// -----------------------------------------------------------------------------

export function SlugFieldDisplay({ value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }
  return (
    <code
      className="text-xs font-mono rounded px-1.5 py-0.5"
      style={{
        backgroundColor: 'var(--martis-surface-alt)',
        color: 'var(--martis-text)',
      }}
    >
      {String(value)}
    </code>
  )
}

// -----------------------------------------------------------------------------
// Input helpers
// -----------------------------------------------------------------------------

const DIACRITICS: Record<string, string> = {
  á: 'a', à: 'a', â: 'a', ã: 'a', ä: 'a', å: 'a', æ: 'ae',
  ç: 'c',
  é: 'e', è: 'e', ê: 'e', ë: 'e',
  í: 'i', ì: 'i', î: 'i', ï: 'i',
  ñ: 'n',
  ó: 'o', ò: 'o', ô: 'o', õ: 'o', ö: 'o', ø: 'o',
  ú: 'u', ù: 'u', û: 'u', ü: 'u',
  ý: 'y', ÿ: 'y',
  ß: 'ss',
}

function transliterate(input: string): string {
  const lowered = input.toLowerCase()
  return Array.from(lowered).map((ch) => DIACRITICS[ch] ?? ch).join('')
}

function escapeForClass(separator: string): string {
  return separator.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * Permissive, typing-time normalization — preserves what the user is in the
 * middle of writing. Lowercases, transliterates diacritics and replaces
 * invalid characters with the separator, but does NOT trim leading/trailing
 * separators nor collapse consecutive ones. This lets the user type `"hello-"`
 * without the trailing `-` being yanked before they can type the next letter.
 */
function partialSlugify(input: string, separator: string): string {
  if (input === '') return ''
  const sepEscaped = escapeForClass(separator)
  const invalidChar = new RegExp(`[^a-z0-9${sepEscaped}]`, 'g')
  return transliterate(input).replace(invalidChar, separator)
}

/**
 * Canonical slugify — matches `Str::slug($value, $separator)` on the backend.
 * Applied on blur, on auto-generation from the source attribute, and on the
 * server before persistence.
 */
function slugify(input: string, separator: string): string {
  if (input === '') return ''
  const sepEscaped = escapeForClass(separator)
  const nonAlphaNum = new RegExp(`[^a-z0-9${sepEscaped}]+`, 'g')
  const multipleSep = new RegExp(`${sepEscaped}{2,}`, 'g')
  const trimSep = new RegExp(`^${sepEscaped}+|${sepEscaped}+$`, 'g')
  return transliterate(input)
    .replace(nonAlphaNum, separator)
    .replace(multipleSep, separator)
    .replace(trimSep, '')
}

type CheckState =
  | { kind: 'idle' }
  | { kind: 'checking' }
  | { kind: 'available' }
  | { kind: 'taken'; suggestion: string | null }
  | { kind: 'reserved'; suggestion: string | null }
  | { kind: 'error' }

// -----------------------------------------------------------------------------
// Input
// -----------------------------------------------------------------------------

export function SlugFieldInput({
  field,
  value,
  onChange,
  error,
  resourceKey,
  recordId,
  formValues,
}: FieldInputProps) {
  const { t } = useTranslation('messages')
  const extras = (field as unknown as { sourceAttribute?: string; separator?: string; reserved?: string[] }) ?? {}
  const sourceAttribute = extras.sourceAttribute ?? null
  const separator = extras.separator ?? '-'
  const reserved = Array.isArray(extras.reserved) ? extras.reserved : []
  // `lockAfter` is enforced server-side by `Slug::fill()` (silent ignore on
  // a locked model). UI lock is driven by `field.readonly`, which resource
  // authors can wire to the same condition. A richer auto-lock hint flows
  // once Task 08 (dependsOn) lands.
  const locked = !!field.readonly

  const stringValue = value === null || value === undefined ? '' : String(value)
  const [manuallyEdited, setManuallyEdited] = useState<boolean>(stringValue !== '')
  const [checkState, setCheckState] = useState<CheckState>({ kind: 'idle' })
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  // In update context (recordId set), capture the value the record was loaded
  // with. While the user has not touched the slug, we suppress the status row
  // entirely — no "Available" badge the instant the drawer opens, since the
  // user hasn't changed anything yet.
  const initialValueRef = useRef<string | null>(null)
  useEffect(() => {
    if (initialValueRef.current === null && recordId !== undefined && recordId !== null && stringValue !== '') {
      initialValueRef.current = stringValue
    }
  }, [stringValue, recordId])
  const isUnchangedFromInitial =
    initialValueRef.current !== null && stringValue === initialValueRef.current

  // ⭐ D1 — Live preview: auto-generate from the source field as the user types
  // in the source input. We stop auto-generating once the user has typed into
  // the slug input directly (detected via `manuallyEdited`).
  useEffect(() => {
    if (locked) return
    if (manuallyEdited) return
    if (!sourceAttribute) return
    const source = formValues?.[sourceAttribute]
    if (typeof source !== 'string' || source === '') return
    const generated = slugify(source, separator)
    if (generated !== stringValue) {
      onChange(generated === '' ? null : generated)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [formValues?.[sourceAttribute ?? ''], sourceAttribute, separator, locked, manuallyEdited])

  // ⭐ D2 — Debounced collision check against /api/resources/{resource}/slug-check/{field}.
  useEffect(() => {
    if (locked) return
    if (!resourceKey) return
    if (stringValue === '') {
      setCheckState({ kind: 'idle' })
      return
    }
    // On edit, while the user hasn't changed the slug from what was loaded,
    // keep the status row hidden — opening the form shouldn't flash a badge.
    if (isUnchangedFromInitial) {
      setCheckState({ kind: 'idle' })
      return
    }
    // Client-side reserved word check runs instantly — saves a roundtrip.
    if (reserved.includes(stringValue)) {
      setCheckState({ kind: 'reserved', suggestion: null })
      return
    }

    if (debounceRef.current !== null) {
      clearTimeout(debounceRef.current)
    }
    setCheckState({ kind: 'checking' })
    debounceRef.current = setTimeout(async () => {
      try {
        const qs = new URLSearchParams({ value: stringValue })
        if (recordId !== undefined && recordId !== null) qs.set('id', String(recordId))
        const res = await api.get<{
          data: { available: boolean; suggestion: string | null; reserved: boolean }
        }>(`/api/resources/${resourceKey}/slug-check/${field.attribute}?${qs.toString()}`)
        if (res.data.reserved) {
          setCheckState({ kind: 'reserved', suggestion: res.data.suggestion })
        } else if (res.data.available) {
          setCheckState({ kind: 'available' })
        } else {
          setCheckState({ kind: 'taken', suggestion: res.data.suggestion })
        }
      } catch (e) {
        if (e instanceof ApiError && e.status === 404) {
          // Endpoint missing — stay idle rather than alarming the user.
          setCheckState({ kind: 'idle' })
        } else {
          setCheckState({ kind: 'error' })
        }
      }
    }, 400)

    return () => {
      if (debounceRef.current !== null) clearTimeout(debounceRef.current)
    }
  }, [stringValue, resourceKey, field.attribute, recordId, reserved, locked, isUnchangedFromInitial])

  const handleUserInput = (raw: string) => {
    setManuallyEdited(true)
    // Permissive normalization only — preserves in-flight characters like a
    // trailing separator ("hello-") so the user can keep typing. Canonical
    // slugify runs on blur and again on the server.
    onChange(raw === '' ? null : partialSlugify(raw, separator))
  }

  const handleBlur = () => {
    if (stringValue === '') return
    const canonical = slugify(stringValue, separator)
    if (canonical !== stringValue) onChange(canonical === '' ? null : canonical)
  }

  const applySuggestion = (suggestion: string) => {
    setManuallyEdited(true)
    onChange(suggestion)
  }

  const showClear = !!field.nullable && stringValue !== '' && !locked

  return (
    <div className="flex flex-col gap-1">
      <div className="relative">
        <InputText
          id={field.attribute}
          name={field.attribute}
          type="text"
          value={stringValue}
          readOnly={locked}
          disabled={locked}
          required={field.required}
          onChange={(e) => handleUserInput(e.target.value)}
          onBlur={handleBlur}
          invalid={!!error}
          placeholder={field.placeholder ?? slugify('example', separator)}
          className="w-full"
          style={showClear ? { paddingRight: '2rem' } : undefined}
          data-testid={`slug-input-${field.attribute}`}
        />
        <ClearButton
          visible={showClear}
          onClick={() => {
            setManuallyEdited(false)
            onChange(null)
          }}
          style={{ position: 'absolute', right: '0.5rem', top: '50%', transform: 'translateY(-50%)' }}
        />
      </div>

      {/* Status row — lock, or check state, or error. */}
      {locked && (
        <div
          className="flex items-center gap-1 text-xs"
          style={{ color: 'var(--martis-text-muted)' }}
          data-testid={`slug-locked-${field.attribute}`}
        >
          <LockSimpleIcon size={12} weight="fill" />
          <span>{t('slug_locked')}</span>
        </div>
      )}

      {!locked && stringValue !== '' && !isUnchangedFromInitial && checkState.kind === 'available' && (
        <SlugStatusRow
          testId={`slug-status-${field.attribute}`}
          status="available"
          color="var(--martis-success)"
          icon={<CheckCircleIcon size={12} weight="fill" />}
          label={t('slug_available')}
        />
      )}

      {!locked && !isUnchangedFromInitial && checkState.kind === 'taken' && (
        <SlugStatusRow
          testId={`slug-status-${field.attribute}`}
          status="taken"
          color="var(--martis-danger)"
          icon={<XCircleIcon size={12} weight="fill" />}
          label={t('slug_taken')}
          suggestion={checkState.suggestion}
          suggestionTooltip={t('slug_apply_suggestion')}
          onApply={applySuggestion}
          suggestionTestId={`slug-suggestion-${field.attribute}`}
        />
      )}

      {!locked && !isUnchangedFromInitial && checkState.kind === 'reserved' && (
        <SlugStatusRow
          testId={`slug-status-${field.attribute}`}
          status="reserved"
          color="var(--martis-warning)"
          icon={<WarningCircleIcon size={12} weight="fill" />}
          label={t('slug_reserved_short')}
          suggestion={checkState.suggestion}
          suggestionTooltip={t('slug_apply_suggestion')}
          onApply={applySuggestion}
          suggestionTestId={`slug-suggestion-${field.attribute}`}
        />
      )}

      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}

// -----------------------------------------------------------------------------
// Status row — shared layout for available / taken / reserved.
//
// Design rules:
//  - Status label never wraps mid-phrase (`whiteSpace: nowrap`) — "Já existe"
//    used to break between tokens when the drawer column was narrow.
//  - Suggestion is a chip (tinted accent background, rounded) rather than an
//    underlined link — clearer affordance, softer contrast, wraps cleanly.
//  - Row uses `flex-wrap` so the chip drops to a new line before ever cutting
//    the status text.
// -----------------------------------------------------------------------------

function SlugStatusRow({
  testId,
  status,
  color,
  icon,
  label,
  suggestion,
  suggestionTooltip,
  onApply,
  suggestionTestId,
}: {
  testId: string
  status: 'available' | 'taken' | 'reserved'
  color: string
  icon: React.ReactNode
  label: string
  suggestion?: string | null
  suggestionTooltip?: string
  onApply?: (s: string) => void
  suggestionTestId?: string
}) {
  return (
    <div
      className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs"
      data-testid={testId}
      data-status={status}
    >
      <span className="inline-flex items-center gap-1" style={{ color, whiteSpace: 'nowrap' }}>
        {icon}
        <span>{label}</span>
      </span>
      {suggestion && onApply && (
        <button
          type="button"
          onClick={() => onApply(suggestion)}
          className="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium transition-colors hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-1 cursor-pointer"
          style={{
            backgroundColor: 'color-mix(in oklab, var(--martis-accent) 12%, transparent)',
            color: 'var(--martis-accent)',
            border: '1px solid color-mix(in oklab, var(--martis-accent) 24%, transparent)',
            whiteSpace: 'nowrap',
          }}
          data-testid={suggestionTestId}
          data-pr-tooltip={suggestionTooltip}
          data-pr-position="top"
          aria-label={suggestionTooltip}
        >
          <span>→</span>
          <code className="font-mono">{suggestion}</code>
        </button>
      )}
    </div>
  )
}
