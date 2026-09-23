import type { FieldDefinition } from '@/types'

/** Shared props for every field renderer. */
export interface FieldDisplayProps {
  field: FieldDefinition
  value: unknown
}

/** A Repeater row: the Repeater's attribute and the row type (`Repeatable::shortName()`). */
export interface RepeaterRowScope {
  repeater: string
  repeatable: string
}

/** Props for renderers used inside a form context (create/update). */
export interface FieldInputProps {
  field: FieldDefinition
  value: unknown
  onChange: (value: unknown) => void
  error?: string
  /**
   * The server errors of the values inside this field's value, keyed by their
   * path below the field's attribute: `1.fields.name` is the `name` field of
   * row 1 of a Repeater. Only an input whose value holds other values (the
   * Repeater's rows) reads it. Every bundled form fills it from its error map,
   * and so does `useMartisForm().fieldProps(field)`.
   */
  nestedErrors?: Record<string, string>
  /** The resource URI key (e.g. 'posts') — used by relatable fields to build the correct API endpoint. */
  resourceKey?: string
  /** The record ID being edited — used by relatable fields for contextual relatable queries. */
  recordId?: string | number
  /** All current form values. Fields that need to react to other fields (e.g. Slug source, dependsOn) read this. */
  formValues?: Record<string, unknown>
  /** The form context the input renders in. Server-scoped fields send it so the backend looks the field up in the matching field set. */
  context?: 'create' | 'update'
  /**
   * Base path of the Action's endpoints when the input renders one of an
   * Action's fields (e.g. `/api/resources/posts/actions/publish-post`).
   * Relation pickers (`BelongsTo`, `MorphTo`, `Tag`) then load their options
   * from `{actionEndpoint}/relatable/{attribute}`, which reads the Action's
   * own declaration of the field instead of the page's resource. The action
   * modals leave `resourceKey` unset, so per-resource input overrides and
   * resource-scoped lookups do not reach Action fields.
   */
  actionEndpoint?: string
  /**
   * Base path of a many-to-many panel's pivot fields when the input renders
   * one of them: `/api/resources/{resource}/{id}/{belongs-to-many|morph-to-many}/{relationship}/pivot-fields`
   * in the attach modal, plus `/{relatedId}` in the pivot edit modal of an
   * attached record. Relation pickers then load their options from
   * `{pivotEndpoint}/relatable/{attribute}`, which reads the relationship's
   * pivot fields instead of the parent resource's forms.
   */
  pivotEndpoint?: string
  /**
   * The Repeater row the input renders in, set by the Repeater on each row
   * field. Server-backed row fields (relation pickers, remote Select search)
   * send it as the `repeater` and `repeatable` query parameters, so the
   * server reads the field from that row type's `fields()` on the form (or
   * Action, or pivot fields) the Repeater belongs to.
   */
  repeaterRow?: RepeaterRowScope
  /**
   * The Tool URI key when the form is scoped to a Tool implementing
   * `ProvidesFields`. Routes server-backed field behaviours (remote Select
   * search) at `/api/tools/{toolKey}/...`. When both `toolKey` and
   * `resourceKey` are set, `toolKey` owns the option search (the Tool is
   * who declared the field) and `resourceKey` keeps scoping slug, relatable
   * and dependsOn calls.
   */
  toolKey?: string
}
