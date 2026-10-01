import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { InputText } from 'primereact/inputtext'
import { EyeIcon, EyeSlashIcon, CheckCircleIcon, XCircleIcon, InfoIcon } from '@phosphor-icons/react'
import { ClearButton } from '@/components/ClearButton'
import { FieldError } from '@/components/auth/FieldError'
import type { PasswordRequirements } from '@/lib/config'

/**
 * What a set-password page (register, reset password, invitation accept, the
 * forced password change) adds to the resource-form input. A resource form
 * passes none of them.
 */
export interface PasswordPageInputProps {
  /** Focus the input on mount. */
  autoFocus?: boolean
  /** Disable the input, for example while the form submits. */
  disabled?: boolean
  /**
   * Render `error` as the auth pages render a field error (`FieldError`,
   * `role="alert"`), always: a screen reader hears it even when a checklist
   * row or the match indicator shows the same concern.
   */
  announceError?: boolean
}

// -----------------------------------------------------------------------------
// ⭐ Declarative complexity requirements — emitted from PHP Password field.
// -----------------------------------------------------------------------------

const COMMON_PASSWORDS = [
  'password', 'qwerty', '12345', '12345678', '123456789',
  'letmein', 'admin', 'welcome', 'abc123', 'iloveyou',
]

type RequirementCheck = {
  id: keyof PasswordRequirements
  label: string
  /** `null`: the server checks it (`uncompromised`), the browser cannot. */
  passes: boolean | null
}

/** Characters, as `mb_strlen` counts them (an emoji is one, not two UTF-16 units). */
function characterCount(value: string): number {
  return [...value].length
}

// The checks of Laravel's `Password` rule, so the checklist and the server agree.
const UPPERCASE = /\p{Lu}/u
const LOWERCASE = /\p{Ll}/u
const LETTER = /\p{L}/u
const NUMBER = /\p{N}/u
const SYMBOL = /[\p{Z}\p{S}\p{P}]/u

function evaluateRequirements(
  value: string,
  reqs: PasswordRequirements,
  t: (key: string, opts?: Record<string, unknown>) => string,
): RequirementCheck[] {
  const checks: RequirementCheck[] = []
  const length = characterCount(value)
  if (reqs.minLength !== undefined) {
    checks.push({ id: 'minLength', label: t('password_req_min_length', { n: reqs.minLength }), passes: length >= reqs.minLength })
  }
  if (reqs.maxLength !== undefined) {
    checks.push({ id: 'maxLength', label: t('password_req_max_length', { n: reqs.maxLength }), passes: length <= reqs.maxLength })
  }
  if (reqs.uppercase) {
    checks.push({ id: 'uppercase', label: t('password_req_uppercase'), passes: UPPERCASE.test(value) })
  }
  if (reqs.lowercase) {
    checks.push({ id: 'lowercase', label: t('password_req_lowercase'), passes: LOWERCASE.test(value) })
  }
  if (reqs.letters) {
    checks.push({ id: 'letters', label: t('password_req_letters'), passes: LETTER.test(value) })
  }
  if (reqs.number) {
    checks.push({ id: 'number', label: t('password_req_number'), passes: NUMBER.test(value) })
  }
  if (reqs.symbol) {
    checks.push({ id: 'symbol', label: t('password_req_symbol'), passes: SYMBOL.test(value) })
  }
  if (reqs.noCommon) {
    const lower = value.toLowerCase()
    const passes = value.length > 0 && !COMMON_PASSWORDS.some((w) => lower.startsWith(w))
    checks.push({ id: 'noCommon', label: t('password_req_no_common'), passes })
  }
  if (reqs.uncompromised) {
    checks.push({ id: 'uncompromised', label: t('password_req_uncompromised'), passes: null })
  }
  return checks
}

export function PasswordFieldDisplay(_props: FieldDisplayProps) {
  return <span className="text-gray-400 dark:text-gray-500">••••••••</span>
}

// ⭐ Differential — zxcvbn-lite strength heuristic (0–4 scale).
// Reads length, class diversity (lower/upper/digit/symbol) and the presence
// of common weak patterns. Good enough as a UX hint without pulling in the
// heavy `zxcvbn` npm dependency.
function scorePassword(pwd: string): number {
  if (pwd.length === 0) return 0
  const length = characterCount(pwd)
  let score = 0
  if (length >= 8) score++
  if (length >= 12) score++
  // Long passwords are independently strong even without every character
  // class. A 40-char passphrase with lowercase + digits + symbols should read
  // as "Strong", not stop at "Good" because it happens to lack an uppercase.
  if (length >= 20) score++
  const classes = [LOWERCASE, UPPERCASE, NUMBER, SYMBOL].reduce((n, rx) => n + (rx.test(pwd) ? 1 : 0), 0)
  if (classes >= 3) score++
  if (classes === 4 && length >= 10) score++
  if (/^(password|qwerty|12345|letmein|admin|welcome)/i.test(pwd)) score = Math.max(0, score - 2)
  return Math.min(4, score)
}

function strengthColor(score: number): string {
  // Steady progression aligned with var(--martis-*) tokens.
  if (score <= 1) return 'var(--martis-danger)'
  if (score === 2) return 'var(--martis-warning)'
  if (score === 3) return 'var(--martis-info)'
  return 'var(--martis-success)'
}

function strengthLabel(score: number, t: (k: string) => string): string {
  if (score <= 1) return t('password_strength_weak')
  if (score === 2) return t('password_strength_fair')
  if (score === 3) return t('password_strength_good')
  return t('password_strength_strong')
}

export function PasswordFieldInput({ field, value, onChange, error, autoFocus, disabled, announceError }: FieldInputProps & PasswordPageInputProps) {
  const { t } = useTranslation('messages')
  const [show, setShow] = useState(false)
  const stringValue = value === null || value === undefined ? '' : String(value)
  const showClear = !!field.nullable && stringValue !== '' && !field.readonly

  // Metadata emitted by the server-side Password field (extraAttributes).
  const extras = field as unknown as {
    strengthMeter?: boolean
    showRequirements?: boolean
    requirements?: PasswordRequirements
  }
  const strengthMeter = extras.strengthMeter === true
  const showRequirements = extras.showRequirements === true && extras.requirements !== undefined
  const requirements = extras.requirements ?? {}

  // Evaluate requirements only when the checklist is actually going to render
  // AND the user has typed something. Empty input keeps the checklist neutral
  // (all rows unchecked) rather than flashing red the moment the drawer opens.
  const requirementChecks = showRequirements && stringValue !== ''
    ? evaluateRequirements(stringValue, requirements, t as (k: string, opts?: Record<string, unknown>) => string)
    : []
  const checkable = requirementChecks.filter((c) => c.passes !== null)
  const allRequirementsMet = checkable.length > 0 && checkable.every((c) => c.passes === true)

  // Strength score — clamped to at least 3 ("Good") when a checklist is
  // present and every requirement passes. Prevents the inconsistent UX of
  // green checks next to a red "Weak" bar.
  let score: number | null = null
  if (strengthMeter && stringValue !== '') {
    score = scorePassword(stringValue)
    if (allRequirementsMet) score = Math.max(score, 3)
  }

  return (
    <div className="flex flex-col gap-1">
      <div className="relative">
        <InputText
          id={field.attribute}
          name={field.attribute}
          type={show ? 'text' : 'password'}
          // v1.8.1 — Chrome's a11y audit warns when a password input
          // is missing an `autocomplete` attribute. `new-password` is
          // correct for any "set password" flow (profile change,
          // register, reset). Sign-in flows use a custom `<input>`
          // with `current-password` instead of this component, so
          // `new-password` here is unambiguous.
          autoComplete="new-password"
          value={stringValue}
          readOnly={field.readonly}
          required={field.required}
          onChange={(e) => onChange(e.target.value)}
          invalid={!!error}
          disabled={field.readonly || disabled === true}
          autoFocus={autoFocus}
          className="w-full"
          style={{ paddingRight: showClear ? '4rem' : '2rem' }}
          placeholder={field.placeholder ?? (field.required ? '' : t('password_leave_blank_hint'))}
          data-testid={`password-input-${field.attribute}`}
        />
        <ClearButton
          visible={showClear}
          onClick={() => onChange(null)}
          style={{ position: 'absolute', right: '2rem', top: '50%', transform: 'translateY(-50%)' }}
        />
        <button
          type="button"
          onClick={() => setShow(!show)}
          className="absolute right-3 top-1/2 -translate-y-1/2 martis-text-muted hover:opacity-80 focus:outline-none bg-transparent border-0 cursor-pointer p-0"
          tabIndex={-1}
          aria-label={show ? t('password_hide') : t('password_show')}
          data-testid={`password-toggle-${field.attribute}`}
        >
          {show ? <EyeSlashIcon size={16} /> : <EyeIcon size={16} />}
        </button>
      </div>

      {strengthMeter && score !== null && (
        <div className="flex items-center gap-2" data-testid={`password-strength-${field.attribute}`}>
          <div className="flex-1 h-1 rounded-full overflow-hidden" style={{ backgroundColor: 'var(--martis-surface-alt)' }}>
            <div
              className="h-full transition-all"
              style={{
                width: `${((score + 1) / 5) * 100}%`,
                backgroundColor: strengthColor(score),
              }}
              data-strength-score={score}
            />
          </div>
          <span className="text-xs" style={{ color: strengthColor(score) }}>
            {strengthLabel(score, t)}
          </span>
        </div>
      )}

      {showRequirements && stringValue !== '' && (
        <ul
          className="mt-1 flex flex-col gap-0.5 list-none pl-0 m-0"
          data-testid={`password-requirements-${field.attribute}`}
        >
          {requirementChecks.map((check) => (
            <li
              key={check.id}
              className="flex items-center gap-1.5 text-xs"
              style={{
                color: check.passes === true ? 'var(--martis-success)' : 'var(--martis-text-muted)',
                whiteSpace: 'nowrap',
              }}
              data-requirement={check.id}
              data-passes={check.passes === null ? 'server' : check.passes ? 'true' : 'false'}
            >
              {check.passes === null ? (
                <InfoIcon size={12} weight="regular" />
              ) : check.passes ? (
                <CheckCircleIcon size={12} weight="fill" />
              ) : (
                <XCircleIcon size={12} weight="regular" />
              )}
              <span>{check.label}</span>
            </li>
          ))}
        </ul>
      )}

      {/* Show the `error` prop only when the checklist isn't already
          surfacing a failing requirement — otherwise we'd render the same
          concern twice (once as a checklist row, once as a red error line).
          A set-password page announces it whatever the checklist shows. */}
      {announceError === true ? (
        <FieldError message={error} />
      ) : error && !(showRequirements && requirementChecks.some((c) => c.passes === false)) && (
        <small className="text-red-500">{error}</small>
      )}
    </div>
  )
}
