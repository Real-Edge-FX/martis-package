import { useState } from 'react'
import { CaretDownIcon, CaretRightIcon } from '@phosphor-icons/react'
import { useTranslation } from 'react-i18next'
import type { PanelDefinition, FieldDefinition } from '@/types'
import { FieldDisplay, FieldInput } from './FieldRenderer'
import { FieldLabelTooltip } from './FieldLabelTooltip'

// -------------------------------------------------------------------------
// Panel — shared internal container
// -------------------------------------------------------------------------

interface PanelContainerProps {
  panel: PanelDefinition
  children: (fields: FieldDefinition[]) => React.ReactNode
}

function PanelContainer({ panel, children }: PanelContainerProps) {
  const { t } = useTranslation('martis')
  const [collapsed, setCollapsed] = useState(panel.collapsedByDefault)
  const [expanded, setExpanded] = useState(false)

  const hasLimit = panel.limit !== null && panel.limit > 0
  const visibleFields = hasLimit && !expanded
    ? panel.fields.slice(0, panel.limit!)
    : panel.fields
  const hiddenCount = panel.fields.length - (panel.limit ?? panel.fields.length)

  const panelId = `panel-content-${panel.title.toLowerCase().replace(/\s+/g, '-')}`

  return (
    <div className="rounded-lg" style={{ border: '1px solid var(--martis-border)', backgroundColor: 'var(--martis-surface)' }}>
      {/* Panel header */}
      <div
        className={[
          'flex items-center justify-between px-4 py-3',
          panel.collapsible ? 'cursor-pointer select-none transition-colors' : '',
        ].join(' ')}
        style={{ borderBottom: '1px solid var(--martis-border)', backgroundColor: 'var(--martis-hover)' }}
        onClick={panel.collapsible ? () => setCollapsed((c: boolean) => !c) : undefined}
        role={panel.collapsible ? 'button' : undefined}
        aria-expanded={panel.collapsible ? !collapsed : undefined}
        aria-controls={panel.collapsible ? panelId : undefined}
        tabIndex={panel.collapsible ? 0 : undefined}
        onKeyDown={panel.collapsible ? (e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault()
            setCollapsed((c: boolean) => !c)
          }
        } : undefined}
      >
        <div>
          <h3 className="text-sm font-semibold" style={{ color: 'var(--martis-text)' }}>{panel.title}</h3>
          {panel.description && (
            <p className="text-xs mt-0.5" style={{ color: 'var(--martis-text-muted)' }}>{panel.description}</p>
          )}
        </div>
        {panel.collapsible && (
          <span style={{ color: 'var(--martis-text-muted)' }} aria-hidden="true">
            {collapsed ? <CaretRightIcon size={16} /> : <CaretDownIcon size={16} />}
          </span>
        )}
      </div>

      {/* Panel content */}
      {!collapsed && (
        <div id={panelId} className="p-4">
          {children(visibleFields)}

          {/* Show more / show less toggle */}
          {hasLimit && hiddenCount > 0 && (
            <button
              type="button"
              className="mt-3 text-xs font-medium transition-colors"
              style={{ color: 'var(--martis-accent)' }}
              onClick={() => setExpanded((e: boolean) => !e)}
            >
              {expanded ? t('show_less') : t('show_more')}
            </button>
          )}
        </div>
      )}
    </div>
  )
}

// -------------------------------------------------------------------------
// Display mode (detail / index)
// -------------------------------------------------------------------------

export function PanelDisplay({
  panel,
  values,
  resourceKey,
}: {
  panel: PanelDefinition
  values: Record<string, unknown>
  resourceKey?: string
}) {
  return (
    <PanelContainer panel={panel}>
      {(fields) => (
        <dl className="grid grid-cols-12 gap-4">
          {fields.map((field) => (
            <div
              key={field.attribute}
              className="col-span-12"
              style={{
                gridColumn: field.colSpan ? `span ${field.colSpan}` : 'span 12',
              }}
            >
              <dt className="text-xs font-medium mb-1" style={{ color: 'var(--martis-text-muted)' }}>{field.label}</dt>
              <dd>
                <FieldDisplay
                  field={field}
                  value={values[field.attribute]}
                  resourceKey={resourceKey}
                  context="detail"
                />
              </dd>
            </div>
          ))}
        </dl>
      )}
    </PanelContainer>
  )
}

// -------------------------------------------------------------------------
// Input mode (create / update)
// -------------------------------------------------------------------------

export function PanelInput({
  panel,
  values,
  onChange,
  errors,
  resourceKey,
  recordId,
  context,
}: {
  panel: PanelDefinition
  values: Record<string, unknown>
  onChange: (attribute: string, value: unknown) => void
  errors: Record<string, string>
  resourceKey?: string
  recordId?: string | number
  context?: 'create' | 'update'
}) {
  return (
    <PanelContainer panel={panel}>
      {(fields) => (
        <div className="grid grid-cols-12 gap-4">
          {fields.map((field) => (
            <div
              key={field.attribute}
              className="col-span-12 flex flex-col gap-1.5"
              style={{
                gridColumn: field.colSpan ? `span ${field.colSpan}` : 'span 12',
              }}
            >
              <label
                htmlFor={field.attribute}
                className="block text-sm font-medium"
                style={{ color: 'var(--martis-text-muted)' }}
              >
                {field.label}
                {field.required && (
                  <span className="ml-1 text-red-500" aria-hidden="true">*</span>
                )}
                <FieldLabelTooltip text={field.tooltip} />
              </label>
              <FieldInput
                field={field}
                value={values[field.attribute]}
                onChange={(v) => onChange(field.attribute, v)}
                error={errors[field.attribute]}
                resourceKey={resourceKey}
                recordId={recordId}
                context={context}
                formValues={values}
              />
              {field.helpText && (
                <p className="mt-1 text-xs" style={{ color: 'var(--martis-text-muted)' }} dangerouslySetInnerHTML={{ __html: field.helpText }} />
              )}
            </div>
          ))}
        </div>
      )}
    </PanelContainer>
  )
}
