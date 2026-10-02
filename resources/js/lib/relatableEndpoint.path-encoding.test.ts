import { describe, it, expect } from 'vitest'
import { relatableUrl } from '@/lib/relatableEndpoint'
import { remoteOptionsEndpoint } from '@/hooks/useRemoteSelectOptions'
import { apiSegments, resolveApiPath, QUERY_SMUGGLING_ID, QUERY_SMUGGLING_ID_SEGMENT } from '@/test-support/apiPaths'

/*
 * F099: a relation picker's options URL named the resource, the record and the
 * field attribute raw, and the SPA hands `useParams()` to it as the route. React
 * Router decodes `%2F` and `%3F`, so `/resources/posts/..%2F..%2Fsomething%3F/edit`
 * sent the picker's GET to an endpoint of the attacker's choosing (the trailing
 * `/relatable/...` became a query string). Each value is one encoded segment.
 */

describe('relatableUrl — values from the route stay one path segment', () => {
  it('encodes the record id taken from the route', () => {
    const url = relatableUrl('team_id', {}, { resource: 'posts', id: QUERY_SMUGGLING_ID })!

    expect(url).toBe(`/api/resources/posts/${QUERY_SMUGGLING_ID_SEGMENT}/relatable/team_id`)
    expect(apiSegments(url)).toEqual(['resources', 'posts', QUERY_SMUGGLING_ID_SEGMENT, 'relatable', 'team_id'])
    expect(resolveApiPath(url).search).toBe('')
  })

  it('encodes the resource taken from the route and the field attribute', () => {
    const url = relatableUrl('../../x?y=1', {}, { resource: '../../tools/reports', id: '5' })!

    expect(apiSegments(url)).toEqual(['resources', '..%252F..%252Ftools%252Freports', '5', 'relatable', '..%252F..%252Fx%3Fy%3D1'])
    expect(resolveApiPath(url).search).toBe('')
  })

  it('encodes a record id handed by the form', () => {
    const url = relatableUrl('team_id', { resourceKey: 'posts', recordId: '../users/5' }, {})!

    expect(apiSegments(url)).toEqual(['resources', 'posts', '..%252Fusers%252F5', 'relatable', 'team_id'])
  })

  it('encodes the attribute after an Action or pivot base, and leaves the base as built', () => {
    const base = '/api/resources/projects/actions/assign-owner'
    const url = relatableUrl('../x', { actionEndpoint: base }, {})!

    expect(url).toBe(`${base}/relatable/..%252Fx`)
    expect(relatableUrl('owner', { pivotEndpoint: '/api/resources/projects/3/belongs-to-many/members/pivot-fields' }, {}))
      .toBe('/api/resources/projects/3/belongs-to-many/members/pivot-fields/relatable/owner')
  })

  it('keeps the create-form placeholder and a plain id readable', () => {
    expect(relatableUrl('team_id', { resourceKey: 'posts', context: 'create' }, {})).toBe('/api/resources/posts/_/relatable/team_id')
    expect(relatableUrl('team_id', {}, { resource: 'posts', id: '5' })).toBe('/api/resources/posts/5/relatable/team_id')
  })
})

describe('remoteOptionsEndpoint — keys stay one path segment', () => {
  it('encodes the Tool key and the attribute', () => {
    const url = remoteOptionsEndpoint('../x', { toolKey: '../resources/users' })!

    expect(apiSegments(url)).toEqual(['tools', '..%252Fresources%252Fusers', 'fields', '..%252Fx', 'options'])
  })

  it('encodes the resource key, the attribute and the record id', () => {
    const url = remoteOptionsEndpoint('status', { resourceKey: '../users', context: 'update', recordId: '5&x=1' })!

    expect(apiSegments(url)).toEqual(['resources', '..%252Fusers', 'fields', 'status', 'options'])
    expect(resolveApiPath(url).searchParams.get('id')).toBe('5&x=1')
    expect(resolveApiPath(url).searchParams.get('context')).toBe('update')
    expect(resolveApiPath(url).searchParams.has('x')).toBe(false)
  })
})
