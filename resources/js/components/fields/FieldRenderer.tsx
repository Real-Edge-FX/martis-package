import type { ComponentType } from 'react'
import { componentRegistry } from '@/lib/componentRegistry'
import type { FieldDefinition } from '@/types'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { TextFieldDisplay, TextFieldInput } from './TextField'
import { TextareaFieldDisplay, TextareaFieldInput } from './TextareaField'
import { NumberFieldDisplay, NumberFieldInput } from './NumberField'
import { BooleanFieldDisplay, BooleanFieldInput } from './BooleanField'
import { SelectFieldDisplay, SelectFieldInput } from './SelectField'
import { DateFieldDisplay, DateFieldInput } from './DateField'
import { DateTimeFieldDisplay, DateTimeFieldInput } from './DateTimeField'
import { BelongsToFieldDisplay, BelongsToFieldInput } from './BelongsToField'
import { FileFieldDisplay, FileFieldInput } from './FileField'
import { ImageFieldDisplay, ImageFieldInput } from './ImageField'
import { IdFieldDisplay, IdFieldInput } from './IdField'
import { EmailFieldDisplay, EmailFieldInput } from './EmailField'
import { PasswordFieldDisplay, PasswordFieldInput } from './PasswordField'
import { HeadingFieldDisplay, HeadingFieldInput } from './HeadingField'
import { HiddenFieldDisplay, HiddenFieldInput } from './HiddenField'

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
  datetime: DateTimeFieldDisplay,
  belongs_to: BelongsToFieldDisplay,
  file: FileFieldDisplay,
  image: ImageFieldDisplay,
  id: IdFieldDisplay,
  email: EmailFieldDisplay,
  password: PasswordFieldDisplay,
  heading: HeadingFieldDisplay,
  hidden: HiddenFieldDisplay,
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
  datetime: DateTimeFieldInput,
  belongs_to: BelongsToFieldInput,
  file: FileFieldInput,
  image: ImageFieldInput,
  id: IdFieldInput,
  email: EmailFieldInput,
  password: PasswordFieldInput,
  heading: HeadingFieldInput,
  hidden: HiddenFieldInput,
}

// -------------------------------------------------------------------------
// Registration into the global registry
// -------------------------------------------------------------------------

export function registerDefaultFields(): void {
  Object.entries(DEFAULT_DISPLAY).forEach(([type, component]) => {
    if (!componentRegistry.has(`field:display:${type}`)) {
      componentRegistry.register(`field:display:${type}`, component)
    }
  })
  Object.entries(DEFAULT_INPUT).forEach(([type, component]) => {
    if (!componentRegistry.has(`field:input:${type}`)) {
      componentRegistry.register(`field:input:${type}`, component)
    }
  })
}

// -------------------------------------------------------------------------
// Fallback helpers
// -------------------------------------------------------------------------

function getFallbackDisplay(type: string): ComponentType<FieldDisplayProps> {
  return DEFAULT_DISPLAY[type] ?? TextFieldDisplay
}

function getFallbackInput(type: string): ComponentType<FieldInputProps> {
  return DEFAULT_INPUT[type] ?? TextFieldInput
}

// -------------------------------------------------------------------------
// Rendered components — supports 4-tier override resolution
// -------------------------------------------------------------------------

/**
 * Renders a field value in read-only display mode (index / detail).
 *
 * Override resolution (highest to lowest priority):
 *   1. field.component (explicit key set in PHP via ->component('key'))
 *   2. Per-resource override (componentRegistry.registerResourceFieldDisplay)
 *   3. Global type override (componentRegistry.registerFieldDisplay)
 *   4. Built-in default component for the type
 *
 * @param resourceKey  The resource URI key (e.g. "users") — enables per-resource overrides
 */
export function FieldDisplay({
  field,
  value,
  resourceKey,
}: {
  field: FieldDefinition
  value: unknown
  resourceKey?: string
}) {
  const Component = componentRegistry.resolveDisplay(
    field.type,
    field.attribute,
    resourceKey,
    field.component,
    getFallbackDisplay(field.type),
  )
  return <Component field={field} value={value} />
}

/**
 * Renders a field as an editable input (create / update forms).
 *
 * Override resolution follows the same 4-tier chain as FieldDisplay.
 *
 * @param resourceKey  The resource URI key — enables per-resource overrides
 */
export function FieldInput({
  field,
  value,
  onChange,
  error,
  resourceKey,
}: {
  field: FieldDefinition
  value: unknown
  onChange: (v: unknown) => void
  error?: string
  resourceKey?: string
}) {
  const Component = componentRegistry.resolveInput(
    field.type,
    field.attribute,
    resourceKey,
    field.component,
    getFallbackInput(field.type),
  )
  return <Component field={field} value={value} onChange={onChange} error={error} />
}
