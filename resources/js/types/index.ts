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

export type FieldType =
  | 'text'
  | 'textarea'
  | 'number'
  | 'boolean'
  | 'select'
  | 'date'
  | 'belongs_to'

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
}

export interface ResourceEmbedded {
  uriKey: string
  label: string
  singularLabel: string
  softDeletes: boolean
  group: string | null
}

export interface ResourceSchema extends ResourceEmbedded {
  fields: FieldDefinition[]
}

export interface ResourceRecord {
  id: number | string
  [key: string]: unknown
  _resource: ResourceEmbedded
}
