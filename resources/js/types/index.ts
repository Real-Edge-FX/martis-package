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

export type FieldType =  | 'text'  | 'textarea'  | 'number'  | 'boolean'  | 'select'  | 'date'  | 'datetime'  | 'belongs_to'  | 'id'  | 'email'  | 'password'  | 'heading'  | 'hidden'  | 'file'  | 'image'

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
  placeholder?: string
  /** Content text for heading fields. */
  content?: string | null
  /** Whether the field accepts multiple values (File/Image fields). */
  multiple?: boolean
}

export interface ResourceEmbedded {
  uriKey: string
  label: string
  singularLabel: string
  softDeletes: boolean
  group: string | null
  titleAttribute?: string
}

export interface ResourceMessages {
  created: string
  updated: string
  deleted: string
  restored: string
  deleteConfirm: string
  archiveConfirm: string
}

export interface ResourceSchema extends ResourceEmbedded {
  fields: FieldDefinition[]
  messages?: ResourceMessages
  errorDisplay?: 'inline' | 'toast'
  indexSearchable?: boolean
  perPageOptions?: number[]
  perPage?: number
  searchPlaceholder?: string | null
  tableStriped?: boolean
  tableShowGridlines?: boolean
  tableSize?: 'normal' | 'small' | 'large'
  tableRowHover?: boolean
}

export interface ResourceRecord {
  id: number | string
  _title?: string
  [key: string]: unknown
  _resource: ResourceEmbedded
}
