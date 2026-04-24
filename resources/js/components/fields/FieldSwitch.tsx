import type { InputHTMLAttributes } from 'react'

interface FieldSwitchProps
  extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'onChange'> {
  /** Visible label placed after the track. Omit for icon-only switches. */
  label?: string
  /** Called with the new checked state. */
  onChange?: (checked: boolean) => void
  /** Wrapper className. The base `.martis-switch` is always applied. */
  wrapperClassName?: string
}

/**
 * Native toggle switch aligned to the Catalog `.toggle` spec — 32×18 track,
 * 14×14 white knob, accent background when checked. The native `<input>` is
 * visually hidden but keeps keyboard focus, ARIA state and form value.
 */
export function FieldSwitch({
  label,
  onChange,
  checked,
  disabled,
  wrapperClassName,
  ...inputProps
}: FieldSwitchProps) {
  const wrapperClass = wrapperClassName ? `martis-switch ${wrapperClassName}` : 'martis-switch'
  return (
    <label className={wrapperClass}>
      <input
        type="checkbox"
        role="switch"
        className="martis-switch-input"
        checked={checked}
        disabled={disabled}
        onChange={onChange ? (e) => onChange(e.target.checked) : undefined}
        {...inputProps}
      />
      <span className="martis-switch-track" aria-hidden="true" />
      {label && <span className="martis-switch-label">{label}</span>}
    </label>
  )
}
