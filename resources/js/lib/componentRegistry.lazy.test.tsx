import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { Component, memo } from 'react'
import { act, render, screen, waitFor } from '@testing-library/react'
import { ComponentRegistry } from '@/lib/componentRegistry'
import { STALE_CHUNK_RELOAD_KEY } from '@/lib/staleChunk'

/*
 * Lazy registrations (v2.20.0): `registerLazy(key, loader)` and the
 * `register(key, () => import(...))` form. `resolve()` returns a component
 * that carries its own Suspense boundary, so it renders anywhere a registry
 * component does. Eager registrations are unchanged.
 */

function Hello({ name = 'world' }: { name?: string }) {
  return <div data-testid="hello">{`hello ${name}`}</div>
}

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

function mount(registry: ComponentRegistry, key: string, props: object = {}) {
  const C = registry.resolve(key) as unknown as React.ComponentType<object>
  return render(<C {...props} />)
}

let registry: ComponentRegistry

beforeEach(() => {
  registry = new ComponentRegistry()
})

describe('eager registration is unchanged', () => {
  it('stores and resolves the very component it was given', () => {
    registry.register('tool:eager', Hello)

    expect(registry.resolve('tool:eager')).toBe(Hello)
    expect(registry.isLazy('tool:eager')).toBe(false)
    expect(registry.has('tool:eager')).toBe(true)
  })

  it('does not take a zero-argument function component for a loader', () => {
    const Zero = () => <div data-testid="zero">zero</div>
    registry.register('tool:zero', Zero)

    expect(registry.resolve('tool:zero')).toBe(Zero)
    expect(registry.isLazy('tool:zero')).toBe(false)
  })

  it('does not take a class, memo or forwardRef component for a loader', () => {
    class Klass extends Component {
      render() {
        return null
      }
    }
    const Memoed = memo(Hello)

    registry.register('tool:klass', Klass)
    registry.register('tool:memo', Memoed as never)

    expect(registry.isLazy('tool:klass')).toBe(false)
    expect(registry.isLazy('tool:memo')).toBe(false)
  })

  it('does not take a zero-argument component with a block body for a loader', () => {
    const Sneaky = () => {
      void Promise.resolve().then(() => 1)
      return <div>sneaky</div>
    }
    registry.register('tool:sneaky', Sneaky)

    expect(registry.isLazy('tool:sneaky')).toBe(false)
  })

  it('keeps the typed helpers eager for a component', () => {
    registry.registerFieldDisplay('text', Hello as never)
    registry.registerFieldInput('text', Hello as never)
    registry.registerResourceFieldDisplay('posts', 'status', Hello as never)
    registry.registerResourceFieldInput('posts', 'status', Hello as never)

    expect(registry.keys().map((key) => registry.isLazy(key))).toEqual([false, false, false, false])
  })
})

describe('registerLazy', () => {
  it('registers a lazy component, resolved as a stable wrapper', () => {
    registry.registerLazy('tool:lazy', () => Promise.resolve({ default: Hello }))

    expect(registry.has('tool:lazy')).toBe(true)
    expect(registry.isLazy('tool:lazy')).toBe(true)
    expect(registry.resolve('tool:lazy')).toBe(registry.resolve('tool:lazy'))
    expect(registry.resolve('tool:lazy')).not.toBe(Hello)
  })

  it('shows the fallback, then the component with its props', async () => {
    const gate = deferred<{ default: typeof Hello }>()
    registry.registerLazy('tool:lazy', () => gate.promise, { fallback: <span data-testid="wait">wait</span> })

    mount(registry, 'tool:lazy', { name: 'Ada' })

    expect(screen.getByTestId('wait')).toBeTruthy()
    expect(screen.queryByTestId('hello')).toBeNull()

    await act(async () => {
      gate.resolve({ default: Hello })
    })

    expect(screen.getByTestId('hello').textContent).toBe('hello Ada')
    expect(screen.queryByTestId('wait')).toBeNull()
  })

  it('accepts a fallback component and a loader that resolves the component itself', async () => {
    const Waiting = () => <span data-testid="waiting-component">waiting</span>
    const gate = deferred<typeof Hello>()
    registry.registerLazy('tool:lazy', () => gate.promise, { fallback: Waiting })

    mount(registry, 'tool:lazy')
    expect(screen.getByTestId('waiting-component')).toBeTruthy()

    await act(async () => {
      gate.resolve(Hello)
    })
    expect(screen.getByTestId('hello')).toBeTruthy()
  })

  it('shows the Martis loader when no fallback is registered', () => {
    registry.registerLazy('tool:lazy', () => new Promise<{ default: typeof Hello }>(() => undefined))

    mount(registry, 'tool:lazy')

    expect(screen.getByText(/loading/i)).toBeTruthy()
    expect(screen.queryByTestId('hello')).toBeNull()
  })

  it('shows an error state in place of the component when the chunk cannot load', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    registry.registerLazy('tool:lazy', () => Promise.reject(new Error('boom')))

    mount(registry, 'tool:lazy')

    await waitFor(() => expect(screen.getByText(/unexpected error/i)).toBeTruthy())
    consoleError.mockRestore()
  })

  it('replaces a lazy registration with an eager one under the same key', () => {
    registry.registerLazy('tool:swap', () => Promise.resolve({ default: Hello }))
    registry.register('tool:swap', Hello)

    expect(registry.isLazy('tool:swap')).toBe(false)
    expect(registry.resolve('tool:swap')).toBe(Hello)
  })

  it('forgets the lazy flag on unregister and clear', () => {
    registry.registerLazy('tool:a', () => Promise.resolve({ default: Hello }))
    registry.registerLazy('tool:b', () => Promise.resolve({ default: Hello }))

    expect(registry.unregister('tool:a')).toBe(true)
    expect(registry.isLazy('tool:a')).toBe(false)

    registry.clear()
    expect(registry.isLazy('tool:b')).toBe(false)
  })
})

describe('register() with a loader', () => {
  it('takes an expression-bodied arrow that imports as a loader', () => {
    // `importer(` is not `import(`: a call to some other function stays eager.
    registry.register('tool:not-a-loader', new Function('return () => importer("./X")')() as never)
    expect(registry.isLazy('tool:not-a-loader')).toBe(false)

    // The shape a bundler leaves: `() => import('./X')`, a zero-argument arrow.
    registry.register('tool:loader', new Function('return () => import("data:text/javascript,export default 1")')() as never)
    expect(registry.isLazy('tool:loader')).toBe(true)
  })

  it('takes a bundler-wrapped and a function-expression loader too', () => {
    registry.register('tool:preload', new Function('return () => __vitePreload(() => import("./X.js"), [])')() as never)
    registry.register('tool:minified', new Function('return ()=>t(()=>import("./X.js"),[])')() as never)
    registry.register('tool:function', new Function('return function(){return import("./X.js")}')() as never)

    expect(registry.isLazy('tool:preload')).toBe(true)
    expect(registry.isLazy('tool:minified')).toBe(true)
    expect(registry.isLazy('tool:function')).toBe(true)
  })

  it('applies to the typed helpers', () => {
    const loader = new Function('return () => import("./X.js")')() as never
    registry.registerFieldDisplay('money', loader)
    registry.registerFieldInput('money', loader)
    registry.registerResourceFieldDisplay('posts', 'price', loader)
    registry.registerResourceFieldInput('posts', 'price', loader)

    expect(registry.keys().every((key) => registry.isLazy(key))).toBe(true)
  })
})

describe('preload', () => {
  it('runs the loader once, however many times it is asked or rendered', async () => {
    const loader = vi.fn(() => Promise.resolve({ default: Hello }))
    registry.registerLazy('tool:lazy', loader)

    await Promise.all([registry.preload('tool:lazy'), registry.preload('tool:lazy')])
    await registry.preload('tool:lazy')
    expect(loader).toHaveBeenCalledTimes(1)

    mount(registry, 'tool:lazy')
    await waitFor(() => expect(screen.getByTestId('hello')).toBeTruthy())
    expect(loader).toHaveBeenCalledTimes(1)
  })

  it('does not run the loader before a render or a preload', () => {
    const loader = vi.fn(() => Promise.resolve({ default: Hello }))
    registry.registerLazy('tool:lazy', loader)

    expect(loader).not.toHaveBeenCalled()
  })

  it('resolves at once for an eager or unknown key', async () => {
    registry.register('tool:eager', Hello)

    await expect(registry.preload('tool:eager')).resolves.toBeUndefined()
    await expect(registry.preload('tool:missing')).resolves.toBeUndefined()
  })

  it('retries after a failed fetch', async () => {
    const loader = vi
      .fn<() => Promise<{ default: typeof Hello }>>()
      .mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValueOnce({ default: Hello })
    registry.registerLazy('tool:lazy', loader)

    await expect(registry.preload('tool:lazy')).rejects.toThrow('offline')
    await expect(registry.preload('tool:lazy')).resolves.toBeUndefined()
    expect(loader).toHaveBeenCalledTimes(2)
  })
})

describe("preload: 'idle'", () => {
  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('fetches idle entries only after runIdlePreloads(), on an idle callback', async () => {
    const idle: Array<() => void> = []
    vi.stubGlobal('requestIdleCallback', (callback: () => void) => idle.push(callback))
    const idleLoader = vi.fn(() => Promise.resolve({ default: Hello }))
    const lazyLoader = vi.fn(() => Promise.resolve({ default: Hello }))
    registry.registerLazy('tool:idle', idleLoader, { preload: 'idle' })
    registry.registerLazy('tool:never', lazyLoader, { preload: 'never' })
    registry.registerLazy('tool:visible', lazyLoader, { preload: 'visible' })

    expect(idle).toHaveLength(0)

    registry.runIdlePreloads()
    expect(idle).toHaveLength(1)
    expect(idleLoader).not.toHaveBeenCalled()

    idle[0]?.()
    expect(idleLoader).toHaveBeenCalledTimes(1)
    expect(lazyLoader).not.toHaveBeenCalled()
  })

  it('starts an idle entry registered after the app mounted', () => {
    const idle: Array<() => void> = []
    vi.stubGlobal('requestIdleCallback', (callback: () => void) => idle.push(callback))

    registry.runIdlePreloads()
    registry.registerLazy('tool:late', () => Promise.resolve({ default: Hello }), { preload: 'idle' })

    expect(idle).toHaveLength(1)
  })

  it('falls back to a timer when requestIdleCallback is missing, and swallows a failed fetch', async () => {
    vi.useFakeTimers()
    vi.stubGlobal('requestIdleCallback', undefined)
    const loader = vi.fn(() => Promise.reject(new Error('offline')))
    registry.registerLazy('tool:idle', loader, { preload: 'idle' })

    registry.runIdlePreloads()
    await vi.advanceTimersByTimeAsync(1000)

    expect(loader).toHaveBeenCalledTimes(1)
  })
})

describe('stale chunk', () => {
  const reload = vi.fn()

  beforeEach(() => {
    reload.mockReset()
    window.sessionStorage.removeItem(STALE_CHUNK_RELOAD_KEY)
    vi.stubGlobal('location', { ...window.location, reload })
    window.MartisConfig = {} as never
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    delete window.MartisConfig
  })

  it('reloads the tab once when the chunk is gone, then shows the error state', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    const chunkError = () => new TypeError('Failed to fetch dynamically imported module: /chunks/Reports-abc.js')
    registry.registerLazy('tool:first', () => Promise.reject(chunkError()))

    mount(registry, 'tool:first')
    await waitFor(() => expect(reload).toHaveBeenCalledTimes(1))
    expect(window.sessionStorage.getItem(STALE_CHUNK_RELOAD_KEY)).not.toBeNull()

    // The reload already happened in this tab: the next failure is an error state.
    registry.registerLazy('tool:second', () => Promise.reject(chunkError()))
    mount(registry, 'tool:second')

    await waitFor(() => expect(screen.getByText(/unexpected error/i)).toBeTruthy())
    expect(reload).toHaveBeenCalledTimes(1)
    consoleError.mockRestore()
  })

  it('does not reload on a failure that is not a chunk error', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    registry.registerLazy('tool:lazy', () => Promise.reject(new Error('Cannot read properties of undefined')))

    mount(registry, 'tool:lazy')

    await waitFor(() => expect(screen.getByText(/unexpected error/i)).toBeTruthy())
    expect(reload).not.toHaveBeenCalled()
    consoleError.mockRestore()
  })

  it('does not reload when the kill switch is off', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    window.MartisConfig = { extensionsReloadOnStale: false } as never
    registry.registerLazy('tool:lazy', () => Promise.reject(new TypeError('Importing a module script failed.')))

    mount(registry, 'tool:lazy')

    await waitFor(() => expect(screen.getByText(/unexpected error/i)).toBeTruthy())
    expect(reload).not.toHaveBeenCalled()
    consoleError.mockRestore()
  })

  it('does not reload from a background preload', async () => {
    registry.registerLazy('tool:lazy', () => Promise.reject(new TypeError('Failed to fetch dynamically imported module')))

    await expect(registry.preload('tool:lazy')).rejects.toThrow()
    expect(reload).not.toHaveBeenCalled()
  })

  it('forgets the reload guard once a chunk loads, so a later deploy can reload again', async () => {
    window.sessionStorage.setItem(STALE_CHUNK_RELOAD_KEY, '1')
    registry.registerLazy('tool:lazy', () => Promise.resolve({ default: Hello }))

    mount(registry, 'tool:lazy')

    await waitFor(() => expect(screen.getByTestId('hello')).toBeTruthy())
    expect(window.sessionStorage.getItem(STALE_CHUNK_RELOAD_KEY)).toBeNull()
  })
})
