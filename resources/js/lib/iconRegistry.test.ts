import { describe, expect, it } from 'vitest'
import { DatabaseIcon, UserIcon } from '@phosphor-icons/react'
import { iconRegistry } from './iconRegistry'

/*
 * Security regression (F035): the curated icons are a plain object, and the
 * lookup used `in`, which walks the prototype chain. A stored icon name that
 * normalises to an inherited member (`constructor` stays as it is) resolved
 * to `Object` itself, which React calls as a function component and which
 * throws "Objects are not valid as a React child", taking the whole page
 * down for everyone who opens the record. Only the registry's own entries
 * resolve now, and any other name falls back like a typo.
 */

const INHERITED = ['constructor', 'Constructor', '__proto__', 'toString', 'valueOf', 'hasOwnProperty', 'isPrototypeOf', 'propertyIsEnumerable', 'toLocaleString']

describe('iconRegistry names that are Object.prototype members', () => {
  it.each(INHERITED)('resolves %s to a component, never to an inherited member', (name) => {
    const resolved = iconRegistry.resolve(name)

    expect(resolved).not.toBe(Object)
    expect(resolved).not.toBe(Object.prototype)
    expect(typeof resolved === 'function' || (typeof resolved === 'object' && resolved !== null && '$$typeof' in resolved)).toBe(true)
    expect(resolved).toBe(DatabaseIcon)
  })

  it.each(INHERITED)('does not report %s as an eagerly bundled icon', (name) => {
    expect(iconRegistry.has(name)).toBe(false)
  })
})

describe('iconRegistry known names', () => {
  it('still resolves a curated icon synchronously, by any spelling', () => {
    expect(iconRegistry.resolve('user')).toBe(UserIcon)
    expect(iconRegistry.resolve('User')).toBe(UserIcon)
    expect(iconRegistry.resolve('UserIcon')).toBe(UserIcon)
    expect(iconRegistry.has('user')).toBe(true)
  })

  it('falls back to the database icon for an empty or unknown name', () => {
    expect(iconRegistry.resolve(null)).toBe(DatabaseIcon)
    expect(iconRegistry.resolve('')).toBe(DatabaseIcon)
    expect(iconRegistry.resolve('no-such-icon-anywhere')).toBe(DatabaseIcon)
    expect(iconRegistry.has('no-such-icon-anywhere')).toBe(false)
  })

  it('lets a registered custom icon win, and reports it', () => {
    const Custom = () => null
    iconRegistry.register('my-custom-icon', Custom)

    expect(iconRegistry.resolve('my-custom-icon')).toBe(Custom)
    expect(iconRegistry.has('my-custom-icon')).toBe(true)
  })
})
