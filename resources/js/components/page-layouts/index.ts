import type { ComponentType } from "react"
import type { CreatePageProps, IndexPageProps, DetailPageProps } from "@/lib/pageRegistry"

/**
 * Built-in Page Layout Registry
 *
 * Maps component keys (declared in PHP Resource overrides) to built-in
 * React components. This is the bridge between backend Resource::overrideCreate()
 * and the frontend page rendering.
 *
 * When a Resource declares:
 *   public function overrideCreate(): ?array {
 *       return ["component" => "sidebar-create"];
 *   }
 *
 * The schema API sends { overrides: { create: { component: "sidebar-create" } } }
 * and the frontend resolves "sidebar-create" from this registry.
 */

import { SidebarCreate } from "./SidebarCreate"

// Built-in create page layouts
const createLayouts = new Map<string, ComponentType<CreatePageProps>>([
  ["sidebar-create", SidebarCreate],
])

// Built-in index page layouts (extensible)
const indexLayouts = new Map<string, ComponentType<IndexPageProps>>()

// Built-in detail page layouts (extensible)
const detailLayouts = new Map<string, ComponentType<DetailPageProps>>()

export function resolveCreateLayout(
  key: string,
): ComponentType<CreatePageProps> | null {
  return createLayouts.get(key) ?? null
}

export function resolveIndexLayout(
  key: string,
): ComponentType<IndexPageProps> | null {
  return indexLayouts.get(key) ?? null
}

export function resolveDetailLayout(
  key: string,
): ComponentType<DetailPageProps> | null {
  return detailLayouts.get(key) ?? null
}
