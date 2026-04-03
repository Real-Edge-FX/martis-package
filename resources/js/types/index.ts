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

