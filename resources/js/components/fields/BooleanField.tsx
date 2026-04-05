import type { FieldDisplayProps, FieldInputProps } from ./types
import { InputSwitch } from primereact/inputswitch
import { useTranslation } from react-i18next

export function BooleanFieldDisplay({ field, value }: FieldDisplayProps) {
  const { t } = useTranslation(messages)
  const checked = Boolean(value)
  const label = checked
    ? (field.trueLabel as string | undefined) ?? t(yes)
    : (field.falseLabel as string | undefined) ?? t(no)
  return (
    <span
      className={[
        inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium,
        checked
          ? bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400
          : bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400,
      ].join( )}
    >
      {label}
    </span>
  )
}

export function BooleanFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const { t } = useTranslation(messages)
  const checked = Boolean(value)
  const label = checked
    ? (field.trueLabel as string | undefined) ?? t(active)
    : (field.falseLabel as string | undefined) ?? t(inactive)
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
