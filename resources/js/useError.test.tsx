import { describe, expect, it } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import { useError } from '@/lib/useError'
import { ApiError } from '@/lib/api'

/**
 * `useError` is public since v1.38.0 (`@martis/runtime`), so its parsing
 * of the errors a consumer's `api` call throws is part of the contract.
 */

describe('useError', () => {
    it('keeps the message of an ApiError and the first error of each field', () => {
        const { result } = renderHook(() => useError())
        const error = new ApiError(422, 'The given data was invalid.', [
            { field: 'title', message: 'The title is required.', code: 'required' },
            { field: 'title', message: 'The title must be a string.', code: 'string' },
            { field: 'slug', message: 'The slug has already been taken.', code: 'unique' },
        ])

        act(() => result.current.setError(error))

        expect(result.current.errors).toEqual({
            message: 'The given data was invalid.',
            fieldErrors: { title: 'The title is required.', slug: 'The slug has already been taken.' },
            apiError: error,
        })
        expect(result.current.hasErrors).toBe(true)
    })

    it('keeps the message of another Error or a string, and a generic one for anything else', () => {
        const { result } = renderHook(() => useError())

        act(() => result.current.setError(new Error('Network down')))
        expect(result.current.errors).toEqual({ message: 'Network down', fieldErrors: {}, apiError: null })

        act(() => result.current.setError('Quota exceeded'))
        expect(result.current.errors.message).toBe('Quota exceeded')

        act(() => result.current.setError({ status: 500 }))
        expect(result.current.errors.message).toBe('An unexpected error occurred.')
    })

    it('clears one field error, then every error', () => {
        const { result } = renderHook(() => useError())
        act(() => result.current.setError(new ApiError(422, 'Invalid.', [
            { field: 'title', message: 'Required.', code: 'required' },
            { field: 'slug', message: 'Taken.', code: 'unique' },
        ])))

        act(() => result.current.clearFieldError('title'))
        expect(result.current.errors.fieldErrors).toEqual({ slug: 'Taken.' })

        act(() => result.current.clearErrors())
        expect(result.current.errors).toEqual({ message: null, fieldErrors: {}, apiError: null })
        expect(result.current.hasErrors).toBe(false)
    })
})
