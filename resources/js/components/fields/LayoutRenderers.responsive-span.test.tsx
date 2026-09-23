import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import type { FieldDefinition, PanelDefinition, SectionDefinition, TabGroupDefinition } from '@/types'

/*
 * `colSpanMd()` / `colSpanLg()` were serialised by `Field::toArray()` but the
 * Section, Panel and Tab bodies only wrote `grid-column: span {colSpan}`
 * inline, and only the Section grid collapsed on phones. Every form and
 * detail field grid now carries the `.martis-field-grid` class (martis.css
 * owns `grid-column` per breakpoint: full row below md, then the colSpan
 * cascade) and each grid item only carries its resolved tiers as custom
 * properties, so no inline `grid-column` is left for a theme to fight.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('./FieldRenderer', () => ({
  FieldInput: ({ field }: { field: { attribute: string } }) => <input data-testid={`input-${field.attribute}`} />,
  FieldDisplay: ({ field }: { field: { attribute: string } }) => <span data-testid={`display-${field.attribute}`} />,
}))

import { SectionDisplay, SectionInput } from './SectionRenderer'
import { PanelDisplay, PanelInput } from './PanelRenderer'
import { TabsDisplay, TabsInput } from './TabsRenderer'

type Spans = { colSpan?: number; colSpanMd?: number | null; colSpanLg?: number | null }

function field(attribute: string, spans: Spans = {}): FieldDefinition {
  // Field::toArray() always serialises colSpan (12 unless set) and null tiers.
  return { attribute, label: attribute, type: 'text', colSpan: 12, colSpanMd: null, colSpanLg: null, ...spans } as unknown as FieldDefinition
}

function section(fields: FieldDefinition[], columns = 12): SectionDefinition {
  return { type: 'section', title: 'Details', fields, columns, collapsible: false, collapsedByDefault: false, limit: null }
}

function panel(title: string, fields: FieldDefinition[]): PanelDefinition {
  return { type: 'panel', title, fields, collapsible: false, collapsedByDefault: false, limit: null }
}

function tabs(fields: (FieldDefinition | PanelDefinition)[]): TabGroupDefinition {
  return { type: 'tab_group', tabs: [{ title: 'General', fields }] }
}

/** The grid item (a direct child of the field grid) that holds the element. */
function gridItem(testId: string): HTMLElement {
  const item = screen.getByTestId(testId).closest('.martis-field-grid > *')
  expect(item).not.toBeNull()
  return item as HTMLElement
}

/** The field grid that places the element. */
function gridOf(testId: string): HTMLElement {
  return gridItem(testId).parentElement as HTMLElement
}

function expectTiers(item: HTMLElement, base: string, md: string, lg: string): void {
  expect(item.style.getPropertyValue('--martis-field-span')).toBe(base)
  expect(item.style.getPropertyValue('--martis-field-span-md')).toBe(md)
  expect(item.style.getPropertyValue('--martis-field-span-lg')).toBe(lg)
  // Placement belongs to .martis-field-grid (martis.css), never inline.
  expect(item.style.gridColumn).toBe('')
  expect(item.getAttribute('style') ?? '').not.toMatch(/grid-column/)
  expect(item.className).not.toMatch(/col-span/)
}

function expectGrid(grid: HTMLElement, columns: string): void {
  expect(grid.classList.contains('martis-field-grid')).toBe(true)
  expect(grid.style.getPropertyValue('--martis-field-columns')).toBe(columns)
  // The tracks come from the class too, so a theme can change them.
  expect(grid.style.gridTemplateColumns).toBe('')
  expect(grid.className).not.toMatch(/grid-cols-/)
}

const noop = () => {}

describe('Section grid responsive spans (v1.38.0+)', () => {
  const fields = [
    field('name', { colSpan: 12, colSpanMd: 6, colSpanLg: 4 }),
    field('email', { colSpan: 6 }),
  ]

  it('carries the colSpan cascade of each field on a create/update form', () => {
    render(<SectionInput section={section(fields)} values={{}} onChange={noop} errors={{}} />)

    expectGrid(gridOf('input-name'), '12')
    expect(gridOf('input-name').classList.contains('martis-section-grid')).toBe(true)
    expectTiers(gridItem('input-name'), '12', '6', '4')
    expectTiers(gridItem('input-email'), '6', '6', '6')
  })

  it('carries the colSpan cascade of each field on a detail view', () => {
    render(<SectionDisplay section={section(fields)} values={{}} />)

    expectGrid(gridOf('display-name'), '12')
    expectTiers(gridItem('display-name'), '12', '6', '4')
    expectTiers(gridItem('display-email'), '6', '6', '6')
  })

  it('resolves the spans against Section::columns(), so a field without span() is one full row', () => {
    const narrow = section([
      field('title'),
      field('city', { colSpan: 1, colSpanMd: 2 }),
    ], 3)
    render(<SectionInput section={narrow} values={{}} onChange={noop} errors={{}} />)

    expectGrid(gridOf('input-title'), '3')
    expectTiers(gridItem('input-title'), '3', '3', '3')
    expectTiers(gridItem('input-city'), '1', '2', '2')
  })
})

describe('Panel grid responsive spans (v1.38.0+)', () => {
  const fields = [
    field('starts_at', { colSpan: 12, colSpanMd: 6 }),
    field('notes'),
  ]

  it('carries the colSpan cascade of each field on a create/update form', () => {
    render(<PanelInput panel={panel('Timeline', fields)} values={{}} onChange={noop} errors={{}} />)

    expectGrid(gridOf('input-starts_at'), '12')
    expectTiers(gridItem('input-starts_at'), '12', '6', '6')
    expectTiers(gridItem('input-notes'), '12', '12', '12')
  })

  it('carries the colSpan cascade of each field on a detail view', () => {
    render(<PanelDisplay panel={panel('Timeline', fields)} values={{}} />)

    expectGrid(gridOf('display-starts_at'), '12')
    expectTiers(gridItem('display-starts_at'), '12', '6', '6')
    expectTiers(gridItem('display-notes'), '12', '12', '12')
  })
})

describe('Tab grid responsive spans (v1.38.0+)', () => {
  const tabGroup = tabs([
    field('status', { colSpan: 6, colSpanLg: 3 }),
    panel('Audit', [field('reviewed_by', { colSpan: 12, colSpanMd: 4 })]),
  ])

  it('carries the colSpan cascade on a create/update form, a nested panel taking the full row', () => {
    render(<TabsInput tabGroup={tabGroup} values={{}} onChange={noop} errors={{}} />)

    const tabGrid = gridOf('input-status')
    expectGrid(tabGrid, '12')
    expectTiers(gridItem('input-status'), '6', '6', '3')

    // The nested panel is an item of the tab grid (full row at every tier)
    // and lays its own fields out on its own grid.
    const panelGrid = gridOf('input-reviewed_by')
    expect(panelGrid).not.toBe(tabGrid)
    expectGrid(panelGrid, '12')
    expectTiers(gridItem('input-reviewed_by'), '12', '4', '4')
    const panelItem = Array.from(tabGrid.children).find((child) => child.contains(panelGrid)) as HTMLElement
    expectTiers(panelItem, '12', '12', '12')
  })

  it('carries the colSpan cascade on a detail view', () => {
    render(<TabsDisplay tabGroup={tabGroup} values={{}} />)

    expectGrid(gridOf('display-status'), '12')
    expectTiers(gridItem('display-status'), '6', '6', '3')
    expectTiers(gridItem('display-reviewed_by'), '12', '4', '4')
  })
})
