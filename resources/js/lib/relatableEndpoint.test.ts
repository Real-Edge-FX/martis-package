import { describe, it, expect } from 'vitest'
import { pickerAttributesQuery, relatableUrl } from './relatableEndpoint'

describe('pickerAttributesQuery', () => {
  it('names the title attribute the picker reads, after the URL own parameters', () => {
    expect(pickerAttributesQuery('name')).toBe('&title_attribute=name')
  })

  it('names the subtitle attribute too when the picker shows subtitles', () => {
    expect(pickerAttributesQuery('name', 'region')).toBe('&title_attribute=name&subtitle_attribute=region')
  })

  it('names nothing when the picker reads no attribute', () => {
    expect(pickerAttributesQuery()).toBe('')
    expect(pickerAttributesQuery('', '')).toBe('')
  })

  it('encodes what it names', () => {
    expect(pickerAttributesQuery('a b&c')).toBe('&title_attribute=a+b%26c')
  })
})

describe('relatableUrl', () => {
  it('has no URL without a resource: the picker then uses the context-free one', () => {
    expect(relatableUrl('owner_id', {}, {})).toBeNull()
  })
})
