import { pageRegistry } from '@/lib/pageRegistry'
import { componentRegistry } from '@/lib/componentRegistry'
import { DemoProjectCreate } from '@/components/overrides/DemoProjectCreate'
import { DemoFooter } from '@/components/overrides/DemoFooter'

/**
 * Register all demo overrides.
 *
 * Called from app.tsx after registerDefaultFields().
 * Demonstrates two override capabilities:
 *
 * 1. Page Override (pageRegistry): Replace the entire Create page for "projects"
 *    resource with a custom sidebar-based form.
 *
 * 2. Component Override (componentRegistry): Replace the default Footer with
 *    a custom component across the entire admin panel.
 */
export function registerDemoOverrides(): void {
  // Page override: custom Create page for the "projects" resource
  pageRegistry.registerCreate('projects', DemoProjectCreate)

  // Component override: custom Footer for the entire admin panel
  componentRegistry.register('layout:footer', DemoFooter)
}
