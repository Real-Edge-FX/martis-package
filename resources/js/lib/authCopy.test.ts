import { describe, expect, it } from 'vitest'
import { resolveCopyValue } from './authCopy'

/*
 * v2.22.0: the resolver behind `useAuthCopy()` is shared with the SSO button
 * label (`martis.auth.sso.providers.<name>.label` takes a string or a map of
 * locale => string, like `martis.auth.copy`).
 */

describe('resolveCopyValue', () => {
  it('returns a non-empty string verbatim', () => {
    expect(resolveCopyValue('Continue with Panel A', 'pt_PT')).toBe('Continue with Panel A')
  })

  it('treats an empty or blank string, null and undefined as unset', () => {
    expect(resolveCopyValue('', 'en')).toBeNull()
    expect(resolveCopyValue('   ', 'en')).toBeNull()
    expect(resolveCopyValue(null, 'en')).toBeNull()
    expect(resolveCopyValue(undefined, 'en')).toBeNull()
  })

  it('resolves a map: exact locale, then its prefix, then en, then the first non-empty value', () => {
    const map = { en: 'Continue', pt: 'Continuar', pt_BR: 'Seguir', de: 'Weiter' }

    expect(resolveCopyValue(map, 'pt_BR')).toBe('Seguir')
    expect(resolveCopyValue(map, 'pt_PT')).toBe('Continuar')
    expect(resolveCopyValue(map, 'pt-PT')).toBe('Continuar')
    expect(resolveCopyValue(map, 'fr')).toBe('Continue')
    expect(resolveCopyValue({ de: 'Weiter', fr: 'Continuer' }, 'es')).toBe('Weiter')
  })

  it('skips empty entries of a map and answers null for a map with nothing usable', () => {
    expect(resolveCopyValue({ pt: '', en: 'Continue' }, 'pt')).toBe('Continue')
    expect(resolveCopyValue({ pt: '', en: ' ' }, 'pt')).toBeNull()
    expect(resolveCopyValue({}, 'en')).toBeNull()
  })

  it('answers null for anything else', () => {
    expect(resolveCopyValue(['a'] as unknown as string, 'en')).toBeNull()
    expect(resolveCopyValue(5 as unknown as string, 'en')).toBeNull()
  })
})
