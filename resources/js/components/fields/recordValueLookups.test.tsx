import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { BadgeFieldDisplay } from './BadgeField'
import { MultiSelectFieldDisplay } from './MultiSelectField'
import { IconFieldDisplay } from './IconField'
import { resolveBadgeStyle, BADGE_DEFAULT_STYLE } from './badgeStyles'

/*
 * Same root cause as the icon registry finding (F035): a field display looked
 * a stored value up in a plain-object table (`map`, `labels`, `colorMap`,
 * ...) with `table[value]`, which also finds what every object inherits. A
 * value spelled `constructor` or `__proto__` resolved to `Object` or
 * `Object.prototype`, and rendering that threw ("Objects are not valid as a
 * React child") or hid the value. Only the table's own entries count now.
 */

function field(type: string, extra: Record<string, unknown> = {}): FieldDefinition {
  return {
    attribute: 'status', label: 'Status', type,
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: true, showOnDetail: true, showOnForms: false,
    rules: [], ...extra,
  } as unknown as FieldDefinition
}

const INHERITED = ['constructor', '__proto__', 'toString', 'valueOf', 'hasOwnProperty']

describe('BadgeFieldDisplay with a stored value spelled like an inherited member', () => {
  it.each(INHERITED)('shows %s as its own text, with the default style', (value) => {
    const { container } = render(<BadgeFieldDisplay field={field('badge', { map: { active: 'success' }, labels: { active: 'Active' }, types: {}, icons: {}, withIcons: true })} value={value} />)

    expect(container.textContent).toBe(value)
    expect((container.querySelector('.martis-badge') as HTMLElement).style.backgroundColor).toBe('var(--martis-surface-alt)')
  })

  it('still resolves a mapped value', () => {
    const { container } = render(<BadgeFieldDisplay field={field('badge', { map: { active: 'success' }, labels: { active: 'Active' } })} value="active" />)

    expect(container.textContent).toBe('Active')
    expect((container.querySelector('.martis-badge') as HTMLElement).style.backgroundColor).toBe('var(--martis-badge-success-bg)')
  })
})

describe('resolveBadgeStyle with a colour spelled like an inherited member', () => {
  it.each(INHERITED)('falls back to the default style for %s', (color) => {
    expect(resolveBadgeStyle(color)).toBe(BADGE_DEFAULT_STYLE)
  })
})

describe('MultiSelectFieldDisplay with a stored value spelled like an inherited member', () => {
  it.each(INHERITED)('renders %s as a neutral chip', (value) => {
    const { container } = render(<MultiSelectFieldDisplay field={field('multi_select', { options: [], colorMap: { admin: 'danger' } })} value={[value]} />)

    const chip = container.querySelector('.martis-badge') as HTMLElement
    expect(chip.textContent).toBe(value)
    expect(chip.className).toContain('martis-badge-neutral')
  })

  it('still colours a mapped value', () => {
    const { container } = render(<MultiSelectFieldDisplay field={field('multi_select', { options: [], colorMap: { admin: 'danger' } })} value={['admin']} />)

    expect((container.querySelector('.martis-badge') as HTMLElement).style.backgroundColor).toBe('var(--martis-badge-danger-bg)')
  })
})

describe('IconFieldDisplay with a stored value spelled like an inherited member', () => {
  it.each(INHERITED)('renders an icon for %s instead of hiding it', (value) => {
    const { container } = render(<IconFieldDisplay field={field('icon', { map: { active: { icon: 'check', color: 'success' } } })} value={value} />)

    expect(container.querySelector('[data-testid="icon-display-status"]')).not.toBeNull()
  })

  it('still resolves a mapped value', () => {
    const { container } = render(<IconFieldDisplay field={field('icon', { map: { active: { icon: 'check', color: 'success' } } })} value="active" />)

    expect((container.querySelector('[data-testid="icon-display-status"]') as HTMLElement).style.color).toBe('var(--martis-success)')
  })
})
