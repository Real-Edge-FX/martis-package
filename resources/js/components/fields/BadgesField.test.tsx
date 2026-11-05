import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { BadgesFieldDisplay } from './BadgesField'
import { FieldDisplay } from './FieldRenderer'

const field = (extra: Record<string, unknown> = {}): FieldDefinition =>
  ({ attribute: 'languages', label: 'Languages', type: 'badges', types: { info: 'info', success: 'success', warning: 'warning', danger: 'danger' }, map: {}, ...extra }) as unknown as FieldDefinition

describe('BadgesFieldDisplay', () => {
  it('renders one pill per item inside a list', () => {
    render(<BadgesFieldDisplay field={field()} value={[{ label: 'EN', type: 'success', tooltip: null }, { label: 'PT', type: null, tooltip: null }]} />)
    expect(screen.getByRole('list')).toBeTruthy()
    const items = screen.getAllByRole('listitem')
    expect(items.map((i) => i.textContent)).toEqual(['EN', 'PT'])
  })

  it('maps types to the badge colour variables', () => {
    render(<BadgesFieldDisplay field={field()} value={[{ label: 'EN', type: 'success', tooltip: null }, { label: 'X', type: 'danger', tooltip: null }]} />)
    const [a, b] = screen.getAllByRole('listitem')
    expect(a.style.color).toBe('var(--martis-badge-success-text)')
    expect(b.style.color).toBe('var(--martis-badge-danger-text)')
  })

  it('applies map() aliases and falls back to the default style', () => {
    render(<BadgesFieldDisplay field={field({ map: { ok: 'success' } })} value={[{ label: 'A', type: 'ok', tooltip: null }, { label: 'B', type: null, tooltip: null }]} />)
    const [a, b] = screen.getAllByRole('listitem')
    expect(a.style.color).toBe('var(--martis-badge-success-text)')
    expect(b.style.color).toBe('var(--martis-text)')
  })

  it('renders the empty placeholder for an empty list', () => {
    const { container } = render(<BadgesFieldDisplay field={field()} value={[]} />)
    expect(container.textContent).toBe('—')
    expect(screen.queryByRole('list')).toBeNull()
  })

  it('wires the tooltip to the global Tooltip, not a native title', () => {
    render(<BadgesFieldDisplay field={field()} value={[{ label: 'PT', type: 'warning', tooltip: 'No translation' }, { label: 'EN', type: null, tooltip: null }]} />)
    const [a, b] = screen.getAllByRole('listitem')
    expect(a.getAttribute('data-pr-tooltip')).toBe('No translation')
    expect(a.hasAttribute('title')).toBe(false)
    expect(b.hasAttribute('data-pr-tooltip')).toBe(false)
  })

  it('renders labels as plain text, never HTML', () => {
    const { container } = render(<BadgesFieldDisplay field={field()} value={[{ label: '<b>x</b>', type: null, tooltip: null }]} />)
    expect(container.querySelector('b')).toBeNull()
    expect(screen.getByRole('listitem').textContent).toBe('<b>x</b>')
  })

  it('is the renderer for the badges field type', () => {
    render(<FieldDisplay field={field()} value={[{ label: 'EN', type: null, tooltip: null }]} />)
    expect(screen.getByRole('listitem').textContent).toBe('EN')
  })
})
