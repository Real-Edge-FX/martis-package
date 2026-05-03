import type { ComponentType } from 'react'
import type { FieldDisplayProps, FieldInputProps } from '@/components/fields/types'

/**
 * Component Registry — Bloco 9 (Override System v1)
 *
 * Three-tier resolution for field components:
 *
 * 1. Explicit component key (from field schema `component` property)
 *    → `registry.register('status-badge', StatusBadge)`
 *    → Field: Text::make('status')->component('status-badge')
 *
 * 2. Per-resource override (resource + field name)
 *    → `componentRegistry.registerResourceFieldDisplay('posts', 'status', StatusBadge)`
 *
 * 3. Global type override (all fields of a type)
 *    → `componentRegistry.registerFieldDisplay('text', MyTextField)`
 *
 * 4. Built-in default (registered via registerDefaultFields())
 *
 * Resolution order: explicit key → resource-field → type → built-in default
 *
 * Usage:
 *   import { componentRegistry } from '@/lib/componentRegistry'
 *
 *   // Register custom display for all "text" fields globally
 *   componentRegistry.registerFieldDisplay('text', MyCustomTextField)
 *
 *   // Register custom display for "status" field on "posts" resource only
 *   componentRegistry.registerResourceFieldDisplay('posts', 'status', StatusBadgeDisplay)
 *
 *   // Register by explicit key (matching field.component in PHP)
 *   componentRegistry.register('status-badge', StatusBadgeDisplay)
 */
class ComponentRegistry {
  /** Keyed by arbitrary string (explicit component keys, type keys, resource-field keys). */
  private readonly components = new Map<string, ComponentType<never>>()

  // -------------------------------------------------------------------------
  // Low-level registration
  // -------------------------------------------------------------------------

  /**
   * Register a component under an arbitrary key.
   * Also used for explicit `field.component` keys from PHP.
   */
  register<P = never>(key: string, component: ComponentType<P>): void {
    this.components.set(key, component as ComponentType<never>)
  }

  // -------------------------------------------------------------------------
  // Typed registration helpers
  // -------------------------------------------------------------------------

  /** Override the display component for all fields of a given type (e.g. "text"). */
  registerFieldDisplay(type: string, component: ComponentType<FieldDisplayProps>): void {
    this.register(`field:display:${type}`, component)
  }

  /** Override the input component for all fields of a given type (e.g. "text"). */
  registerFieldInput(type: string, component: ComponentType<FieldInputProps>): void {
    this.register(`field:input:${type}`, component)
  }

  /**
   * Override the display component for a specific field on a specific resource.
   *
   * @param resourceKey  The resource URI key (e.g. "posts", "users")
   * @param fieldName    The field attribute name (e.g. "status", "name")
   * @param component    The React component to use for display
   */
  registerResourceFieldDisplay(
    resourceKey: string,
    fieldName: string,
    component: ComponentType<FieldDisplayProps>,
  ): void {
    this.register(`field:display:${resourceKey}:${fieldName}`, component)
  }

  /**
   * Override the input component for a specific field on a specific resource.
   *
   * @param resourceKey  The resource URI key (e.g. "posts", "users")
   * @param fieldName    The field attribute name (e.g. "status", "name")
   * @param component    The React component to use for the form input
   */
  registerResourceFieldInput(
    resourceKey: string,
    fieldName: string,
    component: ComponentType<FieldInputProps>,
  ): void {
    this.register(`field:input:${resourceKey}:${fieldName}`, component)
  }

  // -------------------------------------------------------------------------
  // Resolution
  // -------------------------------------------------------------------------

  /** Resolve a display component with full 4-tier fallback chain. */
  resolveDisplay(
    type: string,
    fieldName: string,
    resourceKey: string | undefined,
    explicitKey: string | null | undefined,
    fallback: ComponentType<FieldDisplayProps>,
  ): ComponentType<FieldDisplayProps> {
    // Tier 1: explicit component key from field schema
    if (explicitKey) {
      const explicit = this.components.get(explicitKey) as ComponentType<FieldDisplayProps> | undefined
      if (explicit) return explicit
    }

    // Tier 2: per-resource override
    if (resourceKey) {
      const resourceSpecific = this.components.get(
        `field:display:${resourceKey}:${fieldName}`,
      ) as ComponentType<FieldDisplayProps> | undefined
      if (resourceSpecific) return resourceSpecific
    }

    // Tier 3: global type override
    const typeLevel = this.components.get(`field:display:${type}`) as ComponentType<FieldDisplayProps> | undefined
    if (typeLevel) return typeLevel

    // Tier 4: built-in default
    return fallback
  }

  /** Resolve an input component with full 4-tier fallback chain. */
  resolveInput(
    type: string,
    fieldName: string,
    resourceKey: string | undefined,
    explicitKey: string | null | undefined,
    fallback: ComponentType<FieldInputProps>,
  ): ComponentType<FieldInputProps> {
    // Tier 1: explicit component key from field schema
    if (explicitKey) {
      const explicit = this.components.get(explicitKey) as ComponentType<FieldInputProps> | undefined
      if (explicit) return explicit
    }

    // Tier 2: per-resource override
    if (resourceKey) {
      const resourceSpecific = this.components.get(
        `field:input:${resourceKey}:${fieldName}`,
      ) as ComponentType<FieldInputProps> | undefined
      if (resourceSpecific) return resourceSpecific
    }

    // Tier 3: global type override
    const typeLevel = this.components.get(`field:input:${type}`) as ComponentType<FieldInputProps> | undefined
    if (typeLevel) return typeLevel

    // Tier 4: built-in default
    return fallback
  }

  /** Resolve a component by key, returning undefined if not found. */
  resolve(key: string): ComponentType<never> | undefined {
    return this.components.get(key)
  }

  /** Check whether a key is registered. */
  has(key: string): boolean {
    return this.components.has(key)
  }

  /**
   * Remove a single registration. Returns `true` when a registration
   * existed for the key, `false` otherwise. Mostly useful in tests
   * that mount a custom component for one assertion and want a clean
   * slate afterwards.
   */
  unregister(key: string): boolean {
    return this.components.delete(key)
  }

  /**
   * Drop every registration. Same intended use as `unregister()` —
   * keeping tests isolated. Production code should never call this;
   * the registry is built up at boot and is expected to be stable for
   * the lifetime of the SPA.
   */
  clear(): void {
    this.components.clear()
  }

  /** List all registered keys (useful for debugging). */
  keys(): string[] {
    return Array.from(this.components.keys())
  }
}

export const componentRegistry = new ComponentRegistry()
