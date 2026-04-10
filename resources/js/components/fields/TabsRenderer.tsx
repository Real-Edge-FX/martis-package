import { useState } from 'react'
import type { TabGroupDefinition, TabDefinition, FieldDefinition, PanelDefinition } from '@/types'
import { FieldDisplay, FieldInput } from './index'
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
      className="flex border-b border-border bg-muted/30 px-1 pt-1 gap-0.5 overflow-x-auto"
    >
      {tabs.map((tab, i) => (
        <button
          key={tab.title}
          role="tab"
          aria-selected={i === activeIndex}
          aria-controls={`tabpanel-${i}`}
          id={`tab-${i}`}
          tabIndex={i === activeIndex ? 0 : -1}
          className={[
            'px-4 py-2 text-sm font-medium rounded-t-md border border-transparent whitespace-nowrap transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            i === activeIndex
              ? 'bg-card border-border border-b-card -mb-px text-foreground'
              : 'text-muted-foreground hover:text-foreground hover:bg-card/60',
          ].join(' ')}
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
    <div className="border border-border rounded-lg overflow-hidden bg-card">
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
                  <dt className="text-xs font-medium text-muted-foreground mb-1">{field.label}</dt>
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
    <div className="border border-border rounded-lg overflow-hidden bg-card">
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
                className="col-span-12"
                style={{ gridColumn: field.colSpan ? `span ${field.colSpan}` : 'span 12' }}
              >
                <FieldInput
                  field={field}
                  value={values[field.attribute]}
                  onChange={(v) => onChange(field.attribute, v)}
                  error={errors[field.attribute]}
                  resourceKey={resourceKey}
                  recordId={recordId}
                  context={context}
                />
              </div>
            )
          })}
        </div>
      </div>
    </div>
  )
}
