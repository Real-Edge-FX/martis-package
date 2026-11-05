import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'
import type { ResourceRecord } from '@/types'
import type { ActionMeta } from '@/components/Actions'
import { InlineRowActions, splitInlineActions } from './Table'

/*
 * v2.17.0: an inline action can go to the row's "..." menu as a flat item
 * (inRowMenu(), or past the resource's $maxInlineButtons), and one the row
 * cannot run on is greyed out, left out, or kept as an invisible slot
 * (whenInapplicable()).
 */

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (_k: string, d?: string) => d ?? _k }) }))

const action = (uriKey: string, extra: Partial<ActionMeta> = {}): ActionMeta =>
  ({ uriKey, name: uriKey, standalone: false, destructive: false, group: null, icon: null, showIcon: true, iconColor: null, ...extra }) as ActionMeta

const row = (verdicts: Record<string, boolean>): ResourceRecord =>
  ({ id: 1, _actionAuthorization: verdicts }) as unknown as ResourceRecord

const rowButtons = (container: HTMLElement) =>
  Array.from(container.querySelectorAll('button.martis-row-action-btn')).map((b) => b.getAttribute('aria-label'))

function openMenu() {
  fireEvent.click(screen.getByRole('button', { name: 'Actions' }))
  return screen.getByRole('menu')
}

describe('splitInlineActions', () => {
  it('sends inRowMenu() and grouped actions to the menu, flat items first', () => {
    const { buttons, menu } = splitInlineActions([
      action('edit'), action('grouped', { group: 'Power' }), action('rebuild', { inRowMenu: true }), action('view'),
    ])

    expect(buttons.map((a) => a.uriKey)).toEqual(['edit', 'view'])
    expect(menu.map((a) => a.uriKey)).toEqual(['rebuild', 'grouped'])
  })

  it('keeps the first maxInlineButtons as buttons and moves the rest to the menu as flat items', () => {
    const { buttons, menu } = splitInlineActions([action('a'), action('b'), action('c'), action('d'), action('e')], 2)

    expect(buttons.map((a) => a.uriKey)).toEqual(['a', 'b'])
    expect(menu.map((a) => a.uriKey)).toEqual(['c', 'd', 'e'])
  })
})

describe('InlineRowActions', () => {
  it('draws an inRowMenu() action as a flat menu item, with no submenu row', () => {
    const { container } = render(<InlineRowActions actions={[action('rebuild', { inRowMenu: true })]} row={row({ rebuild: true })} onAction={() => {}} />)

    expect(rowButtons(container)).toEqual(['Actions'])
    const menu = openMenu()
    expect(within(menu).getByRole('menuitem', { name: /rebuild/ })).toBeTruthy()
    expect(menu.querySelector('[aria-haspopup="menu"]')).toBeNull()
  })

  it('greys out an inapplicable action by default, in a button and in the menu', () => {
    const { container } = render(
      <InlineRowActions actions={[action('on'), action('archive', { inRowMenu: true })]} row={row({ on: false, archive: false })} onAction={() => {}} />,
    )

    expect((container.querySelector('button[aria-label="on"]') as HTMLButtonElement).disabled).toBe(true)
    expect((within(openMenu()).getByRole('menuitem', { name: /archive/ }) as HTMLButtonElement).disabled).toBe(true)
  })

  it('leaves out a Hide action the row cannot run, and the menu when nothing is left in it', () => {
    const { container } = render(
      <InlineRowActions
        actions={[action('on', { whenInapplicable: 'hide' }), action('off'), action('restart', { group: 'Power', whenInapplicable: 'hide' })]}
        row={row({ on: false, off: true, restart: false })}
        onAction={() => {}}
      />,
    )

    // The grouped action was the only one in the menu: no "..." button, no empty "Power" group.
    expect(rowButtons(container)).toEqual(['off'])
  })

  it('keeps an invisible slot for a KeepSlot button, out of the accessibility tree, and leaves it out of the menu', () => {
    const { container } = render(
      <InlineRowActions
        actions={[action('up', { whenInapplicable: 'keep_slot' }), action('down'), action('move', { inRowMenu: true, whenInapplicable: 'keep_slot' }), action('edit', { inRowMenu: true })]}
        row={row({ up: false, down: true, move: false, edit: true })}
        onAction={() => {}}
      />,
    )

    const slot = container.querySelector('.martis-row-action-btn--slot') as HTMLElement
    expect(slot.getAttribute('aria-hidden')).toBe('true')
    expect(slot.style.visibility).toBe('hidden')
    expect(slot.tagName).toBe('SPAN')
    // The slot comes before "down", so "down" stays in the second position.
    expect(Array.from(container.querySelectorAll('.martis-row-action-btn')).map((e) => e.getAttribute('aria-label') ?? 'slot')).toEqual(['slot', 'down', 'Actions'])

    const menu = openMenu()
    expect(within(menu).queryByRole('menuitem', { name: /move/ })).toBeNull()
    expect(within(menu).getByRole('menuitem', { name: /edit/ })).toBeTruthy()
  })

  it('takes the maxInlineButtons count before hiding, so the same actions are buttons on every row', () => {
    const actions = [action('a', { whenInapplicable: 'hide' }), action('b'), action('c')]
    const { container } = render(<InlineRowActions actions={actions} row={row({ a: false, b: true, c: true })} onAction={() => {}} maxInlineButtons={2} />)

    // "a" is hidden on this row, but "c" stays in the menu: it is past the limit.
    expect(rowButtons(container)).toEqual(['b', 'Actions'])
    expect(within(openMenu()).getByRole('menuitem', { name: /c/ })).toBeTruthy()
  })
})
