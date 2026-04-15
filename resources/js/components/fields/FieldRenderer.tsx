import { lazy, Suspense, type ComponentType } from 'react'
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
import { KeyValueFieldDisplay, KeyValueFieldInput } from './KeyValueField'
import { BadgeFieldDisplay, BadgeFieldInput } from './BadgeField'
import { StatusFieldDisplay, StatusFieldInput } from './StatusField'
import { MultiSelectFieldDisplay, MultiSelectFieldInput } from './MultiSelectField'
import { TagFieldDisplay, TagFieldInput } from './TagField'
import { UrlFieldDisplay, UrlFieldInput } from './UrlField'
import { ColorFieldDisplay, ColorFieldInput } from './ColorField'
import { CountryFieldDisplay, CountryFieldInput } from './CountryField'
import { CurrencyFieldDisplay, CurrencyFieldInput } from './CurrencyField'
import { SparklineFieldDisplay, SparklineFieldInput } from './SparklineField'
import { GravatarFieldDisplay, GravatarFieldInput } from './GravatarField'
import { MorphToFieldDisplay, MorphToFieldInput } from './MorphToField'
import { MorphOneFieldDisplay, MorphOneFieldInput } from './MorphOneField'
import { HasOneFieldDisplay, HasOneFieldInput } from './HasOneField'

const LAZY_FIELD_FALLBACK = <div />

function createLazyFieldComponent<P extends object>(
  displayName: string,
  loader: () => Promise<{ default: ComponentType<P> }>,
): ComponentType<P> {
  const LazyComponent = lazy(loader)
  const WrappedLazyComponent = LazyComponent as unknown as ComponentType<P>

  function LazyFieldComponent(props: P) {
    return (
      <Suspense fallback={LAZY_FIELD_FALLBACK}>
        <WrappedLazyComponent {...props} />
      </Suspense>
    )
  }

  LazyFieldComponent.displayName = displayName

  return LazyFieldComponent
}

const loadCodeField = () => import('./CodeField')
const loadMarkdownField = () => import('./MarkdownField')
const loadTrixField = () => import('./TrixField')
const loadHasManyField = () => import('./HasManyField')
const loadBelongsToManyField = () => import('./BelongsToManyField')
const loadMorphManyField = () => import('./MorphManyField')
const loadMorphToManyField = () => import('./MorphToManyField')

const LazyCodeFieldDisplay = createLazyFieldComponent<FieldDisplayProps>(
  'LazyCodeFieldDisplay',
  async () => {
    const module = await loadCodeField()
    return { default: module.CodeFieldDisplay }
  },
)

const LazyCodeFieldInput = createLazyFieldComponent<FieldInputProps>(
  'LazyCodeFieldInput',
  async () => {
    const module = await loadCodeField()
    return { default: module.CodeFieldInput }
  },
)

const LazyMarkdownFieldDisplay = createLazyFieldComponent<FieldDisplayProps>(
  'LazyMarkdownFieldDisplay',
  async () => {
    const module = await loadMarkdownField()
    return { default: module.MarkdownFieldDisplay }
  },
)

const LazyMarkdownFieldInput = createLazyFieldComponent<FieldInputProps>(
  'LazyMarkdownFieldInput',
  async () => {
    const module = await loadMarkdownField()
    return { default: module.MarkdownFieldInput }
  },
)

const LazyTrixFieldDisplay = createLazyFieldComponent<FieldDisplayProps>(
  'LazyTrixFieldDisplay',
  async () => {
    const module = await loadTrixField()
    return { default: module.TrixFieldDisplay }
  },
)

const LazyTrixFieldInput = createLazyFieldComponent<FieldInputProps>(
  'LazyTrixFieldInput',
  async () => {
    const module = await loadTrixField()
    return { default: module.TrixFieldInput }
  },
)

const LazyHasManyFieldDisplay = createLazyFieldComponent<FieldDisplayProps>(
  'LazyHasManyFieldDisplay',
  async () => {
    const module = await loadHasManyField()
    return { default: module.HasManyFieldDisplay }
  },
)

const LazyHasManyFieldInput = createLazyFieldComponent<FieldInputProps>(
  'LazyHasManyFieldInput',
  async () => {
    const module = await loadHasManyField()
    return { default: module.HasManyFieldInput }
  },
)

const LazyBelongsToManyFieldDisplay = createLazyFieldComponent<FieldDisplayProps>(
  'LazyBelongsToManyFieldDisplay',
  async () => {
    const module = await loadBelongsToManyField()
    return { default: module.BelongsToManyFieldDisplay }
  },
)

const LazyBelongsToManyFieldInput = createLazyFieldComponent<FieldInputProps>(
  'LazyBelongsToManyFieldInput',
  async () => {
    const module = await loadBelongsToManyField()
    return { default: module.BelongsToManyFieldInput }
  },
)

const LazyMorphManyFieldDisplay = createLazyFieldComponent<FieldDisplayProps>(
  'LazyMorphManyFieldDisplay',
  async () => {
    const module = await loadMorphManyField()
    return { default: module.MorphManyFieldDisplay }
  },
)

const LazyMorphManyFieldInput = createLazyFieldComponent<FieldInputProps>(
  'LazyMorphManyFieldInput',
  async () => {
    const module = await loadMorphManyField()
    return { default: module.MorphManyFieldInput }
  },
)

const LazyMorphToManyFieldDisplay = createLazyFieldComponent<FieldDisplayProps>(
  'LazyMorphToManyFieldDisplay',
  async () => {
    const module = await loadMorphToManyField()
    return { default: module.MorphToManyFieldDisplay }
  },
)

const LazyMorphToManyFieldInput = createLazyFieldComponent<FieldInputProps>(
  'LazyMorphToManyFieldInput',
  async () => {
    const module = await loadMorphToManyField()
    return { default: module.MorphToManyFieldInput }
  },
)

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
  key_value: KeyValueFieldDisplay,
  badge: BadgeFieldDisplay,
  status: StatusFieldDisplay,
  multi_select: MultiSelectFieldDisplay,
  tag: TagFieldDisplay,
  url: UrlFieldDisplay,
  code: LazyCodeFieldDisplay,
  color: ColorFieldDisplay,
  markdown: LazyMarkdownFieldDisplay,
  trix: LazyTrixFieldDisplay,
  country: CountryFieldDisplay,
  currency: CurrencyFieldDisplay,
  sparkline: SparklineFieldDisplay,
  gravatar: GravatarFieldDisplay,
  has_many: LazyHasManyFieldDisplay,
  belongs_to_many: LazyBelongsToManyFieldDisplay,
  morph_to: MorphToFieldDisplay,
  morph_many: LazyMorphManyFieldDisplay,
  morph_one: MorphOneFieldDisplay,
  morph_to_many: LazyMorphToManyFieldDisplay,
  has_one: HasOneFieldDisplay,
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
  key_value: KeyValueFieldInput,
  badge: BadgeFieldInput,
  status: StatusFieldInput,
  multi_select: MultiSelectFieldInput,
  tag: TagFieldInput,
  url: UrlFieldInput,
  code: LazyCodeFieldInput,
  color: ColorFieldInput,
  markdown: LazyMarkdownFieldInput,
  trix: LazyTrixFieldInput,
  country: CountryFieldInput,
  currency: CurrencyFieldInput,
  sparkline: SparklineFieldInput,
  gravatar: GravatarFieldInput,
  has_many: LazyHasManyFieldInput,
  belongs_to_many: LazyBelongsToManyFieldInput,
  morph_to: MorphToFieldInput,
  morph_many: LazyMorphManyFieldInput,
  morph_one: MorphOneFieldInput,
  morph_to_many: LazyMorphToManyFieldInput,
  has_one: HasOneFieldInput,
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
 *   0. field.overrides[context] (per-context override from PHP field->overrideIndex/Detail)
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
  context,
}: {
  field: FieldDefinition
  value: unknown
  resourceKey?: string
  context?: 'index' | 'detail'
}) {
  // Tier 0: per-context field override (from PHP field->overrideIndex/Detail)
  const contextOverride = context ? field.overrides?.[context] : undefined
  const explicitKey = contextOverride?.component ?? field.component

  const Component = componentRegistry.resolveDisplay(
    field.type,
    field.attribute,
    resourceKey,
    explicitKey,
    getFallbackDisplay(field.type),
  )
  return <Component field={field} value={value} />
}

/**
 * Renders a field as an editable input (create / update forms).
 *
 * Override resolution follows the same 5-tier chain as FieldDisplay (with context-aware Tier 0).
 *
 * @param resourceKey  The resource URI key — enables per-resource overrides
 */
export function FieldInput({
  field,
  value,
  onChange,
  error,
  resourceKey,
  recordId,
  context,
}: {
  field: FieldDefinition
  value: unknown
  onChange: (v: unknown) => void
  error?: string
  resourceKey?: string
  recordId?: string | number
  context?: 'create' | 'update'
}) {
  // Tier 0: per-context field override (from PHP field->overrideCreate/Update)
  const contextOverride = context ? field.overrides?.[context] : undefined
  const explicitKey = contextOverride?.component ?? field.component

  const Component = componentRegistry.resolveInput(
    field.type,
    field.attribute,
    resourceKey,
    explicitKey,
    getFallbackInput(field.type),
  )
  return <Component field={field} value={value} onChange={onChange} error={error} resourceKey={resourceKey} recordId={recordId} />
}
