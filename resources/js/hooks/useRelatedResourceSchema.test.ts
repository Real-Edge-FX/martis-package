import { describe, it, expect } from 'vitest'
import { relatedResourceSchemaPath } from './useRelatedResourceSchema'

describe('relatedResourceSchemaPath', () => {
  it('returns the plain schema path without via arguments', () => {
    expect(relatedResourceSchemaPath('tasks')).toBe('/api/resources/tasks/schema')
    expect(relatedResourceSchemaPath('tasks', null, null)).toBe('/api/resources/tasks/schema')
  })

  it('appends both via parameters, URL-encoded, when both are given', () => {
    const path = relatedResourceSchemaPath('tasks', 'projects', 'my tasks&x=1')
    const [base, query] = path.split('?')

    expect(base).toBe('/api/resources/tasks/schema')
    const params = new URLSearchParams(query)
    expect(params.get('viaResource')).toBe('projects')
    expect(params.get('viaRelationship')).toBe('my tasks&x=1')
    expect(Array.from(params.keys())).toEqual(['viaResource', 'viaRelationship'])
  })

  it('does not append anything when only one via argument is given', () => {
    expect(relatedResourceSchemaPath('tasks', 'projects')).toBe('/api/resources/tasks/schema')
    expect(relatedResourceSchemaPath('tasks', undefined, 'tasks')).toBe('/api/resources/tasks/schema')
    expect(relatedResourceSchemaPath('tasks', '', 'tasks')).toBe('/api/resources/tasks/schema')
  })
})
