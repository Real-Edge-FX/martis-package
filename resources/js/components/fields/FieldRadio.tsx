import type { InputHTMLAttributes, ReactNode } from 'react'

interface FieldRadioProps
  extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'onChange'> {
  /** Visible label placed after the circle. */
  label: string
  /** Called when this radio becomes checked. */
  onChange?: (value: string) => void
}

/**
 * Single radio input wearing the Catalog `.radio` spec (16×16 circle,
 * accent border + inner dot when checked). Always rendered in a row with
 * a label so one can click the text to select. Group multiple of these
 * with `FieldRadioGroup` to get the vertical stack.
 */
export function FieldRadio({
  label,
  onChange,
  value,
  name,
  checked,
  disabled,
  className,
  ...inputProps
}: FieldRadioProps) {
  const inputClass = className ? `martis-radio ${className}` : 'martis-radio'
  return (
    <label className="martis-radio-row">
      <input
        type="radio"
        name={name}
        value={value}
        checked={checked}
        disabled={disabled}
        className={inputClass}
        onChange={
          onChange
            ? (e) => {
                if (e.target.checked) onChange(String(value ?? ''))
              }
            : undefined
        }
        {...inputProps}
      />
      <span>{label}</span>
    </label>
  )
}

interface FieldRadioGroupProps {
  /** Vertical stack of `FieldRadio` items (or other content that fits the flow). */
  children: ReactNode
  className?: string
}

/**
 * Vertical wrapper around a set of `FieldRadio` inputs — matches the
 * Catalog `.radio-row` (which is, confusingly, a column). Aliased as
 * `.martis-radio-group` in CSS to avoid the inherited naming trap.
 */
export function FieldRadioGroup({ children, className }: FieldRadioGroupProps) {
  const cls = className ? `martis-radio-group ${className}` : 'martis-radio-group'
  return <div className={cls}>{children}</div>
}
