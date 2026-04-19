import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { InputText } from 'primereact/inputtext'
import { EyeIcon, EyeSlashIcon, CheckCircleIcon, XCircleIcon } from '@phosphor-icons/react'
import { ClearButton } from '@/components/ClearButton'

// Companion field — never renders on index/detail. Displayed as the second
// password input with ⭐ live match indicator against the paired Password.

export function PasswordConfirmationFieldDisplay(_props: FieldDisplayProps) {
  return null
}

export function PasswordConfirmationFieldInput({ field, value, onChange, error, formValues }: FieldInputProps) {
  const { t } = useTranslation('messages')
  const [show, setShow] = useState(false)
  const stringValue = value === null || value === undefined ? '' : String(value)

  const confirmsAttr = (field as unknown as { confirms?: string }).confirms ?? 'password'
  const pairedValue = formValues?.[confirmsAttr]
  const pairedString = typeof pairedValue === 'string' ? pairedValue : ''

  const showClear = !!field.nullable && stringValue !== '' && !field.readonly

  // ⭐ Live match indicator — state is only computed once the user has typed
  // into the confirmation input. An empty input stays neutral.
  type MatchState = 'idle' | 'match' | 'mismatch'
  let matchState: MatchState = 'idle'
  if (stringValue !== '') {
    matchState = stringValue === pairedString && pairedString !== '' ? 'match' : 'mismatch'
  }

  return (
    <div className="flex flex-col gap-1">
      <div className="relative">
        <InputText
          id={field.attribute}
          name={field.attribute}
          type={show ? 'text' : 'password'}
          value={stringValue}
          readOnly={field.readonly}
          required={field.required}
          onChange={(e) => onChange(e.target.value)}
          invalid={!!error || matchState === 'mismatch'}
          disabled={field.readonly}
          className="w-full"
          style={{ paddingRight: showClear ? '4rem' : '2.5rem' }}
          placeholder={field.placeholder ?? t('password_confirm_placeholder')}
          data-testid={`password-confirm-input-${field.attribute}`}
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
        >
          {show ? <EyeSlashIcon size={16} /> : <EyeIcon size={16} />}
        </button>
      </div>

      {matchState === 'match' && (
        <div
          className="flex items-center gap-1 text-xs"
          style={{ color: 'var(--martis-success)' }}
          data-testid={`password-confirm-status-${field.attribute}`}
          data-status="match"
        >
          <CheckCircleIcon size={12} weight="fill" />
          <span>{t('password_confirm_match')}</span>
        </div>
      )}

      {matchState === 'mismatch' && (
        <div
          className="flex items-center gap-1 text-xs"
          style={{ color: 'var(--martis-danger)' }}
          data-testid={`password-confirm-status-${field.attribute}`}
          data-status="mismatch"
        >
          <XCircleIcon size={12} weight="fill" />
          <span>{t('password_confirm_mismatch')}</span>
        </div>
      )}

      {/* The live mismatch indicator above already signals non-matching —
          don't duplicate the same concern as a red error line. */}
      {error && matchState !== 'mismatch' && <small className="text-red-500">{error}</small>}
    </div>
  )
}
