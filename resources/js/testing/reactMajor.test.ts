import { describe, expect, it } from 'vitest'
import { assertPanelReactMajor } from './reactMajor'

describe('assertPanelReactMajor', () => {
  it('passes a test run on the panel\'s major, whatever the minor', () => {
    expect(() => assertPanelReactMajor('18.3.1', { react: '18.2.0', reactDom: '18.2.0' })).not.toThrow()
    expect(() => assertPanelReactMajor('18.3.1', { react: '18.3.1', reactDom: '18.3.1' })).not.toThrow()
  })

  it('fails a test run on React 19, naming both versions and the install command', () => {
    expect(() => assertPanelReactMajor('18.3.1', { react: '19.3.0', reactDom: '19.3.0' })).toThrow(
      '[martis] This test run loads react 19.3.0 and react-dom 19.3.0, but the Martis panel runs React 18.3.1: an extension tested on another React major can pass here and fail in the panel. Install the panel\'s major in your app: npm install react@^18 react-dom@^18 (docs/testing-extensions.md).',
    )
  })

  it('fails when only react-dom is on another major', () => {
    expect(() => assertPanelReactMajor('18.3.1', { react: '18.3.1', reactDom: '19.0.0' })).toThrow(/loads react-dom 19\.0\.0, but the Martis panel runs React 18\.3\.1/)
  })

  it('reads the major of a prerelease', () => {
    expect(() => assertPanelReactMajor('18.3.1', { react: '19.0.0-rc.1', reactDom: '18.3.1' })).toThrow(/loads react 19\.0\.0-rc\.1,/)
    expect(() => assertPanelReactMajor('19.0.0-rc.1', { react: '19.1.0', reactDom: '19.1.0' })).not.toThrow()
  })
})
