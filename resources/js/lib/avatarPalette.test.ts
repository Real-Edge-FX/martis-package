import { describe, it, expect } from 'vitest'
import {
  avatarColorForSeed,
  avatarPaletteColor,
  avatarPaletteSlot,
  initialsAvatarStyle,
  isAvatarPaletteSlot,
  readableTextColor,
} from './avatarPalette'

/*
 * The server picks the palette slot of the avatars it sends
 * (Martis\Support\Initials). These slots are pinned in
 * tests/Unit/InitialsTest.php too, so a seed lands on the same slot on the
 * server and in the browser.
 */
const sharedSlots: Array<[string, number]> = [
  ['Ada Lovelace', 11],
  ['Grace Hopper', 6],
  ['jane@example.com', 5],
  ['José Álvares', 9],
  ['李小龍', 16],
  ['🙂 Smile', 15],
  ['Zoë', 10],
  ['a', 7],
  ['Jane Doe', 6],
  ['Margaret Hamilton', 5],
]

describe('avatarPaletteSlot', () => {
  it.each(sharedSlots)('lands %s on slot %i, as the server does', (seed, slot) => {
    expect(avatarPaletteSlot(seed)).toBe(slot)
  })

  it('gives an empty seed the neutral slot 16', () => {
    expect(avatarPaletteSlot('')).toBe(16)
    expect(avatarPaletteSlot(null)).toBe(16)
    expect(avatarPaletteSlot(undefined)).toBe(16)
  })
})

describe('avatarPaletteColor', () => {
  it('paints a slot with its theme token', () => {
    expect(avatarPaletteColor(1)).toBe('var(--martis-avatar-1)')
    expect(avatarPaletteColor(16)).toBe('var(--martis-avatar-16)')
  })

  it('paints anything that is not a slot with the neutral token', () => {
    for (const notASlot of [0, 17, 2.5, Number.NaN, null, undefined]) {
      expect(isAvatarPaletteSlot(notASlot)).toBe(false)
      expect(avatarPaletteColor(notASlot)).toBe('var(--martis-avatar-16)')
    }
  })

  it('keeps avatarColorForSeed on the seed\'s slot', () => {
    expect(avatarColorForSeed('Ada Lovelace')).toBe('var(--martis-avatar-11)')
    expect(avatarColorForSeed('')).toBe('var(--martis-avatar-16)')
  })
})

describe('initialsAvatarStyle', () => {
  it('paints the payload\'s palette slot with the theme token', () => {
    expect(initialsAvatarStyle({ palette: 6, color: '#0891b2', seed: 'Jane Doe' }).backgroundColor).toBe(
      'var(--martis-avatar-6)',
    )
  })

  it('paints a colorFrom() colour, which comes without a slot', () => {
    expect(initialsAvatarStyle({ palette: null, color: '#fde047', seed: 'Jane' })).toEqual({
      backgroundColor: '#fde047',
      color: '#0f172a',
    })
  })

  it('paints a payload with neither on its seed\'s slot', () => {
    expect(initialsAvatarStyle({ seed: 'Ada Lovelace' }).backgroundColor).toBe('var(--martis-avatar-11)')
  })
})

describe('readableTextColor', () => {
  it('picks dark text on a pale colour and white on a dark one', () => {
    expect(readableTextColor('#fde047')).toBe('#0f172a')
    expect(readableTextColor('#2563eb')).toBe('#ffffff')
  })

  it('falls back to white when it cannot read the colour', () => {
    expect(readableTextColor(null)).toBe('#fff')
    expect(readableTextColor('var(--martis-avatar-1)')).toBe('#fff')
  })
})
