import { describe, it, expect, vi, beforeEach } from 'vitest'
import { EventBus, martisEventBus } from '@/lib/eventBus'

describe('EventBus', () => {
  let bus: EventBus

  beforeEach(() => {
    bus = new EventBus()
  })

  it('calls registered handler on emit', () => {
    const handler = vi.fn()
    bus.on('martis:record-created', handler)
    bus.emit('martis:record-created', { resourceKey: 'posts', id: 1 })

    expect(handler).toHaveBeenCalledTimes(1)
    expect(handler).toHaveBeenCalledWith({ resourceKey: 'posts', id: 1 })
  })

  it('calls multiple handlers for the same event', () => {
    const h1 = vi.fn()
    const h2 = vi.fn()
    bus.on('martis:record-updated', h1)
    bus.on('martis:record-updated', h2)
    bus.emit('martis:record-updated', { resourceKey: 'posts', id: 2 })

    expect(h1).toHaveBeenCalledTimes(1)
    expect(h2).toHaveBeenCalledTimes(1)
  })

  it('does not call handler after off()', () => {
    const handler = vi.fn()
    bus.on('martis:record-deleted', handler)
    bus.off('martis:record-deleted', handler)
    bus.emit('martis:record-deleted', { resourceKey: 'posts', id: 3 })

    expect(handler).not.toHaveBeenCalled()
  })

  it('calls once() handler only once', () => {
    const handler = vi.fn()
    bus.once('martis:refresh-index', handler)

    bus.emit('martis:refresh-index', { resourceKey: 'posts' })
    bus.emit('martis:refresh-index', { resourceKey: 'posts' })

    expect(handler).toHaveBeenCalledTimes(1)
  })

  it('does not throw when emitting with no handlers', () => {
    expect(() => bus.emit('martis:refresh-index')).not.toThrow()
  })

  it('isolates events from each other', () => {
    const h1 = vi.fn()
    const h2 = vi.fn()
    bus.on('martis:record-created', h1)
    bus.on('martis:record-updated', h2)

    bus.emit('martis:record-created', { resourceKey: 'posts', id: 1 })

    expect(h1).toHaveBeenCalledTimes(1)
    expect(h2).not.toHaveBeenCalled()
  })

  it('listenerCount returns correct count', () => {
    expect(bus.listenerCount('martis:record-created')).toBe(0)

    const h1 = vi.fn()
    const h2 = vi.fn()
    bus.on('martis:record-created', h1)
    bus.on('martis:record-created', h2)

    expect(bus.listenerCount('martis:record-created')).toBe(2)

    bus.off('martis:record-created', h1)
    expect(bus.listenerCount('martis:record-created')).toBe(1)
  })

  it('clear() removes all handlers for an event', () => {
    const h1 = vi.fn()
    bus.on('martis:record-created', h1)
    bus.clear('martis:record-created')
    bus.emit('martis:record-created', { resourceKey: 'posts', id: 1 })

    expect(h1).not.toHaveBeenCalled()
  })

  it('clear() without arguments removes all handlers', () => {
    const h1 = vi.fn()
    const h2 = vi.fn()
    bus.on('martis:record-created', h1)
    bus.on('martis:record-updated', h2)

    bus.clear()

    bus.emit('martis:record-created', { resourceKey: 'posts', id: 1 })
    bus.emit('martis:record-updated', { resourceKey: 'posts', id: 1 })

    expect(h1).not.toHaveBeenCalled()
    expect(h2).not.toHaveBeenCalled()
  })

  it('handler throwing does not stop other handlers from running', () => {
    const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    const throwingHandler = () => { throw new Error('boom') }
    const safeHandler = vi.fn()

    bus.on('martis:action-executed', throwingHandler)
    bus.on('martis:action-executed', safeHandler)

    expect(() => bus.emit('martis:action-executed', { resourceKey: 'posts', action: 'test', ids: [1] })).not.toThrow()
    expect(safeHandler).toHaveBeenCalledTimes(1)

    consoleSpy.mockRestore()
  })

  it('exports a singleton martisEventBus', () => {
    expect(martisEventBus).toBeInstanceOf(EventBus)
  })
})
