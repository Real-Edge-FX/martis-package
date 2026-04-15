import { useState } from 'react'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { InputText } from 'primereact/inputtext'
import { EyeIcon, EyeSlashIcon } from '@phosphor-icons/react'

export function PasswordFieldDisplay(_props: FieldDisplayProps) {
  return <span className="text-gray-400 dark:text-gray-500">••••••••</span>
}

export function PasswordFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const [show, setShow] = useState(false)

  return (
    <div className="flex flex-col gap-1">
      <div className="relative">
        <InputText
          id={field.attribute}
          name={field.attribute}
          type={show ? 'text' : 'password'}
          value={value === null || value === undefined ? '' : String(value)}
          readOnly={field.readonly}
          required={field.required}
          onChange={(e) => onChange(e.target.value)}
          invalid={!!error}
          disabled={field.readonly}
          className="w-full"
          placeholder={field.placeholder ?? "Leave blank to keep current"}
        />
        <button
          type="button"
          onClick={() => setShow(!show)}
          className="absolute right-3 top-1/2 -translate-y-1/2 martis-text-muted hover:opacity-80 focus:outline-none bg-transparent border-0 cursor-pointer p-0"
          tabIndex={-1}
        >
          {show ? <EyeSlashIcon size={16} /> : <EyeIcon size={16} />}
        </button>
      </div>
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
