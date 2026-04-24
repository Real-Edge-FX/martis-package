import type { InputHTMLAttributes } from 'react'

interface FieldCheckboxProps
  extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'onChange'> {
  /** Visible label placed after the box. Omit for icon-only / grid selection. */
  label?: string
  /** Called with the new checked state. */
  onChange?: (checked: boolean) => void
  /** Wrapper className. The base `.martis-checkbox-row` is always applied when `label` is set. */
  wrapperClassName?: string
}

/**
 * Native checkbox wearing the Catalog `.checkbox` spec (16×16, radius-sm,
 * white tick on accent when checked). When a `label` is provided the native
 * input is wrapped in an inline row so clicking the text also toggles the
 * value. Without a label you can drop the component straight into a cell.
 */
export function FieldCheckbox({
  label,
  onChange,
  checked,
  disabled,
  wrapperClassName,
  className,
  ...inputProps
}: FieldCheckboxProps) {
  const inputClass = className ? `martis-checkbox ${className}` : 'martis-checkbox'
  const input = (
    <input
      type="checkbox"
      className={inputClass}
      checked={checked}
      disabled={disabled}
      onChange={onChange ? (e) => onChange(e.target.checked) : undefined}
      {...inputProps}
    />
  )

  if (!label) return input

  const wrapperClass = wrapperClassName
    ? `martis-checkbox-row ${wrapperClassName}`
    : 'martis-checkbox-row'
  return (
    <label className={wrapperClass}>
      {input}
      <span>{label}</span>
    </label>
  )
}
