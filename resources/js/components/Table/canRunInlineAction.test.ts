import { describe, expect, it } from 'vitest'
import type { ResourceRecord } from '@/types'
import type { ActionMeta } from '@/components/Actions'
import { canRunInlineAction, rowActionAuthorization } from './Table'

/*
 * Same root cause as the icon registry finding (F035): `action.uriKey in
 * perAction` and `perAction[action.uriKey]` also find what every object
 * inherits. An action keyed `constructor` was "authorised" by the inherited
 * `Object` (truthy), so a row whose record policy forbids the run still
 * offered it. Only the row's own entries count.
 */

const action = (uriKey: string, extra: Partial<ActionMeta> = {}): ActionMeta =>
  ({ uriKey, name: uriKey, standalone: false, destructive: false, ...extra }) as ActionMeta

const row = (extra: Record<string, unknown>): ResourceRecord => ({ id: 1, ...extra }) as unknown as ResourceRecord

const INHERITED = ['constructor', '__proto__', 'toString', 'valueOf', 'hasOwnProperty']

describe('canRunInlineAction with an action keyed like an inherited member', () => {
  it.each(INHERITED)('falls to the record policy for %s when the row holds no entry of its own', (uriKey) => {
    const denied = row({ _actionAuthorization: { archive: true }, _authorization: { authorizedToRunAction: false } })

    expect(canRunInlineAction(denied, action(uriKey))).toBe(false)
    expect(rowActionAuthorization(denied, action(uriKey))).toBeUndefined()
  })

  it('honours the entry a row holds of its own, true or false', () => {
    const r = row({ _actionAuthorization: { archive: false, publish: true }, _authorization: { authorizedToRunAction: false } })

    expect(canRunInlineAction(r, action('archive'))).toBe(false)
    expect(canRunInlineAction(r, action('publish'))).toBe(true)
  })

  it('honours an own entry that is itself spelled like an inherited member', () => {
    const perAction = JSON.parse('{"constructor": true}') as Record<string, boolean>
    const r = row({ _actionAuthorization: perAction, _authorization: { authorizedToRunAction: false } })

    expect(canRunInlineAction(r, action('constructor'))).toBe(true)
  })
})
