import { describe, expect, it } from 'vitest'
import {
  applyDashboardFiltersUpdate,
  parseDashboardFilters,
  serializeDashboardFilters,
} from '@/lib/dashboardFilters'
import type { FilterDefinition, FilterType } from '@/types'

function filter(uriKey: string, filterType: FilterType, component: string | null = null): FilterDefinition {
  return {
    type: 'filter',
    filterType,
    name: uriKey,
    uriKey,
    component,
    options: [],
    default: null,
    meta: {},
  }
}

const FILTERS = [
  filter('project', 'select'),
  filter('tags', 'multi-select'),
  filter('flags', 'boolean'),
  filter('day', 'date'),
  filter('period', 'date-range'),
  filter('custom', 'select', 'filter:custom'),
]

function parse(value: unknown): Record<string, unknown> {
  return parseDashboardFilters(JSON.stringify(value), FILTERS)
}

describe('parseDashboardFilters', () => {
  it('reads every filter shape the panel writes', () => {
    const filters = {
      project: 7,
      tags: ['a', 'b'],
      flags: { active: true, archived: false },
      day: '2026-10-09',
      period: { from: '2026-10-01', to: '2026-10-09' },
      custom: { anything: ['the control owns it'] },
    }

    expect(parse(filters)).toEqual(filters)
  })

  it('returns the filters in the dashboard order', () => {
    expect(Object.keys(parse({ period: { from: '2026-10-01' }, project: 'x' }))).toEqual(['project', 'period'])
  })

  it('ignores a malformed payload, a non-object and an empty one', () => {
    expect(parseDashboardFilters('{not json', FILTERS)).toEqual({})
    expect(parseDashboardFilters('["project"]', FILTERS)).toEqual({})
    expect(parseDashboardFilters('"project"', FILTERS)).toEqual({})
    expect(parseDashboardFilters('null', FILTERS)).toEqual({})
    expect(parseDashboardFilters('', FILTERS)).toEqual({})
    expect(parseDashboardFilters(null, FILTERS)).toEqual({})
  })

  it('ignores a key that names no filter of the dashboard, inherited names included', () => {
    expect(parseDashboardFilters('{"unknown":1,"__proto__":{"x":1},"constructor":2,"project":"p"}', FILTERS)).toEqual({ project: 'p' })
  })

  it('ignores a value its control could not show', () => {
    expect(parse({
      project: { id: 1 },
      tags: 'a',
      flags: { active: 'yes' },
      day: '09/10/2026',
      period: { from: 'yesterday' },
    })).toEqual({})
  })

  it('ignores blank values and a boolean filter with nothing checked', () => {
    expect(parse({ project: '', tags: [], flags: { active: false }, period: {}, day: null })).toEqual({})
  })

  it('rejects a date range with a key other than from and to', () => {
    expect(parse({ period: { from: '2026-10-01', until: '2026-10-09' } })).toEqual({})
  })
})

describe('serializeDashboardFilters', () => {
  it('writes the set filters as JSON', () => {
    expect(serializeDashboardFilters({ project: 'p', tags: ['a'] })).toBe('{"project":"p","tags":["a"]}')
  })

  it('returns null when no filter is set, so the parameter is removed', () => {
    expect(serializeDashboardFilters({})).toBeNull()
    expect(serializeDashboardFilters({ project: null, tags: [] })).toBeNull()
  })
})

describe('applyDashboardFiltersUpdate', () => {
  it('merges an object patch into the current filters', () => {
    expect(applyDashboardFiltersUpdate({ project: 'p', day: '2026-10-09' }, { project: 'q' })).toEqual({ project: 'q', day: '2026-10-09' })
  })

  it('clears a filter the patch sets to a blank value', () => {
    expect(applyDashboardFiltersUpdate({ project: 'p', tags: ['a'] }, { project: null, tags: [] })).toEqual({})
  })

  it('replaces the whole set with what a function returns', () => {
    expect(applyDashboardFiltersUpdate({ project: 'p', day: '2026-10-09' }, (current) => ({ day: current.day }))).toEqual({ day: '2026-10-09' })
  })
})
