import { useState } from 'react'
import type { TabGroupDefinition, TabDefinition, FieldDefinition, PanelDefinition } from '@/types'
import { FieldDisplay, FieldInput } from './FieldRenderer'
import { PanelDisplay, PanelInput } from './PanelRenderer'

// -------------------------------------------------------------------------
// Tab navigation bar
// -------------------------------------------------------------------------

function TabBar({
  tabs,
  activeIndex,
  onSelect,
}: {
  tabs: TabDefinition[]
  activeIndex: number
  onSelect: (i: number) => void
}) {
  return (
    <div
      role="tablist"
      aria-label="Resource tabs"
      className="flex px-1 pt-1 gap-0.5 overflow-x-auto overflow-y-hidden"
      style={{ borderBottom: '1px solid var(--martis-border)', backgroundColor: 'var(--martis-hover)' }}
    >
      {tabs.map((tab, i) => (
        <button
          type="button"
          key={tab.title}
          role="tab"
          aria-selected={i === activeIndex}
          aria-controls={`tabpanel-${i}`}
          id={`tab-${i}`}
          tabIndex={i === activeIndex ? 0 : -1}
          className="px-4 py-2 text-sm font-medium rounded-t-md whitespace-nowrap transition-colors focus-visible:outline-none"
          style={{
            color: i === activeIndex ? 'var(--martis-text)' : 'var(--martis-text-muted)',
            backgroundColor: i === activeIndex ? 'var(--martis-surface)' : 'transparent',
            border: i === activeIndex ? '1px solid var(--martis-border)' : '1px solid transparent',
            borderBottom: i === activeIndex ? '1px solid var(--martis-surface)' : '1px solid transparent',
            marginBottom: i === activeIndex ? '-1px' : undefined,
          }}
          onClick={() => onSelect(i)}
          onKeyDown={(e) => {
            if (e.key === 'ArrowRight') { onSelect((i + 1) % tabs.length) }
            if (e.key === 'ArrowLeft') { onSelect((i - 1 + tabs.length) % tabs.length) }
            if (e.key === 'Home') { onSelect(0) }
            if (e.key === 'End') { onSelect(tabs.length - 1) }
          }}
        >
          {tab.title}
        </button>
      ))}
    </div>
  )
}

// -------------------------------------------------------------------------
// Type guard helpers
// -------------------------------------------------------------------------

function isPanelDefinition(item: FieldDefinition | PanelDefinition): item is PanelDefinition {
  return (item as PanelDefinition).type === 'panel'
}

// -------------------------------------------------------------------------
// Display mode (detail)
// -------------------------------------------------------------------------

export function TabsDisplay({
  tabGroup,
  values,
  resourceKey,
}: {
  tabGroup: TabGroupDefinition
  values: Record<string, unknown>
  resourceKey?: string
}) {
  const [activeIndex, setActiveIndex] = useState(0)
  const { tabs } = tabGroup
  const activeTab = tabs[activeIndex]

  if (!activeTab) return null

  return (
    <div className="rounded-lg" style={{ border: '1px solid var(--martis-border)', backgroundColor: 'var(--martis-surface)' }}>
      <TabBar tabs={tabs} activeIndex={activeIndex} onSelect={setActiveIndex} />

      <div
        role="tabpanel"
        id={`tabpanel-${activeIndex}`}
        aria-labelledby={`tab-${activeIndex}`}
        className="p-4"
      >
        <div className="grid grid-cols-12 gap-4">
          {activeTab.fields.map((item: FieldDefinition | PanelDefinition) => {
            if (isPanelDefinition(item)) {
              return (
                <div key={item.title} className="col-span-12">
                  <PanelDisplay panel={item} values={values} resourceKey={resourceKey} />
                </div>
              )
            }
            const field = item as FieldDefinition
            return (
              <div
                key={field.attribute}
                className="col-span-12"
                style={{ gridColumn: field.colSpan ? `span ${field.colSpan}` : 'span 12' }}
              >
                <dl>
                  <dt className="text-xs font-medium mb-1" style={{ color: 'var(--martis-text-muted)' }}>{field.label}</dt>
                  <dd>
                    <FieldDisplay
                      field={field}
                      value={values[field.attribute]}
                      resourceKey={resourceKey}
                      context="detail"
                    />
                  </dd>
                </dl>
              </div>
            )
          })}
        </div>
      </div>
    </div>
  )
}

// -------------------------------------------------------------------------
// Input mode (create / update)
// -------------------------------------------------------------------------

export function TabsInput({
  tabGroup,
  values,
  onChange,
  errors,
  resourceKey,
  recordId,
  context,
}: {
  tabGroup: TabGroupDefinition
  values: Record<string, unknown>
  onChange: (attribute: string, value: unknown) => void
  errors: Record<string, string>
  resourceKey?: string
  recordId?: string | number
  context?: 'create' | 'update'
}) {
  const [activeIndex, setActiveIndex] = useState(0)
  const { tabs } = tabGroup
  const activeTab = tabs[activeIndex]

  if (!activeTab) return null

  return (
    <div className="rounded-lg" style={{ border: '1px solid var(--martis-border)', backgroundColor: 'var(--martis-surface)' }}>
      <TabBar tabs={tabs} activeIndex={activeIndex} onSelect={setActiveIndex} />

      <div
        role="tabpanel"
        id={`tabpanel-${activeIndex}`}
        aria-labelledby={`tab-${activeIndex}`}
        className="p-4"
      >
        <div className="grid grid-cols-12 gap-4">
          {activeTab.fields.map((item: FieldDefinition | PanelDefinition) => {
            if (isPanelDefinition(item)) {
              return (
                <div key={item.title} className="col-span-12">
                  <PanelInput
                    panel={item}
                    values={values}
                    onChange={onChange}
                    errors={errors}
                    resourceKey={resourceKey}
                    recordId={recordId}
                    context={context}
                  />
                </div>
              )
            }
            const field = item as FieldDefinition
            return (
              <div
                key={field.attribute}
                className="col-span-12 flex flex-col gap-1.5"
                style={{ gridColumn: field.colSpan ? `span ${field.colSpan}` : 'span 12' }}
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
            )
          })}
        </div>
      </div>
    </div>
  )
}
