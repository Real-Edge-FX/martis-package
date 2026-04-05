import { componentRegistry } from "@/lib/componentRegistry"
import { StatusPills } from "./StatusPills"
import { PriorityBadge } from "./PriorityBadge"

/**
 * Register built-in field override components.
 *
 * These map to the component keys set via Field::component() in PHP.
 * For example: Select::make("status")->component("status-pills")
 * resolves to the StatusPills React component.
 */
export function registerBuiltinFieldOverrides(): void {
  componentRegistry.register("status-pills", StatusPills)
  componentRegistry.register("priority-badge", PriorityBadge)
}
