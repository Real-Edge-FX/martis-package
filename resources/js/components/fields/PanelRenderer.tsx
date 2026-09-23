import { useState } from 'react'
import { CaretDownIcon, CaretRightIcon } from '@phosphor-icons/react'
import { useTranslation } from 'react-i18next'
import type { PanelDefinition, FieldDefinition } from '@/types'
import { fieldGridSpanStyle, fieldGridStyle } from '@/lib/fieldGridSpan'
import { FieldDisplay, FieldInput } from './FieldRenderer'
import { FieldWrapper } from './FieldWrapper'

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
          'flex items-center justify-between px-4 py-2',
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
        <div id={panelId} className="px-4 py-3">
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
// Grid placement
// -------------------------------------------------------------------------
//
// The panel body is a 12-track `.martis-field-grid`. Each field only carries
// its colSpan / colSpanMd / colSpanLg cascade as custom properties
// (lib/fieldGridSpan.ts); martis.css owns `grid-column` per breakpoint, full
// row below md.

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
        <dl className="martis-field-grid martis-form-grid" style={fieldGridStyle()}>
          {fields.map((field) => (
            <div key={field.attribute} style={fieldGridSpanStyle(field)}>
              <dt className="martis-detail-label mb-1">{field.label}</dt>
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
  toolKey,
  context,
}: {
  panel: PanelDefinition
  values: Record<string, unknown>
  onChange: (attribute: string, value: unknown) => void
  errors: Record<string, string>
  resourceKey?: string
  recordId?: string | number
  toolKey?: string
  context?: 'create' | 'update'
}) {
  return (
    <PanelContainer panel={panel}>
      {(fields) => (
        <div className="martis-field-grid martis-form-grid" style={fieldGridStyle()}>
          {fields.map((field) => (
            <div key={field.attribute} style={fieldGridSpanStyle(field)}>
              <FieldWrapper
                htmlFor={field.attribute}
                label={field.label}
                required={field.required}
                tooltip={field.tooltip}
                help={field.helpText}
              >
                <FieldInput
                  field={field}
                  value={values[field.attribute]}
                  onChange={(v) => onChange(field.attribute, v)}
                  error={errors[field.attribute]}
                  resourceKey={resourceKey}
                  recordId={recordId}
                  toolKey={toolKey}
                  context={context}
                  formValues={values}
                />
              </FieldWrapper>
            </div>
          ))}
        </div>
      )}
    </PanelContainer>
  )
}
