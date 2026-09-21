import { describe, it, expect, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'

/*
 * Row headers of a Repeater.
 *
 * `Repeatable::title(Closure)` is resolved per row on the server and travels
 * as the row's `title`; the display and the editor prefer it over the
 * repeatable's label. A `{attr}` template keeps being evaluated live on the
 * client. A row of a Closure-titled repeatable that has no resolved title
 * yet (added in the form, not saved) falls back to "Label #N", the same
 * fallback a template that resolves to nothing uses. The "#N" badge is only
 * rendered next to a static label, so the number never shows twice.
 */

vi.mock('./FieldRenderer', () => ({
  FieldInput: () => <div data-testid="child-input" />,
}))

import { RepeaterFieldDisplay, RepeaterFieldInput, resolveRowTitle } from './RepeaterField'

function repeaterField(repeatable: Record<string, unknown>): FieldDefinition {
  return {
    attribute: 'sections',
    label: 'Sections',
    type: 'repeater',
    nullable: true,
    readonly: false,
    required: false,
    sortable: false,
    searchable: false,
    showOnIndex: false,
    showOnDetail: true,
    showOnForms: true,
    rules: [],
    storage: 'json',
    repeatables: [
      {
        shortName: 'section',
        uniqueKey: 'section',
        label: 'Home Section',
        fields: [{ attribute: 'key', label: 'Key', type: 'text' }],
        ...repeatable,
      },
    ],
  } as unknown as FieldDefinition
}

const closureRows = [
  { id: 'a', type: 'section', fields: { key: 'hero' }, title: 'Hero' },
  { id: 'b', type: 'section', fields: { key: 'stats' }, title: 'Stats' },
  { id: 'c', type: 'section', fields: { key: 'new' } },
]

describe('resolveRowTitle', () => {
  const rep = { shortName: 'section', uniqueKey: 'section', label: 'Home Section', fields: [] }

  it('prefers the server-resolved title of a Closure-titled row', () => {
    expect(resolveRowTitle({ ...rep, hasTitleCallback: true, titleTemplate: '{key}' }, closureRows[0], 0)).toBe('Hero')
  })

  it('falls back to "Label #N" for a Closure-titled row without a resolved title', () => {
    expect(resolveRowTitle({ ...rep, hasTitleCallback: true }, closureRows[2], 2)).toBe('Home Section #3')
  })

  it('evaluates a template live and falls back to "Label #N" when it resolves to nothing', () => {
    const row = { id: 'b', type: 'section', fields: { key: 'stats' } }
    expect(resolveRowTitle({ ...rep, titleTemplate: 'Section {key}' }, row, 1)).toBe('Section stats')
    expect(resolveRowTitle({ ...rep, titleTemplate: '{missing}' }, row, 1)).toBe('Home Section #2')
  })

  it('ignores a stray title on a row whose repeatable is template-titled', () => {
    expect(resolveRowTitle({ ...rep, titleTemplate: 'Section {key}' }, closureRows[1], 1)).toBe('Section stats')
  })

  it('returns null when the repeatable declares no dynamic title', () => {
    expect(resolveRowTitle(rep, { id: 'b', type: 'section', fields: { key: 'stats' } }, 1)).toBeNull()
  })
})

describe('RepeaterFieldDisplay', () => {
  it('renders the server-resolved title for each Closure-titled row', () => {
    render(<RepeaterFieldDisplay field={repeaterField({ hasTitleCallback: true })} value={closureRows} />)

    expect(screen.getByText('Hero')).toBeTruthy()
    expect(screen.getByText('Stats')).toBeTruthy()
    expect(screen.getByText('Home Section #3')).toBeTruthy()
  })

  it('keeps rendering the label plus the badge for a static header', () => {
    render(
      <RepeaterFieldDisplay
        field={repeaterField({ badgeCount: true })}
        value={[{ id: 'a', type: 'section', fields: { key: 'hero' } }]}
      />,
    )

    expect(screen.getByText('Home Section')).toBeTruthy()
    expect(screen.getByText('#1')).toBeTruthy()
  })
})

describe('RepeaterFieldInput', () => {
  it('shows the server-resolved title in each row header and no badge next to a dynamic title', () => {
    render(
      <RepeaterFieldInput
        field={repeaterField({ hasTitleCallback: true, badgeCount: true })}
        value={closureRows}
        onChange={() => {}}
        error={undefined}
      />,
    )

    expect(screen.getByText('Hero')).toBeTruthy()
    expect(screen.getByText('Stats')).toBeTruthy()
    expect(screen.getByText('Home Section #3')).toBeTruthy()
    expect(screen.queryByText('#1')).toBeNull()
  })

  it('renders the row number once for a static label with badgeCount', () => {
    render(
      <RepeaterFieldInput
        field={repeaterField({ badgeCount: true })}
        value={[{ id: 'a', type: 'section', fields: { key: 'hero' } }]}
        onChange={() => {}}
        error={undefined}
      />,
    )

    expect(screen.getByText('Home Section')).toBeTruthy()
    expect(screen.getAllByText(/#1/)).toHaveLength(1)
  })

  it('sends the resolved title back untouched so the server can drop it', () => {
    const onChange = vi.fn()
    const { container } = render(
      <RepeaterFieldInput
        field={repeaterField({ hasTitleCallback: true })}
        value={closureRows}
        onChange={onChange}
        error={undefined}
      />,
    )

    // The remove button is labelled through the shared PrimeReact tooltip,
    // not an accessible name, so it is picked by its tooltip attribute.
    const removeButtons = container.querySelectorAll<HTMLButtonElement>('button[data-pr-tooltip="Remove"]')
    expect(removeButtons).toHaveLength(3)
    fireEvent.click(removeButtons[2])

    expect(onChange).toHaveBeenCalledWith([
      { id: 'a', type: 'section', fields: { key: 'hero' }, title: 'Hero' },
      { id: 'b', type: 'section', fields: { key: 'stats' }, title: 'Stats' },
    ])
  })
})
