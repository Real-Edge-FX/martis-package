import type { ComponentType } from 'react'

/**
 * Layout Registry — Bloco 9 (Override System v1)
 *
 * Allows resources to declare a custom page layout.
 * The default layout wraps every resource page (index, detail, create, update).
 * Override per-resource to provide a completely custom shell.
 *
 * Usage:
 *   import { layoutRegistry } from '@/lib/layoutRegistry'
 *   import { UserResourceLayout } from './UserResourceLayout'
 *
 *   // Override layout for the "users" resource
 *   layoutRegistry.register('users', UserResourceLayout)
 *
 * Layout components receive children as props:
 *   function UserResourceLayout({ children }: { children: React.ReactNode }) { ... }
 */

export interface LayoutProps {
  children: React.ReactNode
}

class LayoutRegistry {
  private readonly layouts = new Map<string, ComponentType<LayoutProps>>()

  /**
   * Register a custom layout for a resource.
   *
   * @param resourceKey  The resource URI key (e.g. "users", "posts")
   * @param layout       The React layout component
   */
  register(resourceKey: string, layout: ComponentType<LayoutProps>): void {
    this.layouts.set(resourceKey, layout)
  }

  /**
   * Resolve the layout for a resource, falling back to the default layout.
   *
   * @param resourceKey   The resource URI key
   * @param defaultLayout The fallback layout component
   */
  resolve(
    resourceKey: string | undefined,
    defaultLayout: ComponentType<LayoutProps>,
  ): ComponentType<LayoutProps> {
    if (!resourceKey) return defaultLayout
    return this.layouts.get(resourceKey) ?? defaultLayout
  }

  /** Check whether a custom layout is registered for a resource. */
  has(resourceKey: string): boolean {
    return this.layouts.has(resourceKey)
  }

  /** List all resource keys with custom layouts. */
  keys(): string[] {
    return Array.from(this.layouts.keys())
  }
}

export const layoutRegistry = new LayoutRegistry()
