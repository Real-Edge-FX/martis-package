/**
 * The error map a form keeps: one message per path the server validated,
 * as `ApiError.errorsByField()` flattens a 422. A field's own error sits
 * under its attribute (`lines`); the error of a value inside the field's
 * value sits under that value's dotted path: `lines.1.fields.name` is the
 * `name` field of row 1 of the `lines` Repeater.
 */
export type FormErrors = Record<string, string>

/** The error props a form hands a field input (spread them onto `FieldInput`). */
export interface FieldErrorProps {
  /** The field's own error. */
  error?: string
  /** The errors inside the field's value, keyed by their path below the field's attribute (`1.fields.name`). */
  nestedErrors?: FormErrors
}

/** The errors of one Repeater row. */
export interface RowErrors {
  /** Errors of the row itself, not of one of its fields (`1.type`: a row type the server rejected). */
  row: string[]
  /** Errors of the fields inside the row, keyed by their path below `fields` (`name`, or `links.0.fields.url` inside a nested Repeater). */
  fields: FormErrors
}

/** Whether the error under `key` belongs to the field `attribute`: its own error or one inside its value. */
export function isFieldErrorKey(key: string, attribute: string): boolean {
  return key === attribute || key.startsWith(`${attribute}.`)
}

/**
 * The errors inside the value of the field `attribute`, keyed by their path
 * below it, or `undefined` when there are none. An error the form cleared
 * (an empty message) is left out.
 */
export function nestedErrorsOf(errors: FormErrors | undefined, attribute: string): FormErrors | undefined {
  if (!errors) return undefined
  const prefix = `${attribute}.`
  let nested: FormErrors | undefined
  for (const [key, message] of Object.entries(errors)) {
    if (message && key.startsWith(prefix)) {
      nested ??= {}
      nested[key.slice(prefix.length)] = message
    }
  }
  return nested
}

/** The error props of the field `attribute`: `<FieldInput {...fieldErrorProps(errors, field.attribute)} />`. */
export function fieldErrorProps(errors: FormErrors | undefined, attribute: string): FieldErrorProps {
  return { error: errors?.[attribute], nestedErrors: nestedErrorsOf(errors, attribute) }
}

/**
 * The errors of a Repeater's rows, by row index: `1.fields.name` is the
 * `name` field of row 1, and any other path under a row (`1.type`) is an
 * error of the row itself.
 */
export function rowErrorsByIndex(nestedErrors: FormErrors | undefined): Record<string, RowErrors> {
  const rows: Record<string, RowErrors> = {}
  for (const [path, message] of Object.entries(nestedErrors ?? {})) {
    const match = /^(\d+)(?:\.(.+))?$/.exec(path)
    if (!match || !message) continue
    const [, index, rest] = match
    const row = (rows[index] ??= { row: [], fields: {} })
    if (rest !== undefined && rest.startsWith('fields.')) {
      row.fields[rest.slice('fields.'.length)] = message
    } else {
      row.row.push(message)
    }
  }
  return rows
}
