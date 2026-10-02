import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/config', () => ({ config: { auth: {} }, BASE_PATH: '/martis', API_BASE_URL: 'http://localhost/martis' }))

import { api, ApiError } from '@/lib/api'
import { apiPath, hasDotSegment, pathSegment, routePath, withQuery } from '@/lib/apiPath'

// The browser resolves a request URL before it leaves: `..` is removed, `?`
// starts the query, `#` the fragment, a backslash is a slash. These tests
// resolve the paths the way fetch() does (WHATWG URL) and assert that a value
// stays ONE segment of the endpoint it was built for.

const ORIGIN = 'http://localhost/martis'

/** The path the server sees for `path`, resolved as the browser does. */
function resolved(path: string): URL {
  return new URL(`${ORIGIN}${path}`)
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('pathSegment', () => {
  it.each([
    ['5', '5'],
    [5, '5'],
    ['a-b_c', 'a-b_c'],
    ['../users/5', '..%252Fusers%252F5'],
    ['5/force', '5%252Fforce'],
    ['5?email=x', '5%3Femail%3Dx'],
    ['5#frag', '5%23frag'],
    ['a\\b', 'a%5Cb'],
    ['a%b', 'a%25b'],
    ['a b', 'a%20b'],
  ])('encodes %j as one segment', (value, expected) => {
    expect(pathSegment(value)).toBe(expected)
  })

  it('spells a value made of dots so no spelling of it is a plain dot segment', () => {
    expect(pathSegment('.')).toBe('%2E')
    expect(pathSegment('..')).toBe('%2E%2E')
    // ... which the URL parser still reads as one, so request() refuses it.
    expect(hasDotSegment(`/api/resources/posts/${pathSegment('..')}`)).toBe(true)
  })

  it('turns a missing value into text that names no endpoint', () => {
    expect(pathSegment(undefined)).toBe('undefined')
    expect(pathSegment(null)).toBe('null')
  })
})

describe('apiPath', () => {
  it('keeps the literal text and encodes every interpolated value', () => {
    expect(apiPath`/api/resources/${'posts'}/${5}/restore`).toBe('/api/resources/posts/5/restore')
  })

  it('keeps a record id that traverses to one segment of the endpoint', () => {
    const path = apiPath`/api/resources/${'posts'}/${'../users/5'}`

    expect(path).toBe('/api/resources/posts/..%252Fusers%252F5')
    expect(resolved(path).pathname).toBe('/martis/api/resources/posts/..%252Fusers%252F5')
    expect(resolved(path).search).toBe('')
  })

  // Laravel decodes the path before it matches a route (`rawurldecode`), so a
  // plain `%2F` would be a slash again: `5%2Fforce` reaches the force-delete
  // route of record 5. A slash is spelt `%252F`, which the server decodes to
  // the text `%2F`: no route splits it, and no real key holds it.
  it('spells a slash so the server, which decodes the path before routing, never sees one', () => {
    const path = apiPath`/api/resources/${'posts'}/${'5/force'}`

    expect(path).toBe('/api/resources/posts/5%252Fforce')
    expect(decodeURIComponent(resolved(path).pathname)).toBe('/martis/api/resources/posts/5%2Fforce')
    expect(decodeURIComponent(resolved(path).pathname).split('/')).toEqual(['', 'martis', 'api', 'resources', 'posts', '5%2Fforce'])
  })

  it('leaves a slash in a query value as a plain %2F: the server decodes a query value once', () => {
    expect(apiPath`/api/search?q=${'a/b'}`).toBe('/api/search?q=a%2Fb')
    expect(apiPath`/api/resources/${'posts'}/${'x'}?next=${'/a/b'}`).toBe('/api/resources/posts/x?next=%2Fa%2Fb')
  })

  it('keeps a route param that smuggles a query string out of the query string', () => {
    // What React Router hands the page for `/resources/posts/..%2Fusers%2F5%3Femail%3Da%40b.c/edit`.
    const id = '../users/5?email=a@b.c'
    const path = apiPath`/api/resources/${'posts'}/${id}?context=update`

    expect(resolved(path).pathname).toBe('/martis/api/resources/posts/..%252Fusers%252F5%3Femail%3Da%40b.c')
    expect(resolved(path).searchParams.get('context')).toBe('update')
    expect(resolved(path).searchParams.has('email')).toBe(false)
  })

  it('keeps a value with a backslash from becoming a slash', () => {
    expect(resolved(apiPath`/api/resources/${'posts'}/${'..\\users\\5'}`).pathname).toBe('/martis/api/resources/posts/..%5Cusers%5C5')
  })

  it('encodes a query value placed in the literal text', () => {
    expect(apiPath`/api/search?q=${'a&b=c#d'}`).toBe('/api/search?q=a%26b%3Dc%23d')
  })

})

describe('routePath', () => {
  it('encodes the values of a path of the SPA router, a slash as a plain %2F that React Router decodes back', () => {
    expect(routePath`/resources/${'posts'}/${'a/b'}/edit`).toBe('/resources/posts/a%2Fb/edit')
    expect(routePath`/resources/${'posts'}/${'../users/5?x=1'}`).toBe('/resources/posts/..%2Fusers%2F5%3Fx%3D1')
  })

  it('spells a value made of dots so no spelling of it is a plain dot segment', () => {
    expect(routePath`/resources/${'posts'}/${'..'}`).toBe('/resources/posts/%2E%2E')
  })
})

describe('withQuery', () => {
  it('starts the query string, or continues the one the url has', () => {
    expect(withQuery('/api/x', 'a=1')).toBe('/api/x?a=1')
    expect(withQuery('/api/x?a=1', 'b=2')).toBe('/api/x?a=1&b=2')
    expect(withQuery('/api/x', '')).toBe('/api/x')
  })
})

describe('hasDotSegment', () => {
  it.each([
    '/api/resources/posts/../users/5',
    '/api/resources/posts/./5',
    '/api/resources/posts/%2e%2e/users/5',
    '/api/resources/posts/%2E%2E/users/5',
    '/api/resources/posts/.%2e/users/5',
    '/api/resources/posts/%2e./users/5',
    '/api/resources/posts/%2e/5',
    '/api/resources/posts/..\\users\\5',
    '/api/resources/posts/.\t./users/5',
    '/api/resources/posts/..',
  ])('finds the dot segment of %j', (path) => {
    expect(hasDotSegment(path)).toBe(true)
  })

  it.each([
    '/api/resources/posts/5',
    '/api/resources/posts/..%2Fusers%2F5',
    '/api/resources/posts/...',
    '/api/resources/posts/a..b',
    '/api/resources/posts/5?next=../x',
    '/api/resources/posts/5#../x',
    '/api/resources/posts/.hidden',
  ])('leaves %j alone', (path) => {
    expect(hasDotSegment(path)).toBe(false)
  })
})

describe('the request helpers refuse a path the browser would rewrite', () => {
  it('does not send a GET with a dot segment', async () => {
    const fetchSpy = vi.fn()
    vi.stubGlobal('fetch', fetchSpy)

    await expect(api.get('/api/resources/posts/../users/5')).rejects.toBeInstanceOf(ApiError)
    expect(fetchSpy).not.toHaveBeenCalled()
  })

  it('does not send a DELETE, PUT, POST or PATCH with one either', async () => {
    const fetchSpy = vi.fn()
    vi.stubGlobal('fetch', fetchSpy)

    await expect(api.delete('/api/resources/posts/../users/5')).rejects.toBeInstanceOf(ApiError)
    await expect(api.put('/api/resources/posts/%2e%2e/users/5', {})).rejects.toBeInstanceOf(ApiError)
    await expect(api.post('/api/resources/posts/./5/actions/x', {})).rejects.toBeInstanceOf(ApiError)
    await expect(api.patch('/api/resources/posts/..\\users', {})).rejects.toBeInstanceOf(ApiError)
    expect(fetchSpy).not.toHaveBeenCalled()
  })

  it('does not send a multipart upload with one', async () => {
    const fetchSpy = vi.fn()
    vi.stubGlobal('fetch', fetchSpy)

    await expect(api.upload('PUT', '/api/resources/posts/../users/5', { name: 'x' })).rejects.toBeInstanceOf(ApiError)
    expect(fetchSpy).not.toHaveBeenCalled()
  })

  it('sends the path an apiPath built, with the value as one segment', async () => {
    const fetchSpy = vi.fn(async () => new Response('{}', { status: 200 }))
    vi.stubGlobal('fetch', fetchSpy)

    await api.delete(apiPath`/api/resources/${'posts'}/${'../users/5'}`)

    expect(fetchSpy).toHaveBeenCalledTimes(1)
    const [url, init] = fetchSpy.mock.calls[0] as unknown as [string, RequestInit]
    expect(url).toBe('http://localhost/martis/api/resources/posts/..%252Fusers%252F5')
    expect(new URL(url).pathname).toBe('/martis/api/resources/posts/..%252Fusers%252F5')
    expect(init.method).toBe('DELETE')
  })

  it('refuses a record keyed with dots only instead of redirecting the request', async () => {
    const fetchSpy = vi.fn()
    vi.stubGlobal('fetch', fetchSpy)

    await expect(api.delete(apiPath`/api/resources/${'posts'}/${'..'}`)).rejects.toBeInstanceOf(ApiError)
    expect(fetchSpy).not.toHaveBeenCalled()
  })
})

// B-D6: the slash is spelt `%252F`, which the server decodes to the text
// `%2F`. A value that already holds the text `%2F` encoded to the same wire
// segment, so a request meant for the record keyed `a/b` also addressed the
// record keyed `a%2Fb`, and the other way round. The encoding cannot tell them
// apart (the server decodes once), so a value holding a literal `%2F` is
// refused, with the error `request()` answers a dot segment with.
describe('pathSegment is injective: a value that holds a literal %2F is refused', () => {
  it.each(['a%2Fb', 'a%2fb', '%2F', '%2f', 'x%2F', 'a%2Fb%2Fc', '5%2Fforce', '..%2Fusers%2F5'])('refuses %j in a path segment, with an ApiError (400)', (value) => {
    let thrown: unknown
    try {
      pathSegment(value)
    } catch (error) {
      thrown = error
    }

    expect(thrown).toBeInstanceOf(ApiError)
    expect((thrown as ApiError).status).toBe(400)
  })

  it('refuses it through the template tag, and sends nothing', async () => {
    const fetchSpy = vi.fn()
    vi.stubGlobal('fetch', fetchSpy)

    expect(() => apiPath`/api/resources/${'posts'}/${'a%2Fb'}`).toThrow(ApiError)
    expect(fetchSpy).not.toHaveBeenCalled()
  })

  it('never gives two distinct values the same wire segment', () => {
    const values = ['a/b', 'a%252Fb', 'a%25b', 'a%b', 'a%2', 'a%2G', 'a%F', 'a\\b', 'a b', 'ab', '%', '%%', '/', '%25', '%252F', '..', '.', '5']
    const wires = values.map((value) => pathSegment(value))

    expect(new Set(wires).size).toBe(values.length)
  })

  // The nearest neighbours of `%2F` that stay accepted and keep their encoding.
  it.each([
    ['a/b', 'a%252Fb'],
    ['a%252Fb', 'a%25252Fb'],
    ['a%2', 'a%252'],
    ['a%2G', 'a%252G'],
    ['a%F2', 'a%25F2'],
    ['%', '%25'],
    ['2F', '2F'],
    ['a%25b', 'a%2525b'],
  ])('still encodes %j as %j', (value, expected) => {
    expect(pathSegment(value)).toBe(expected)
  })

  it('does not refuse a literal %2F in a query value: the server decodes a query value once, so it is injective there', () => {
    expect(apiPath`/api/search?q=${'a%2Fb'}`).toBe('/api/search?q=a%252Fb')
    expect(apiPath`/api/search?q=${'a/b'}`).toBe('/api/search?q=a%2Fb')
  })

  it('does not refuse it in a path of the SPA router: React Router decodes once, so it is injective there too', () => {
    expect(routePath`/resources/${'posts'}/${'a%2Fb'}`).toBe('/resources/posts/a%252Fb')
    expect(routePath`/resources/${'posts'}/${'a/b'}`).toBe('/resources/posts/a%2Fb')
  })
})

