import type { ComponentType } from 'react'
import { registry } from '@/lib/registry'
import type { FieldDefinition } from '@/types'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { TextFieldDisplay, TextFieldInput } from './TextField'
import { TextareaFieldDisplay, TextareaFieldInput } from './TextareaField'
import { NumberFieldDisplay, NumberFieldInput } from './NumberField'
import { BooleanFieldDisplay, BooleanFieldInput } from './BooleanField'
import { SelectFieldDisplay, SelectFieldInput } from './SelectField'
import { DateFieldDisplay, DateFieldInput } from './DateField'
import { BelongsToFieldDisplay, BelongsToFieldInput } from './BelongsToField'

// -------------------------------------------------------------------------
// Default display components per type
// -------------------------------------------------------------------------

const DEFAULT_DISPLAY: Record<string, ComponentType<FieldDisplayProps>> = {
  text: TextFieldDisplay,
  textarea: TextareaFieldDisplay,
  number: NumberFieldDisplay,
  boolean: BooleanFieldDisplay,
  select: SelectFieldDisplay,
  date: DateFieldDisplay,
  belongs_to: BelongsToFieldDisplay,
}

// -------------------------------------------------------------------------
// Default input components per type
// -------------------------------------------------------------------------

const DEFAULT_INPUT: Record<string, ComponentType<FieldInputProps>> = {
  text: TextFieldInput,
  textarea: TextareaFieldInput,
  number: NumberFieldInput,
  boolean: BooleanFieldInput,
  select: SelectFieldInput,
  date: DateFieldInput,
  belongs_to: BelongsToFieldInput,
}

// -------------------------------------------------------------------------
// Registration into the global registry
// -------------------------------------------------------------------------

export function registerDefaultFields(): void {
  Object.entries(DEFAULT_DISPLAY).forEach(([type, component]) => {
    if (!registry.has(`field:display:${type}`)) {
      registry.register(`field:display:${type}`, component)
    }
  })
  Object.entries(DEFAULT_INPUT).forEach(([type, component]) => {
    if (!registry.has(`field:input:${type}`)) {
      registry.register(`field:input:${type}`, component)
    }
  })
}

// -------------------------------------------------------------------------
// Resolved renderers (use registry, fall back to defaults)
// -------------------------------------------------------------------------

function getFallbackDisplay(type: string): ComponentType<FieldDisplayProps> {
  return DEFAULT_DISPLAY[type] ?? TextFieldDisplay
}

function getFallbackInput(type: string): ComponentType<FieldInputProps> {
  return DEFAULT_INPUT[type] ?? TextFieldInput
}

/** Renders a field value in read-only display mode (index / detail). */
export function FieldDisplay({ field, value }: { field: FieldDefinition; value: unknown }) {
  const Component = registry.resolve<FieldDisplayProps>(
    `field:display:${field.type}`,
    getFallbackDisplay(field.type),
  )
  return <Component field={field} value={value} />
}

/** Renders a field as an editable input (create / update forms). */
export function FieldInput({
  field,
  value,
  onChange,
  error,
}: {
  field: FieldDefinition
  value: unknown
  onChange: (v: unknown) => void
  error?: string
}) {
  const Component = registry.resolve<FieldInputProps>(
    `field:input:${field.type}`,
    getFallbackInput(field.type),
  )
  return <Component field={field} value={value} onChange={onChange} error={error} />
}
