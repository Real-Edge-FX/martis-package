import type { ComponentType } from 'react'

/**
 * Component Registry.
 *
 * Centralises every rendering component the package ships. Consumers
 * swap any component via `registry.register()` without forking.
 *
 * Usage:
 *   import { registry } from '@/lib/registry'
 *   registry.register('field:text', MyCustomTextField)
 */
class ComponentRegistry {
  private components = new Map<string, ComponentType<never>>()

  register<P = never>(name: string, component: ComponentType<P>): void {
    this.components.set(name, component as ComponentType<never>)
  }

  get<P = never>(name: string): ComponentType<P> | undefined {
    return this.components.get(name) as ComponentType<P> | undefined
  }

  resolve<P = never>(name: string, fallback: ComponentType<P>): ComponentType<P> {
    return (this.components.get(name) as ComponentType<P> | undefined) ?? fallback
  }

  has(name: string): boolean {
    return this.components.has(name)
  }

  /**
   * List all registered component keys (useful for debugging/introspection).
   */
  keys(): string[] {
    return Array.from(this.components.keys())
  }
}

export const registry = new ComponentRegistry()
