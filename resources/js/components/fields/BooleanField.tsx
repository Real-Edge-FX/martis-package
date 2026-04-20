import type { FieldDisplayProps, FieldInputProps } from './types'
import { InputSwitch } from 'primereact/inputswitch'
import { useTranslation } from 'react-i18next'
import { resolveBadgeStyle } from './badgeStyles'

export function BooleanFieldDisplay({ field, value }: FieldDisplayProps) {
  const { t } = useTranslation('messages')
  const checked = Boolean(value)
  const label = checked
    ? (field.trueLabel as string | undefined) ?? t('yes')
    : (field.falseLabel as string | undefined) ?? t('no')

  // PHP Boolean::trueColor / falseColor — defaults: success / neutral.
  const trueColor = ((field as Record<string, unknown>).trueColor as string | undefined) ?? 'success'
  const falseColor = ((field as Record<string, unknown>).falseColor as string | undefined) ?? 'neutral'
  const style = resolveBadgeStyle(checked ? trueColor : falseColor)

  return (
    <span
      className="martis-badge"
      style={{ backgroundColor: style.bg, color: style.text, borderColor: style.border }}
    >
      <span className="martis-badge-dot" aria-hidden="true" />
      {label}
    </span>
  )
}

export function BooleanFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const { t } = useTranslation('messages')
  const checked = Boolean(value)
  const label = checked
    ? (field.trueLabel as string | undefined) ?? t('active')
    : (field.falseLabel as string | undefined) ?? t('inactive')
  return (
    <div className="flex flex-col gap-1">
      <div className="flex items-center gap-3">
        <InputSwitch
          inputId={field.attribute}
          checked={checked}
          onChange={(e) => onChange(e.value)}
          disabled={field.readonly}
        />
        <label
          htmlFor={field.attribute}
          className="text-sm text-gray-700 dark:text-gray-300"
        >
          {label}
        </label>
      </div>
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
