import { describe, it, expect } from 'vitest'
import { ApiError } from '@/lib/api'
import { fieldErrorProps, isFieldErrorKey, nestedErrorsOf, rowErrorsByIndex } from './fieldErrors'

/*
 * A 422 keys the error of a value inside a field's value by its dotted path:
 * the `name` field of row 1 of the `lines` Repeater is
 * `lines.1.fields.name`. `ApiError.errorsByField()` keeps those keys, so a
 * form that handed each input `errors[field.attribute]` never gave a
 * Repeater the errors of its rows. These helpers split the form's error map
 * per field and per row.
 */

const errors = new ApiError(422, 'The given data was invalid.', [
  { field: 'title', message: 'The Title field is required.', code: 'required' },
  { field: 'lines', message: 'The Lines field must be an array.', code: 'invalid' },
  { field: 'lines.1.fields.name', message: 'The Name field is required.', code: 'required' },
  { field: 'lines.1.fields.name', message: 'A second message for the same path.', code: 'invalid' },
  { field: 'lines.2.type', message: 'The selected Row type is invalid.', code: 'invalid' },
  { field: 'lines.2.fields.links.0.fields.url', message: 'The URL field is required.', code: 'required' },
  { field: 'lines_extra', message: 'The Lines Extra field is required.', code: 'required' },
]).errorsByField()

describe('nestedErrorsOf', () => {
  it('returns the errors inside a field value, keyed by their path below the field', () => {
    expect(nestedErrorsOf(errors, 'lines')).toEqual({
      '1.fields.name': 'The Name field is required.',
      '2.type': 'The selected Row type is invalid.',
      '2.fields.links.0.fields.url': 'The URL field is required.',
    })
  })

  it('returns undefined for a field with no error inside its value', () => {
    expect(nestedErrorsOf(errors, 'title')).toBeUndefined()
    expect(nestedErrorsOf(undefined, 'lines')).toBeUndefined()
  })

  it('leaves out an error the form cleared', () => {
    expect(nestedErrorsOf({ 'lines.0.fields.name': '' }, 'lines')).toBeUndefined()
  })
})

describe('fieldErrorProps', () => {
  it('gives an input its own error and the errors inside its value', () => {
    expect(fieldErrorProps(errors, 'lines')).toEqual({
      error: 'The Lines field must be an array.',
      nestedErrors: nestedErrorsOf(errors, 'lines'),
    })
  })

  it('gives a scalar field its own error only', () => {
    expect(fieldErrorProps(errors, 'title')).toEqual({ error: 'The Title field is required.', nestedErrors: undefined })
  })
})

describe('isFieldErrorKey', () => {
  it('matches the field attribute and the paths inside its value, not a longer attribute', () => {
    expect(isFieldErrorKey('lines', 'lines')).toBe(true)
    expect(isFieldErrorKey('lines.1.fields.name', 'lines')).toBe(true)
    expect(isFieldErrorKey('lines_extra', 'lines')).toBe(false)
    expect(isFieldErrorKey('title', 'lines')).toBe(false)
  })
})

describe('rowErrorsByIndex', () => {
  it('groups the errors of a Repeater by row: the row fields and the row itself', () => {
    expect(rowErrorsByIndex(nestedErrorsOf(errors, 'lines'))).toEqual({
      1: { row: [], fields: { name: 'The Name field is required.' } },
      2: {
        row: ['The selected Row type is invalid.'],
        fields: { 'links.0.fields.url': 'The URL field is required.' },
      },
    })
  })

  it('returns no rows when there is nothing nested', () => {
    expect(rowErrorsByIndex(undefined)).toEqual({})
  })
})
