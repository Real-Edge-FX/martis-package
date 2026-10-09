import { useEffect, useState } from 'react'
import { useLocation } from 'react-router'

/** What an emailed one-time link carries in its fragment. */
export interface AuthLinkParams {
  token: string
  email: string
}

/**
 * Parse the fragment of an emailed one-time link (`#token=…&email=…`).
 * Missing values are empty strings.
 */
export function parseAuthLinkFragment(hash: string): AuthLinkParams {
  const params = new URLSearchParams(hash.startsWith('#') ? hash.slice(1) : hash)

  return {
    token: params.get('token') ?? '',
    email: params.get('email') ?? '',
  }
}

/**
 * The token and email of the emailed link that opened this page: a password
 * reset, an invitation or a magic-link sign-in (v2.6.0).
 *
 * Martis puts them in the URL fragment, which the browser never sends to the
 * server, so they stay out of the request line that proxies, web servers and
 * APM layers log. The page sends the token in the body of its POST.
 *
 * Read once, then the fragment is dropped from the address bar, so the token
 * does not stay on screen, in a screenshot or in a bookmark.
 */
export function useAuthLinkParams(): AuthLinkParams {
  const { hash } = useLocation()
  const [params] = useState(() => parseAuthLinkFragment(hash))

  useEffect(() => {
    if (window.location.hash === '') return
    const url = new URL(window.location.href)
    url.hash = ''
    window.history.replaceState(window.history.state, '', url.pathname + url.search)
  }, [])

  return params
}
