import { describe, it, expect, afterEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { publishIndexFilters, searchWithIndexFilters, useIndexFilters } from './indexFilters'

/*
 * The Filters panel of an index keeps its filters out of the URL, so the
 * `?filters=` a link opened the page with outlives a change in the panel.
 * The sidebar reads the panel's filters for that page instead.
 */

afterEach(() => {
  publishIndexFilters(null)
})

describe('index filters for the sidebar', () => {
  it('replaces the URL filters of the same page with the panel filters, or drops them', () => {
    const location = { pathname: '/resources/invoices', search: '?filters=%7B%22status%22%3A%22overdue%22%7D&page=2' }

    expect(searchWithIndexFilters(location, null)).toBe(location.search)
    expect(searchWithIndexFilters(location, { pathname: '/resources/invoices', filters: '{"status":"paid"}' }))
      .toBe('?filters=%7B%22status%22%3A%22paid%22%7D&page=2')
    expect(searchWithIndexFilters(location, { pathname: '/resources/invoices', filters: null })).toBe('?page=2')
    expect(searchWithIndexFilters(location, { pathname: '/resources/clients', filters: null })).toBe(location.search)
  })

  it('notifies the readers when the panel publishes', () => {
    const { result } = renderHook(() => useIndexFilters())
    expect(result.current).toBeNull()

    act(() => publishIndexFilters({ pathname: '/resources/invoices', filters: '{"status":"paid"}' }))
    expect(result.current).toEqual({ pathname: '/resources/invoices', filters: '{"status":"paid"}' })
  })
})
