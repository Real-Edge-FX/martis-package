export interface User {
  id: number
  name: string
  email: string
  [key: string]: unknown
}

export interface ResourceMeta {
  uriKey: string
  label: string
  singularLabel: string
  icon: string | null
  group: string | null
  titleAttribute?: string
}

export interface NavigationGroup {
  label: string | null
  resources: ResourceMeta[]
}

export interface PaginatedResponse<T> {
  data: T[]
  meta: {
    current_page: number
    from: number | null
    last_page: number
    per_page: number
    to: number | null
    total: number
  }
  links: {
    first: string | null
    last: string | null
    prev: string | null
    next: string | null
  }
}

export interface Toast {
  id: string
  type: 'success' | 'error' | 'warning' | 'info'
  message: string
}

// -------------------------------------------------------------------------
// Field & Resource schema types (Bloco 8)
// -------------------------------------------------------------------------

export type FieldType =  | 'text'  | 'textarea'  | 'number'  | 'boolean'  | 'select'  | 'date'  | 'datetime'  | 'belongs_to'  | 'id'  | 'email'  | 'password'  | 'heading'  | 'hidden'  | 'file'  | 'image'  | 'key_value'  | 'badge'  | 'status'  | 'multi_select'  | 'tag'  | 'url'  | 'code'  | 'color'  | 'markdown'  | 'trix'  | 'country'  | 'currency'  | 'sparkline'  | 'gravatar'
  | 'has_many'
  | 'belongs_to_many'
  | 'morph_to'

export interface SelectOption {
  value: string | number
  label: string
}

export interface FieldDefinition {
  attribute: string
  label: string
  type: FieldType
  nullable: boolean
  readonly: boolean
  required: boolean
  sortable: boolean
  searchable: boolean
  showOnIndex: boolean
  showOnDetail: boolean
  showOnForms: boolean
  rules: string[]
  options?: SelectOption[]
  relatedResource?: string
  relatedLabel?: string
  /** Explicit component override key (set via PHP field->component('key')). */
  component?: string | null
  /** Per-context component overrides (set via PHP field->overrideCreate/Update/Index/Detail). */
  overrides?: {
    create?: { component: string; params: Record<string, unknown> } | null
    update?: { component: string; params: Record<string, unknown> } | null
    index?: { component: string; params: Record<string, unknown> } | null
    detail?: { component: string; params: Record<string, unknown> } | null
  } | null
  placeholder?: string
  /** Content text for heading fields. */
  content?: string | null
  /** Whether the field accepts multiple values (File/Image fields). */
  multiple?: boolean
  /** Column span in a 12-column grid (1-12, default 12). */
  colSpan?: number
  /** Column span from md breakpoint (>= 768px). */
  colSpanMd?: number | null
  /** Column span from lg breakpoint (>= 1024px). */
  colSpanLg?: number | null
  /** Allow access to arbitrary meta properties set via withMeta(). */
  [key: string]: unknown
}

export interface ResourceEmbedded {
  uriKey: string
  label: string
  singularLabel: string
  softDeletes: boolean
  group: string | null
  titleAttribute?: string
  subtitle?: string | null
  icon?: string | null
}

export interface ResourceMessages {
  created: string
  updated: string
  deleted: string
  restored: string
  deleteConfirm: string
  archiveConfirm: string
}


export interface OverrideDefinition {
  component: string
  params: Record<string, unknown>
  redirectAfter?: string | null
}

export interface AuthorizationMetadata {
  authorizedToView: boolean
  authorizedToUpdate: boolean
  authorizedToDelete: boolean
  authorizedToReplicate: boolean
  authorizedToRunAction: boolean
  authorizedToRunDestructiveAction: boolean
  authorizedToRestore?: boolean
  authorizedToForceDelete?: boolean
}

export interface CollectionAuthorizationMetadata {
  authorizedToViewAny: boolean
  authorizedToCreate: boolean
}

export interface ResourceSchema extends ResourceEmbedded {
  fields: FieldDefinition[]
  fieldsForIndex?: FieldDefinition[]
  fieldsForDetail?: FieldDefinition[]
  fieldsForCreate?: FieldDefinition[]
  fieldsForUpdate?: FieldDefinition[]
  fieldsForInlineCreate?: FieldDefinition[]
  fieldsForPreview?: FieldDefinition[]
  messages?: ResourceMessages
  errorDisplay?: 'inline' | 'toast'
  actionsMenuLabel?: string | null
  bulkActionsMenuLabel?: string | null
  indexSearchable?: boolean
  perPageOptions?: number[]
  perPage?: number
  searchPlaceholder?: string | null
  tableStriped?: boolean
  tableShowGridlines?: boolean
  tableSize?: 'normal' | 'small' | 'large'
  tableRowHover?: boolean
  authorization?: CollectionAuthorizationMetadata
  overrides?: {
    create?: OverrideDefinition | null
    update?: OverrideDefinition | null
    detail?: OverrideDefinition | null
    index?: OverrideDefinition | null
  }
}

export interface ResourceRecord {
  id: number | string
  _title?: string
  _authorization?: AuthorizationMetadata
  [key: string]: unknown
  _resource: ResourceEmbedded
}

/**
 * Standard props passed to ALL override components (create/update/detail/index).
 *
 * Every override receives the same rich interface so the consumer component
 * can perform any CRUD or navigation action without knowing which page
 * context it lives in.
 */
export interface OverrideProps {
  /** The full resource schema. */
  schema: ResourceSchema
  /** The resource URI key (e.g. "posts"). */
  resource: string
  /** Custom parameters from PHP Override. */
  params: Record<string, unknown>
  /** The existing record (populated on detail/update contexts, null on create/index). */
  record?: ResourceRecord | null
  /** The record ID (populated on detail/update contexts, null on create/index). */
  recordId?: string | null

  // Navigation
  /** React Router navigate function for arbitrary navigation. */
  navigate: (to: string) => void
  /** Close the override / navigate back to the resource list. */
  onClose: () => void

  // CRUD events — all overrides receive all events for maximum flexibility.
  /** Called after a record is successfully created. */
  onCreated: (record: { id: string | number }) => void
  /** Called after a record is successfully updated. */
  onUpdated: (record: { id: string | number }) => void
  /** Called after a record is successfully deleted. */
  onDeleted: () => void

  // View navigation events
  /** Navigate to the edit page for a record. */
  onEdit: (id?: string | number) => void
  /** Navigate to the detail page for a record. */
  onView: (id: string | number) => void

  // Utilities
  /** Show a toast notification. */
  addToast: (type: "success" | "error" | "warning" | "info", message: string) => void
}
