import type { JSX } from 'react'
import type { FieldDefinition, PanelDefinition, SectionDefinition, TabGroupDefinition } from '@/types'
import { FieldInput } from '@/components/fields/FieldRenderer'
import { PanelInput } from '@/components/fields/PanelRenderer'
import { SectionInput } from '@/components/fields/SectionRenderer'
import { TabsInput } from '@/components/fields/TabsRenderer'
import { FieldWrapper } from '@/components/fields/FieldWrapper'
import type { MartisForm } from '@/hooks/useMartisForm'

export interface FieldsFormProps {
  /** Controlled form — caller owns the form state via `useMartisForm`. */
  form: MartisForm
  context?: 'create' | 'update'
}

/**
 * Renders `form.resolvedFields` in declaration order — layout containers
 * (`tab_group` / `section` / `panel`) and scalar fields interleaved — the
 * same render loop `ResourceCreatePage` used to own directly (see
 * `ResourceCreate.tsx` ~L432-475), now driven entirely by `useMartisForm`'s
 * `resolvedFields` + `fieldProps`.
 */
export function FieldsForm({ form, context = 'create' }: FieldsFormProps): JSX.Element {
  const { resolvedFields, values, errors, setValue } = form
  const handleChange = (attribute: string, value: unknown) => setValue(attribute, value)
  const resourceKey = form.fieldProps(resolvedFields[0] ?? ({} as FieldDefinition)).resourceKey

  return (
    <div className="martis-form-body martis-form-stack">
      {resolvedFields.map((raw, idx) => {
        const item = raw as { type?: string } & Record<string, unknown>
        if (item.type === 'tab_group') {
          return (
            <TabsInput
              key={idx}
              tabGroup={item as unknown as TabGroupDefinition}
              values={values}
              onChange={handleChange}
              errors={errors}
              resourceKey={resourceKey}
              context={context}
            />
          )
        }
        if (item.type === 'section') {
          return (
            <SectionInput
              key={idx}
              section={item as unknown as SectionDefinition}
              values={values}
              onChange={handleChange}
              errors={errors}
              resourceKey={resourceKey}
              context={context}
            />
          )
        }
        if (item.type === 'panel') {
          return (
            <PanelInput
              key={idx}
              panel={item as unknown as PanelDefinition}
              values={values}
              onChange={handleChange}
              errors={errors}
              resourceKey={resourceKey}
              context={context}
            />
          )
        }
        const field = raw as FieldDefinition
        const props = form.fieldProps(field)
        return (
          <FieldWrapper
            key={field.attribute}
            htmlFor={field.attribute}
            label={field.label}
            required={field.required}
            tooltip={field.tooltip}
            help={field.helpText}
          >
            <FieldInput {...props} context={context} />
          </FieldWrapper>
        )
      })}
    </div>
  )
}
