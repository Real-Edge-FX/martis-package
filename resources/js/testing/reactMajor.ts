/** The major of a React version string (`18` for `18.3.1`, `19` for `19.0.0-rc.1`). */
function major(version: string): string {
  return version.split('.')[0] ?? version
}

/**
 * Fails a consumer's test run that loads another React major than the one
 * the Martis panel runs (v2.3.0). The test runtime uses the app's React, so
 * an extension that calls an API of another major (React 19's `use`) would
 * pass its tests and then throw in the panel, which serves its own React
 * (`window.Martis.react`) to every extension.
 *
 * @param panel the React version the test runtime was built against, the panel's
 * @param loaded the versions of `react` and `react-dom` the test run loads
 */
export function assertPanelReactMajor(panel: string, loaded: { react: string; reactDom: string }): void {
  const wrong = [
    ['react', loaded.react],
    ['react-dom', loaded.reactDom],
  ].filter(([, version]) => major(version) !== major(panel))
  if (wrong.length === 0) return

  const want = major(panel)
  throw new Error(
    `[martis] This test run loads ${wrong.map(([name, version]) => `${name} ${version}`).join(' and ')}, but the Martis panel runs React ${panel}: `
      + `an extension tested on another React major can pass here and fail in the panel. `
      + `Install the panel's major in your app: npm install react@^${want} react-dom@^${want} (docs/testing-extensions.md).`,
  )
}
