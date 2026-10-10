import { describe, it, expect, vi } from 'vitest'
import type { QueryClient } from '@tanstack/react-query'
import { invalidateRelationPanel } from './relationViaParams'

/*
 * Each kind of relationship panel keys its query by its own kind. The
 * invalidation after a create or an edit from a panel used to know has-one
 * and has-many only, so a morph-many or morph-one panel kept its old list.
 */

function client() {
  const invalidateQueries = vi.fn()
  return { qc: { invalidateQueries } as unknown as QueryClient, invalidateQueries }
}

describe('invalidateRelationPanel', () => {
  it.each(['has-many', 'has-one', 'morph-many', 'morph-one', 'morph-to-many'])('invalidates the %s panel of the parent', (kind) => {
    const { qc, invalidateQueries } = client()

    invalidateRelationPanel(qc, kind, 'contacts', '13', 'buyerProfiles')

    expect(invalidateQueries).toHaveBeenCalledTimes(1)
    expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: [kind, 'contacts', '13', 'buyerProfiles'] })
  })

  it('treats an absent or unknown kind as has-many', () => {
    const { qc, invalidateQueries } = client()

    invalidateRelationPanel(qc, null, 'contacts', '13', 'notes')
    invalidateRelationPanel(qc, 'nonsense', 'contacts', '13', 'notes')

    expect(invalidateQueries).toHaveBeenNthCalledWith(1, { queryKey: ['has-many', 'contacts', '13', 'notes'] })
    expect(invalidateQueries).toHaveBeenNthCalledWith(2, { queryKey: ['has-many', 'contacts', '13', 'notes'] })
  })
})
