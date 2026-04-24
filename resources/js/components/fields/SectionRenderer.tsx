import { useState } from 'react'
import { CaretDownIcon, CaretRightIcon } from '@phosphor-icons/react'
import { useTranslation } from 'react-i18next'
import type { SectionDefinition, FieldDefinition } from '@/types'
import { FieldDisplay, FieldInput } from './FieldRenderer'
import { FieldLabelTooltip } from './FieldLabelTooltip'
import { FieldWrapper } from './FieldWrapper'

// -------------------------------------------------------------------------
// Section — shared internal container
// -------------------------------------------------------------------------

interface SectionContainerProps {
  section: SectionDefinition
  children: (fields: FieldDefinition[]) => React.ReactNode
}

function SectionContainer({ section, children }: SectionContainerProps) {
  const { t } = useTranslation('martis')
  const [collapsed, setCollapsed] = useState(section.collapsedByDefault)
  const [expanded, setExpanded] = useState(false)

  const hasLimit = section.limit !== null && section.limit > 0
  const visibleFields = hasLimit && !expanded
    ? section.fields.slice(0, section.limit!)
    : section.fields
  const hiddenCount = section.fields.length - (section.limit ?? section.fields.length)

  const sectionId = `section-content-${(section.title ?? 'unnamed').toLowerCase().replace(/\s+/g, '-')}`

  return (
    // overflow-visible so PrimeReact dropdown panels (portalled to body) are not
    // clipped by the card boundary on browsers that create a block formatting context.
    <div className="rounded-lg" style={{ border: '1px solid var(--martis-border)', backgroundColor: 'var(--martis-surface)' }}>
      {/* Section header — only rendered when title is non-empty */}
      {section.title && (
        <div
          className={[
            'flex items-center justify-between px-4 py-2 rounded-t-lg',
            section.collapsible ? 'cursor-pointer select-none transition-colors' : '',
          ].join(' ')}
          style={{ borderBottom: '1px solid var(--martis-border)', backgroundColor: 'var(--martis-hover)' }}
          onClick={section.collapsible ? () => setCollapsed((c: boolean) => !c) : undefined}
          role={section.collapsible ? 'button' : undefined}
          aria-expanded={section.collapsible ? !collapsed : undefined}
          aria-controls={section.collapsible ? sectionId : undefined}
          tabIndex={section.collapsible ? 0 : undefined}
          onKeyDown={section.collapsible ? (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault()
              setCollapsed((c: boolean) => !c)
            }
          } : undefined}
        >
          <div>
            <h3 className="text-sm font-semibold" style={{ color: 'var(--martis-text)' }}>{section.title}</h3>
            {section.description && (
              <p className="text-xs mt-0.5" style={{ color: 'var(--martis-text-muted)' }}>{section.description}</p>
            )}
          </div>
          {section.collapsible && (
            <span style={{ color: 'var(--martis-text-muted)' }} aria-hidden="true">
              {collapsed ? <CaretRightIcon size={16} /> : <CaretDownIcon size={16} />}
            </span>
          )}
        </div>
      )}

      {/* Section content */}
      {!collapsed && (
        <div id={sectionId} className="px-4 py-3">
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
// Span resolution helper
// -------------------------------------------------------------------------

/**
 * Computes inline gridColumn style for a field inside a Section.
 *
 * Breakpoint handling is done by .martis-section-grid in martis.css:
 * on mobile (< md) the CSS rule `grid-column: 1 / -1` overrides all spans.
 */
function fieldGridColumn(field: FieldDefinition, sectionColumns: number): string {
  const span = field.colSpan ?? sectionColumns
  return `span ${span}`
}

// -------------------------------------------------------------------------
// Input mode (create / update) — primary use-case for Section
// -------------------------------------------------------------------------

export function SectionInput({
  section,
  values,
  onChange,
  errors,
  resourceKey,
  recordId,
  context,
}: {
  section: SectionDefinition
  values: Record<string, unknown>
  onChange: (attribute: string, value: unknown) => void
  errors: Record<string, string>
  resourceKey?: string
  recordId?: string | number
  context?: 'create' | 'update'
}) {
  return (
    <SectionContainer section={section}>
      {(fields) => (
        <div
          className="martis-section-grid grid gap-4"
          style={{ gridTemplateColumns: `repeat(${section.columns}, minmax(0, 1fr))` }}
        >
          {fields.map((field) => (
            <div
              key={field.attribute}
              style={{ gridColumn: fieldGridColumn(field, section.columns) }}
            >
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
                  context={context}
                  formValues={values}
                />
              </FieldWrapper>
            </div>
          ))}
        </div>
      )}
    </SectionContainer>
  )
}

// -------------------------------------------------------------------------
// Display mode (detail) — read-only rendering
// -------------------------------------------------------------------------

export function SectionDisplay({
  section,
  values,
  resourceKey,
}: {
  section: SectionDefinition
  values: Record<string, unknown>
  resourceKey?: string
}) {
  return (
    <SectionContainer section={section}>
      {(fields) => (
        <dl
          className="martis-section-grid grid gap-4"
          style={{ gridTemplateColumns: `repeat(${section.columns}, minmax(0, 1fr))` }}
        >
          {fields.map((field) => (
            <div
              key={field.attribute}
              style={{ gridColumn: fieldGridColumn(field, section.columns) }}
            >
              <dt className="text-xs font-medium mb-1" style={{ color: 'var(--martis-text-muted)' }}>{field.label}<FieldLabelTooltip text={field.tooltip} /></dt>
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
    </SectionContainer>
  )
}
