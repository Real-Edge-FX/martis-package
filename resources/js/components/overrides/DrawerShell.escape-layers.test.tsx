import { describe, it, expect, vi } from 'vitest'
import { useRef, useState, type ReactNode } from 'react'
import { render, screen, fireEvent, act, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { Dropdown } from 'primereact/dropdown'
import { MultiSelect } from 'primereact/multiselect'
import { Calendar } from 'primereact/calendar'
import { AutoComplete } from 'primereact/autocomplete'
import { SplitButton } from 'primereact/splitbutton'
import { OverlayPanel } from 'primereact/overlaypanel'
import { ColorPicker } from 'primereact/colorpicker'
import { DrawerShell } from './DrawerShell'
import { ActionDropdown } from '@/components/Actions/ActionDropdown'
import type { ActionMeta } from '@/components/Actions/ActionModal'
import { InlineActionMenu } from '@/components/Table/Table'
import { useEscapeLayer } from '@/lib/escapeLayers'

/*
 * Escape closes the top layer only. In a Create drawer with a field
 * filled, an Escape meant for a menu or a picker closed the drawer too, and
 * the form with it: every popup's Escape handler and the drawer's ran on
 * the same keystroke. The drawer now leaves the Escape to whatever layer
 * is open when it arrives: a Martis popup
 * (`useEscapeLayer`) or a PrimeReact overlay. The next Escape reaches the
 * drawer, through its unsaved-changes guard.
 */

const action: ActionMeta = {
  uriKey: 'archive-posts', name: 'Archive posts', icon: null, showIcon: true, iconColor: null,
  group: null, destructive: false, showOnIndex: true, showOnDetail: true, showInline: true,
  executionMode: 'bulk', standalone: false, sole: false, queued: false, withConfirmation: false,
  confirmText: null, confirmButtonText: 'Run', cancelButtonText: null, modalSize: 'md',
  supportsDryRun: false, customComponent: null, customComponentProps: {}, logEvents: true,
  isPivotAction: false, pivotLabel: null,
}

function CreateDrawer({ children, onClose, beforeClose }: { children: ReactNode; onClose: () => void; beforeClose?: () => boolean | Promise<boolean> }) {
  const [title, setTitle] = useState('')
  return (
    <MemoryRouter>
      <DrawerShell title="Create post" onClose={onClose} beforeClose={beforeClose}>
        <label>
          Title
          <input value={title} onChange={(e) => setTitle(e.target.value)} />
        </label>
        {children}
      </DrawerShell>
    </MemoryRouter>
  )
}

function escape(target: Element | Document = document.activeElement ?? document) {
  fireEvent.keyDown(target, { key: 'Escape', code: 'Escape', keyCode: 27, which: 27 })
}

async function settle(ms = 400) {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, ms))
  })
}

function fillTitle() {
  fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Draft post' } })
}

function expectDrawerKept(onClose: ReturnType<typeof vi.fn>) {
  expect(onClose).not.toHaveBeenCalled()
  expect((screen.getByLabelText('Title') as HTMLInputElement).value).toBe('Draft post')
}

function openOverlay(): boolean {
  return document.querySelector('[data-pr-is-overlay], .p-overlaypanel, .p-menu-overlay') !== null
}

function TwoLayers() {
  const [outer, setOuter] = useState(true)
  const [inner, setInner] = useState(true)
  useEscapeLayer(outer, () => setOuter(false))
  useEscapeLayer(inner, () => setInner(false))
  return (
    <>
      {outer && <div>Outer layer</div>}
      {inner && <div>Inner layer</div>}
    </>
  )
}

function AutoCompleteHarness() {
  const [suggestions, setSuggestions] = useState<string[]>([])
  return <AutoComplete value="" dropdown suggestions={suggestions} completeMethod={() => setSuggestions(['News', 'Newsletter'])} />
}

function OverlayPanelHarness() {
  const ref = useRef<OverlayPanel>(null)
  return (
    <>
      <button type="button" onClick={(e) => ref.current?.toggle(e)}>Open panel</button>
      <OverlayPanel ref={ref}><p>Panel content</p></OverlayPanel>
    </>
  )
}

describe('Escape in a Create drawer with an open layer', () => {
  it.each<[string, () => ReactNode, () => Promise<void>, () => boolean]>([
    [
      'the actions menu',
      () => <ActionDropdown actions={[action]} onSelect={() => {}} label="Actions" />,
      async () => { fireEvent.click(screen.getByRole('button', { name: /Actions/ })) },
      () => screen.queryByText('Archive posts') !== null,
    ],
    [
      'a row\'s inline action menu',
      () => <InlineActionMenu actions={[action]} row={{ id: 1, _title: 'Post' } as never} onAction={() => {}} />,
      async () => { fireEvent.click(document.querySelector('.martis-drawer button[aria-haspopup], [aria-haspopup="menu"]') as HTMLElement) },
      () => screen.queryByText('Archive posts') !== null,
    ],
    [
      'a PrimeReact Dropdown',
      () => <Dropdown options={['Draft', 'Published']} value="Draft" />,
      async () => { fireEvent.click(document.querySelector('.p-dropdown') as HTMLElement) },
      openOverlay,
    ],
    [
      'a PrimeReact MultiSelect',
      () => <MultiSelect options={['News', 'Tech']} value={[]} />,
      async () => { fireEvent.click(document.querySelector('.p-multiselect') as HTMLElement) },
      openOverlay,
    ],
    [
      'a PrimeReact Calendar',
      () => <Calendar value={null} />,
      async () => {
        const input = document.querySelector('.p-calendar input') as HTMLElement
        input.focus()
        fireEvent.focus(input)
        fireEvent.click(input)
      },
      openOverlay,
    ],
    [
      'a PrimeReact AutoComplete',
      () => <AutoCompleteHarness />,
      async () => {
        fireEvent.click(document.querySelector('.p-autocomplete-dropdown') as HTMLElement)
        await settle(100)
        ;(document.querySelector('.p-autocomplete input') as HTMLElement).focus()
      },
      openOverlay,
    ],
    [
      'a PrimeReact SplitButton',
      () => <SplitButton label="Save" model={[{ label: 'Save and add another' }]} />,
      async () => { fireEvent.click(document.querySelector('.p-splitbutton-menubutton') as HTMLElement) },
      openOverlay,
    ],
    [
      'a PrimeReact OverlayPanel',
      () => <OverlayPanelHarness />,
      async () => { fireEvent.click(screen.getByRole('button', { name: 'Open panel' })) },
      openOverlay,
    ],
    [
      'a PrimeReact ColorPicker',
      () => <ColorPicker value="ff0000" />,
      async () => { fireEvent.click(document.querySelector('.p-colorpicker-preview') as HTMLElement) },
      openOverlay,
    ],
  ])('closes %s only, keeps the drawer and its value, and a second Escape closes the drawer', async (_name, layer, open, isOpen) => {
    const onClose = vi.fn()
    render(<CreateDrawer onClose={onClose}>{layer()}</CreateDrawer>)
    fillTitle()

    await act(async () => { await open() })
    await settle(50)
    expect(isOpen()).toBe(true)

    escape()
    await settle()

    await waitFor(() => expect(isOpen()).toBe(false))
    expectDrawerKept(onClose)

    escape(document)
    await settle()
    expect(onClose).toHaveBeenCalledOnce()
  })

  it('closes the top Martis layer first, then the next, then the drawer', async () => {
    const onClose = vi.fn()
    render(<CreateDrawer onClose={onClose}><TwoLayers /></CreateDrawer>)
    fillTitle()

    escape(document)
    await settle()
    expect(screen.queryByText('Inner layer')).toBeNull()
    expect(screen.getByText('Outer layer')).toBeTruthy()
    expectDrawerKept(onClose)

    escape(document)
    await settle()
    expect(screen.queryByText('Outer layer')).toBeNull()
    expectDrawerKept(onClose)

    escape(document)
    await settle()
    expect(onClose).toHaveBeenCalledOnce()
  })

  it('asks the unsaved-changes guard only on the Escape that reaches the drawer', async () => {
    const onClose = vi.fn()
    // The guard's answer: the user chose to keep editing.
    const beforeClose = vi.fn(() => false)
    render(
      <CreateDrawer onClose={onClose} beforeClose={beforeClose}>
        <ActionDropdown actions={[action]} onSelect={() => {}} label="Actions" />
      </CreateDrawer>,
    )
    fillTitle()
    fireEvent.click(screen.getByRole('button', { name: /Actions/ }))
    expect(screen.getByText('Archive posts')).toBeTruthy()

    escape(document)
    await settle()
    expect(screen.queryByText('Archive posts')).toBeNull()
    expect(beforeClose).not.toHaveBeenCalled()

    escape(document)
    await settle()
    expect(beforeClose).toHaveBeenCalledOnce()
    expectDrawerKept(onClose)
  })
})
