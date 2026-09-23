import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { useState } from 'react'
import { act, render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createBrowserRouter, RouterProvider } from 'react-router-dom'
import type { FieldDefinition, ResourceRecord, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { allowDataRouterNavigation } from '@/test-support/dataRouterNavigation'

/*
 * The unsaved-changes guard of the full-page create and update forms holds
 * the browser's Back button while the form has unsaved changes. A clean form
 * leaves the history alone: Back and Forward move between the pages exactly
 * as they do anywhere else, and the address bar always shows the page on
 * screen. These tests drive a browser router over jsdom's own history, the
 * way the app runs.
 */

const apiGetMock = vi.fn()
const apiPutMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      put: (...args: unknown[]) => apiPutMock(...args),
    },
  }
})

import { ResourceUpdatePage } from '@/pages/ResourceUpdate'
import { ResourceCreatePage } from '@/pages/ResourceCreate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'
import { componentRegistry } from '@/lib/componentRegistry'
import { useModalHistoryLock } from '@/lib/historyLock'
import type { FieldInputProps } from '@/components/fields/types'

registerDefaultFields()
allowDataRouterNavigation()

// An input that picks its value in a modal holding the back button, the way
// the BelongsTo inline create does: the pick changes the value and closes the
// modal in one go.
function PickerModal({ open, onPick }: { open: boolean; onPick: () => void }) {
  useModalHistoryLock(open)
  if (!open) return null
  return (
    <button type="button" onClick={onPick}>
      Use it
    </button>
  )
}

function PickerInput({ value, onChange }: FieldInputProps) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <span data-testid="picked">{String(value ?? '')}</span>
      <button type="button" onClick={() => setOpen(true)}>
        Pick
      </button>
      <PickerModal
        open={open}
        onPick={() => {
          onChange('picked')
          setOpen(false)
        }}
      />
    </>
  )
}
componentRegistry.registerFieldInput('picker', PickerInput)

function textField(attribute: string, label: string): FieldDefinition {
  return {
    attribute,
    label,
    type: 'text',
    nullable: false,
    readonly: false,
    required: false,
    sortable: false,
    searchable: false,
    showOnIndex: false,
    showOnDetail: true,
    showOnForms: true,
    rules: [],
    reserved: [],
  } as unknown as FieldDefinition
}

const fields = [textField('title', 'Title'), textField('summary', 'Summary')]

const postsSchema = {
  uriKey: 'posts',
  label: 'Posts',
  singularLabel: 'Post',
  fields: [],
  fieldsForCreate: fields,
  fieldsForUpdate: fields,
  errorDisplay: 'inline',
  confirmUnsavedChanges: true,
  messages: {},
} as unknown as ResourceSchema

const notesSchema = {
  ...postsSchema,
  uriKey: 'notes',
  label: 'Notes',
  singularLabel: 'Note',
  fieldsForUpdate: [textField('title', 'Title'), { ...textField('topic', 'Topic'), type: 'picker' }],
} as unknown as ResourceSchema

const RECORDS: Record<string, Record<string, unknown>> = {
  '1': { id: 1, title: 'First post', summary: 'About the first post' },
  '2': { id: 2, title: 'Second post', summary: 'About the second post' },
}

let router: ReturnType<typeof createBrowserRouter> | null = null

beforeEach(() => {
  apiGetMock.mockReset()
  apiPutMock.mockReset()
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/posts/schema') return Promise.resolve({ data: postsSchema })
    if (path === '/api/resources/notes/schema') return Promise.resolve({ data: notesSchema })
    if (path === '/api/resources/notes/1?context=update') return Promise.resolve({ data: { id: 1, title: 'A note', topic: null } })
    const recordPath = /^\/api\/resources\/posts\/([^/?]+)\?context=update$/.exec(path)
    if (recordPath) return Promise.resolve({ data: { ...RECORDS[recordPath[1]] } as ResourceRecord })
    return Promise.resolve({ data: [] })
  })
  apiPutMock.mockReturnValue(new Promise(() => {}))
})

// Counts the pops the browser dispatches. Registered before any page mounts,
// so it runs ahead of the listeners that keep a pop from the others.
let pops = 0
const countPop = () => {
  pops += 1
}

afterEach(() => {
  router?.dispose()
  router = null
  window.removeEventListener('popstate', countPop, { capture: true })
})

function renderApp() {
  // Every test starts on a page of its own, so a Back that overshoots the
  // index lands somewhere the assertions can see.
  window.history.replaceState(null, '', '/start')
  window.addEventListener('popstate', countPop, { capture: true })
  router = createBrowserRouter([
    { path: '/start', element: <div data-testid="start-page" /> },
    { path: '/resources/:resource', element: <div data-testid="index-page" /> },
    { path: '/resources/:resource/create', element: <ResourceCreatePage /> },
    { path: '/resources/:resource/:id', element: <div data-testid="detail-page" /> },
    { path: '/resources/:resource/:id/edit', element: <ResourceUpdatePage /> },
  ])
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
  return router
}

// The router shows the page and the address bar names it: a navigation that
// failed in the harness would otherwise look like a page that stayed put.
async function waitForPage(pathname: string) {
  await waitFor(() => {
    expect(router!.state.location.pathname).toBe(pathname)
    expect(window.location.pathname).toBe(pathname)
  })
}

async function visit(pathname: string) {
  await act(() => router!.navigate(pathname))
  await waitForPage(pathname)
}

async function back(pathname: string) {
  act(() => window.history.back())
  await waitForPage(pathname)
}

async function forward(pathname: string) {
  act(() => window.history.forward())
  await waitForPage(pathname)
}

// Runs `action` and waits until the browser has dispatched the pop it causes.
async function popAfter(action: () => void) {
  const before = pops
  act(action)
  await waitFor(() => expect(pops).toBe(before + 1))
}

function input(attribute: string): HTMLInputElement | null {
  return document.getElementById(attribute) as HTMLInputElement | null
}

async function waitForValue(attribute: string, value: string) {
  await waitFor(() => expect(input(attribute)?.value).toBe(value))
}

function type(attribute: string, value: string) {
  fireEvent.change(input(attribute)!, { target: { value } })
}

describe('useUnsavedChangesGuard — a clean form leaves the history alone', () => {
  it('moves Back and Forward between two edit pages like any other page', async () => {
    renderApp()
    await visit('/resources/posts')
    await visit('/resources/posts/1/edit')
    await waitForValue('title', 'First post')
    await visit('/resources/posts/2/edit')
    await waitForValue('title', 'Second post')

    await back('/resources/posts/1/edit')
    await waitForValue('title', 'First post')
    await forward('/resources/posts/2/edit')
    await waitForValue('title', 'Second post')
    await back('/resources/posts/1/edit')
    await waitForValue('title', 'First post')
    await back('/resources/posts')
    expect(screen.getByTestId('index-page')).toBeTruthy()
  })

  it('walks Back from the second edit page to the index one page at a time', async () => {
    renderApp()
    await visit('/resources/posts')
    await visit('/resources/posts/1/edit')
    await waitForValue('title', 'First post')
    await visit('/resources/posts/2/edit')
    await waitForValue('title', 'Second post')

    await back('/resources/posts/1/edit')
    await waitForValue('title', 'First post')
    await back('/resources/posts')
    expect(screen.getByTestId('index-page')).toBeTruthy()
  })

  it('keeps the page opened from a create form in the Forward history', async () => {
    renderApp()
    await visit('/resources/posts')
    await visit('/resources/posts/create')
    await waitForValue('title', '')
    await visit('/resources/posts/1')

    await back('/resources/posts/create')
    await waitForValue('title', '')
    await forward('/resources/posts/1')
    expect(screen.getByTestId('detail-page')).toBeTruthy()
    await back('/resources/posts/create')
    await back('/resources/posts')
    expect(screen.getByTestId('index-page')).toBeTruthy()
  })
})

describe('useUnsavedChangesGuard — a form with unsaved changes', () => {
  it('holds the page on Back until the user discards the changes', async () => {
    renderApp()
    await visit('/resources/posts')
    await visit('/resources/posts/1/edit')
    await waitForValue('title', 'First post')
    type('title', 'First post, edited')

    act(() => window.history.back())
    await screen.findByTestId('unsaved-changes-dialog')
    await waitForPage('/resources/posts/1/edit')
    fireEvent.click(screen.getByTestId('unsaved-keep-editing'))
    expect(screen.queryByTestId('unsaved-changes-dialog')).toBeNull()
    expect(input('title')?.value).toBe('First post, edited')

    act(() => window.history.back())
    fireEvent.click(await screen.findByTestId('unsaved-discard'))

    await waitForPage('/resources/posts')
    expect(screen.getByTestId('index-page')).toBeTruthy()
  })

  it('walks Back past the form in one press once its changes are saved', async () => {
    renderApp()
    await visit('/resources/posts')
    await visit('/resources/posts/1/edit')
    await waitForValue('title', 'First post')
    type('title', 'First post, renamed')
    apiPutMock.mockResolvedValueOnce({ data: { ...RECORDS['1'], title: 'First post, renamed' } })
    fireEvent.click(screen.getByRole('button', { name: /continue/i }))
    await waitFor(() => expect(apiPutMock).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Save changes' })).toHaveProperty('disabled', false))

    await back('/resources/posts')
    expect(screen.getByTestId('index-page')).toBeTruthy()
  })

  it('walks Back through the form and past it after its changes were saved and the page left', async () => {
    renderApp()
    await visit('/resources/posts')
    await visit('/resources/posts/1/edit')
    await waitForValue('title', 'First post')
    type('title', 'First post, renamed')
    apiPutMock.mockResolvedValueOnce({ data: { ...RECORDS['1'], title: 'First post, renamed' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitForPage('/resources/posts/1')

    await back('/resources/posts/1/edit')
    await waitForValue('title', 'First post')
    await back('/resources/posts')
    expect(screen.getByTestId('index-page')).toBeTruthy()
  })

  it('walks Back through the form and past it after the user discarded its changes for a link', async () => {
    renderApp()
    await visit('/resources/posts')
    await visit('/resources/posts/1/edit')
    await waitForValue('title', 'First post')
    type('title', 'First post, edited')

    await act(() => router!.navigate('/resources/posts/2/edit'))
    fireEvent.click(await screen.findByTestId('unsaved-discard'))
    await waitForPage('/resources/posts/2/edit')
    await waitForValue('title', 'Second post')

    await back('/resources/posts/1/edit')
    await waitForValue('title', 'First post')
    await back('/resources/posts')
    expect(screen.getByTestId('index-page')).toBeTruthy()
  })

  it('leaves Back to a modal open over the form', async () => {
    renderApp()
    await visit('/resources/notes')
    await visit('/resources/notes/1/edit')
    await waitForValue('title', 'A note')
    type('title', 'A note, edited')
    fireEvent.click(screen.getByRole('button', { name: 'Pick' }))
    await screen.findByRole('button', { name: 'Use it' })

    await popAfter(() => window.history.back())

    expect(screen.getByRole('button', { name: 'Use it' })).toBeTruthy()
    expect(screen.queryByTestId('unsaved-changes-dialog')).toBeNull()
    await waitForPage('/resources/notes/1/edit')
  })

  it('holds the page on Back once a modal that changed the form has closed', async () => {
    renderApp()
    await visit('/resources/notes')
    await visit('/resources/notes/1/edit')
    await waitForValue('title', 'A note')
    fireEvent.click(screen.getByRole('button', { name: 'Pick' }))
    await screen.findByRole('button', { name: 'Use it' })

    // The modal takes its own history entry away as it closes.
    await popAfter(() => fireEvent.click(screen.getByRole('button', { name: 'Use it' })))
    expect(screen.getByTestId('picked').textContent).toBe('picked')

    act(() => window.history.back())
    await screen.findByTestId('unsaved-changes-dialog')
    await waitForPage('/resources/notes/1/edit')
    fireEvent.click(screen.getByTestId('unsaved-discard'))
    await waitForPage('/resources/notes')
  })
})
