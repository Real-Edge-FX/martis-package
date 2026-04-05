import type { ComponentType } from 'react'
import type { ResourceSchema, FieldDefinition } from '@/types'

/**
 * Page Registry — Resource Page Override System
 *
 * Allows overriding the entire Create, Index, or Detail page for a resource.
 * Falls back to the built-in default pages if no override is registered.
 *
 * Usage:
 *   import { pageRegistry } from '@/lib/pageRegistry'
 *   import { CustomProjectCreate } from './overrides/ProjectCreate'
 *
 *   pageRegistry.registerCreate('projects', CustomProjectCreate)
 *   pageRegistry.registerIndex('projects', CustomProjectIndex)
 *   pageRegistry.registerDetail('projects', CustomProjectDetail)
 *
 * Override components receive standard props with schema, resource key, etc.
 */

export interface CreatePageProps {
  resourceKey: string
  schema: ResourceSchema
  fields: FieldDefinition[]
}

export interface IndexPageProps {
  resourceKey: string
  schema: ResourceSchema
}

export interface DetailPageProps {
  resourceKey: string
  schema: ResourceSchema
  recordId: string
}

export interface EditPageProps {
  resourceKey: string
  schema: ResourceSchema
  fields: FieldDefinition[]
  recordId: string
}

type PageType = 'create' | 'index' | 'detail' | 'edit'

class PageRegistry {
  private readonly pages = new Map<string, ComponentType<never>>()

  private key(resourceKey: string, pageType: PageType): string {
    return `page:${pageType}:${resourceKey}`
  }

  registerCreate(resourceKey: string, component: ComponentType<CreatePageProps>): void {
    this.pages.set(this.key(resourceKey, 'create'), component as ComponentType<never>)
  }

  registerIndex(resourceKey: string, component: ComponentType<IndexPageProps>): void {
    this.pages.set(this.key(resourceKey, 'index'), component as ComponentType<never>)
  }

  registerDetail(resourceKey: string, component: ComponentType<DetailPageProps>): void {
    this.pages.set(this.key(resourceKey, 'detail'), component as ComponentType<never>)
  }

  resolveCreate(resourceKey: string | undefined): ComponentType<CreatePageProps> | null {
    if (!resourceKey) return null
    return (this.pages.get(this.key(resourceKey, 'create')) as ComponentType<CreatePageProps>) ?? null
  }

  resolveIndex(resourceKey: string | undefined): ComponentType<IndexPageProps> | null {
    if (!resourceKey) return null
    return (this.pages.get(this.key(resourceKey, 'index')) as ComponentType<IndexPageProps>) ?? null
  }

  resolveDetail(resourceKey: string | undefined): ComponentType<DetailPageProps> | null {
    if (!resourceKey) return null
    return (this.pages.get(this.key(resourceKey, 'detail')) as ComponentType<DetailPageProps>) ?? null
  }

  registerEdit(resourceKey: string, component: ComponentType<EditPageProps>): void {
    this.pages.set(this.key(resourceKey, 'edit'), component as ComponentType<never>)
  }

  resolveEdit(resourceKey: string | undefined): ComponentType<EditPageProps> | null {
    if (!resourceKey) return null
    return (this.pages.get(this.key(resourceKey, 'edit')) as ComponentType<EditPageProps>) ?? null
  }

  has(resourceKey: string, pageType: PageType): boolean {
    return this.pages.has(this.key(resourceKey, pageType))
  }

  keys(): string[] {
    return Array.from(this.pages.keys())
  }
}

export const pageRegistry = new PageRegistry()
