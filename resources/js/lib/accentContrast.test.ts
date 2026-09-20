import { describe, expect, it } from 'vitest'
import { accentContrastFor, ACCENT_CONTRAST_DARK, ACCENT_CONTRAST_LIGHT } from './accentContrast'

// Mirrors Martis\Preferences\AccentContrast (PHP): the SSR block, the boot
// script and PreferencesContext must agree on the contrast colour they
// derive for a hex, or the UI would flash between them on load.
describe('accentContrastFor', () => {
  it('picks white for dark and mid-tone accents', () => {
    for (const hex of ['#4F7BF9', '#0070F0', '#1a73e8', '#7C3AED', '#0D9488', '#000000']) {
      expect(accentContrastFor(hex)).toBe(ACCENT_CONTRAST_LIGHT)
    }
  })

  it('picks the dark navy for bright accents', () => {
    for (const hex of ['#C6F135', '#16E7D8', '#F59E0B', '#FFFFFF', '#FDE047']) {
      expect(accentContrastFor(hex)).toBe(ACCENT_CONTRAST_DARK)
    }
  })

  it('tolerates 3/4/8-digit hex and falls back to white on garbage', () => {
    expect(accentContrastFor('#fff')).toBe(ACCENT_CONTRAST_DARK)
    expect(accentContrastFor('#000f')).toBe(ACCENT_CONTRAST_LIGHT)
    expect(accentContrastFor('#C6F135ff')).toBe(ACCENT_CONTRAST_DARK)
    expect(accentContrastFor('nope')).toBe(ACCENT_CONTRAST_LIGHT)
  })
})
