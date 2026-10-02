import { describe, expect, it } from 'vitest'
import { ApiError } from '@/lib/api'
import { deleteErrorMessage } from './deleteError'

describe('deleteErrorMessage', () => {
  it('shows the message the API answered, such as the reason a hook gave', () => {
    expect(deleteErrorMessage(new ApiError(422, 'This record is protected and cannot be deleted.'), 'Error deleting record.'))
      .toBe('This record is protected and cannot be deleted.')
  })

  it('shows the generic message the server keeps for an internal failure', () => {
    expect(deleteErrorMessage(new ApiError(500, 'Error deleting record.'), 'fallback')).toBe('Error deleting record.')
  })

  it('falls back for an error that is not an API answer', () => {
    expect(deleteErrorMessage(new TypeError('Failed to fetch'), 'Error deleting record.')).toBe('Error deleting record.')
    expect(deleteErrorMessage(undefined, 'Error deleting record.')).toBe('Error deleting record.')
  })

  it('falls back for an API answer without a message', () => {
    expect(deleteErrorMessage(new ApiError(500, ''), 'Error deleting record.')).toBe('Error deleting record.')
  })
})
