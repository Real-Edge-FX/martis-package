/**
 * Open an external URL in a new tab, as an external menu link does
 * (`target="_blank" rel="noreferrer"`), so the panel stays open.
 */
export function openExternal(url: string): void {
  window.open(url, '_blank', 'noopener,noreferrer')
}
