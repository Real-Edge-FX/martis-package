import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { act, render } from '@testing-library/react'
import type { ReactElement } from 'react'
import type { FieldInputProps } from './types'

/*
 * Render-loop regression (see also `ResourceCreate.characterization.test.tsx`,
 * which documents that mounting `SlugFieldInput` inside the full create page
 * and typing into the source field "enters an unbounded re-render loop under
 * jsdom and kills the Vitest worker").
 *
 * Root cause: `SlugFieldInput` derives
 *
 *   const reserved = Array.isArray(extras.reserved) ? extras.reserved : []
 *
 * fresh on every render — a brand-new array identity each time, even when
 * the field's actual reserved-word list never changes. That array is a
 * dependency of the debounced collision-check `useEffect` ("D2"), which
 * unconditionally calls `setCheckState({ kind: 'checking' })` (a fresh
 * object) on every run before arming the debounce timer. Because
 * `reserved`'s identity changes on every render, any re-render — including
 * one the effect itself just caused via `setCheckState` — re-triggers the
 * effect, which re-arms the debounce timer and schedules another render.
 * With a referentially-stable `field` prop this settles after one extra
 * render (see the harness note in the characterization test), but the
 * underlying defect is that the effect keys off the WRONG thing: an array
 * identity instead of the array's contents. Any caller that legitimately
 * rebuilds the field descriptor on every render (as the full create/update
 * pages do once `dependsOn` overrides are in play) turns that one-extra
 * render into a sustained loop.
 *
 * This test isolates the defect deterministically and without touching the
 * full page (which is documented to hang the worker): it mocks
 * `@/api/lib` so we can count collision-check network calls, renders
 * `SlugFieldInput` with a non-empty, non-reserved, UNCHANGED slug value,
 * and re-renders it several times passing a `reserved` array that is a new
 * reference each time but has identical contents. A correct implementation
 * only needs to check the server once — the slug value never changes. The
 * unstable dependency causes the debounced check to be re-armed on every
 * re-render instead.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
    },
  }
})

// Imported AFTER the mock is registered.
const { SlugFieldInput } = await import('./SlugField')

function makeField(reserved: string[]): FieldInputProps['field'] {
  return {
    attribute: 'slug',
    label: 'Slug',
    separator: '-',
    reserved, // new array reference on every call, but identical contents
  } as unknown as FieldInputProps['field']
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockResolvedValue({
    data: { available: true, suggestion: null, reserved: false },
  })
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('SlugFieldInput reserved-words dependency stability', () => {
  it('re-arms the debounced collision check only when the slug value changes, not on every render', async () => {
    // `renderNonce` is not read by `SlugFieldInput` at all — its only job is
    // to force a fresh JSX element (and thus a fresh, unrelated `field`
    // object with a fresh `reserved` array) on every `rerender` call, the
    // same way a parent form/page re-renders its children for reasons that
    // have nothing to do with this field.
    function Harness({ renderNonce: _renderNonce }: { renderNonce: number }) {
      return (
        <SlugFieldInput
          // Fresh array literal every render — same contents (`['admin', 'root']`),
          // new reference. Nothing about the field's actual configuration changed.
          field={makeField(['admin', 'root'])}
          value="my-slug"
          onChange={() => {}}
          resourceKey="posts"
          recordId={undefined}
          formValues={{}}
        />
      )
    }

    let rerender!: (ui: ReactElement) => void
    await act(async () => {
      ;({ rerender } = render(<Harness renderNonce={0} />))
    })

    // Advance past the initial 400ms debounce so the first (legitimate)
    // check fires and settles.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(500)
    })
    const callsAfterMount = apiGetMock.mock.calls.length
    expect(callsAfterMount).toBe(1)

    // Re-render several times with an unrelated prop change (`renderNonce`)
    // while `value` stays exactly `"my-slug"` and `reserved` keeps the same
    // CONTENTS but a NEW array reference each time. Nothing that should
    // affect the collision-check result has changed.
    const RERENDERS = 5
    for (let i = 1; i <= RERENDERS; i++) {
      await act(async () => {
        rerender(<Harness renderNonce={i} />)
      })
      await act(async () => {
        await vi.advanceTimersByTimeAsync(500)
      })
    }

    // A stable `reserved` dependency (by content) must not cause the
    // debounced check to be re-armed and re-fired on every unrelated
    // re-render. It should still have fired exactly once — the slug value
    // never changed. This FAILS today: each re-render creates a new
    // `reserved` array reference, which re-triggers the collision-check
    // effect and re-arms the debounce timer, so the mocked endpoint gets
    // hit again and again.
    expect(apiGetMock.mock.calls.length).toBe(callsAfterMount)
  })
})
