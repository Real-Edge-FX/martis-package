/**
 * The Content-Security-Policy nonce of the page, when the application set
 * one (`Vite::useCspNonce()`).
 *
 * The shell (`resources/views/app.blade.php`) stamps the nonce on every tag
 * it renders and publishes it in `<meta name="csp-nonce">` for what the SPA
 * injects at run time: the `<style>` elements of PrimeReact and CodeMirror
 * (Trix reads the same meta tag itself). A policy that drops `'unsafe-inline'`
 * from `style-src` blocks those elements unless they carry the nonce.
 *
 * Returns null when no nonce was set, so the elements stay as they were.
 */
export function readCspNonce(): string | null {
  if (typeof document === 'undefined') return null

  const content = document.querySelector('meta[name="csp-nonce"]')?.getAttribute('content')

  return content !== undefined && content !== null && content !== '' ? content : null
}
