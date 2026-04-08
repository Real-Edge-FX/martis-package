import { useState, useRef, useEffect, useCallback, useMemo } from "react"
import { createPortal } from "react-dom"
import { useTranslation } from "react-i18next"
import { Lightning, CaretDown, CaretRight, Warning } from "@phosphor-icons/react"
import { ResourceIcon } from "@/components/ResourceIcon"
import type { ActionMeta } from "./ActionModal"

interface ActionDropdownProps {
  actions: ActionMeta[]
  onSelect: (action: ActionMeta) => void
  disabled?: boolean
  label?: string
}

/** A flat action item or a group header that contains children. */
interface ActionGroup {
  label: string
  children: Array<ActionMeta | ActionGroup>
}

/** Build a nested tree from flat actions using dot-notation groups. */
function buildGroupTree(actions: ActionMeta[]): Array<ActionMeta | ActionGroup> {
  const ungrouped: ActionMeta[] = []
  const groupMap = new Map<string, ActionMeta[]>()

  for (const action of actions) {
    if (!action.group) {
      ungrouped.push(action)
    } else {
      const existing = groupMap.get(action.group) ?? []
      existing.push(action)
      groupMap.set(action.group, existing)
    }
  }

  const result: Array<ActionMeta | ActionGroup> = [...ungrouped]

  // Build nested groups from dot-notation keys
  const topGroups = new Map<string, ActionGroup>()

  for (const [key, items] of groupMap.entries()) {
    const parts = key.split(".")
    if (parts.length === 1) {
      // Single-level group
      if (!topGroups.has(parts[0])) {
        topGroups.set(parts[0], { label: parts[0], children: [] })
      }
      topGroups.get(parts[0])!.children.push(...items)
    } else {
      // Multi-level: first part is top group, rest is submenu label
      const topKey = parts[0]
      const subLabel = parts.slice(1).join(".")
      if (!topGroups.has(topKey)) {
        topGroups.set(topKey, { label: topKey, children: [] })
      }
      const top = topGroups.get(topKey)!
      // Find or create sub-group
      let sub = top.children.find(
        (c): c is ActionGroup => "label" in c && c.label === subLabel
      )
      if (!sub) {
        sub = { label: subLabel, children: [] }
        top.children.push(sub)
      }
      sub.children.push(...items)
    }
  }

  for (const group of topGroups.values()) {
    result.push(group)
  }

  return result
}

function isGroup(item: ActionMeta | ActionGroup): item is ActionGroup {
  return "label" in item && "children" in item && !("uriKey" in item)
}

function ActionIcon({ action, size = 14 }: { action: ActionMeta; size?: number }) {
  if (action.icon) {
    return <ResourceIcon iconName={action.icon} size={size} />
  }
  if (action.destructive) {
    return <Warning size={size} weight="fill" />
  }
  return <Lightning size={size} />
}

/** Submenu that opens on hover to the right of the parent item. */
function SubMenu({
  group,
  onSelect,
  parentRect,
}: {
  group: ActionGroup
  onSelect: (action: ActionMeta) => void
  parentRect: DOMRect | null
}) {
  const menuRef = useRef<HTMLDivElement>(null)
  const [openChild, setOpenChild] = useState<string | null>(null)
  const [childRects, setChildRects] = useState<Map<string, DOMRect>>(new Map())

  if (!parentRect) return null

  return createPortal(
    <div
      ref={menuRef}
      className="rounded-lg border shadow-lg"
      style={{
        position: "fixed",
        top: parentRect.top,
        left: parentRect.right + 2,
        minWidth: 200,
        zIndex: 9981,
        backgroundColor: "var(--martis-card)",
        borderColor: "var(--martis-border)",
      }}
    >
      <div className="py-1">
        {group.children.map((child, idx) => {
          if (isGroup(child)) {
            const key = `sub-${child.label}-${idx}`
            return (
              <div
                key={key}
                className="relative"
                onMouseEnter={(e) => {
                  setOpenChild(key)
                  const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
                  setChildRects((prev) => new Map(prev).set(key, rect))
                }}
                onMouseLeave={() => setOpenChild(null)}
              >
                <div
                  className="flex w-full items-center justify-between gap-2 px-4 py-2 text-left text-sm transition-colors cursor-default"
                  style={{ color: "var(--martis-text)" }}
                  onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "var(--martis-hover)")}
                  onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
                >
                  <span className="font-medium">{child.label}</span>
                  <CaretRight size={12} />
                </div>
                {openChild === key && (
                  <SubMenu
                    group={child}
                    onSelect={onSelect}
                    parentRect={childRects.get(key) ?? null}
                  />
                )}
              </div>
            )
          }
          return (
            <button
              key={child.uriKey}
              type="button"
              onClick={() => onSelect(child)}
              className="flex w-full items-center gap-2 px-4 py-2 text-left text-sm transition-colors"
              style={{ color: child.destructive ? "#dc2626" : "var(--martis-text)" }}
              onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "var(--martis-hover)")}
              onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
            >
              <ActionIcon action={child} />
              {child.name}
            </button>
          )
        })}
      </div>
    </div>,
    document.body,
  )
}

export function ActionDropdown({ actions, onSelect, disabled, label }: ActionDropdownProps) {
  const { t } = useTranslation("actions")
  const [open, setOpen] = useState(false)
  const btnRef = useRef<HTMLButtonElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const [menuPos, setMenuPos] = useState<{ top: number; left: number; width: number }>({ top: 0, left: 0, width: 0 })
  const [openGroup, setOpenGroup] = useState<string | null>(null)
  const [groupRects, setGroupRects] = useState<Map<string, DOMRect>>(new Map())

  const tree = useMemo(() => buildGroupTree(actions), [actions])

  const updatePosition = useCallback(() => {
    if (btnRef.current) {
      const rect = btnRef.current.getBoundingClientRect()
      setMenuPos({
        top: rect.bottom + 4,
        left: rect.left,
        width: Math.max(rect.width, 220),
      })
    }
  }, [])

  useEffect(() => {
    if (!open) return
    updatePosition()
    function handleClick(e: MouseEvent) {
      if (
        menuRef.current && !menuRef.current.contains(e.target as Node) &&
        btnRef.current && !btnRef.current.contains(e.target as Node)
      ) {
        // Also skip if click is inside a portal submenu
        const target = e.target as HTMLElement
        if (target.closest("[data-action-submenu]")) return
        setOpen(false)
      }
    }
    function handleKey(e: KeyboardEvent) {
      if (e.key === "Escape") setOpen(false)
    }
    document.addEventListener("mousedown", handleClick)
    document.addEventListener("keydown", handleKey)
    return () => {
      document.removeEventListener("mousedown", handleClick)
      document.removeEventListener("keydown", handleKey)
    }
  }, [open, updatePosition])

  if (actions.length === 0) return null

  function handleSelect(action: ActionMeta) {
    setOpen(false)
    setOpenGroup(null)
    onSelect(action)
  }

  return (
    <>
      <button
        ref={btnRef}
        type="button"
        onClick={() => setOpen(!open)}
        disabled={disabled}
        className="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors hover:opacity-90 disabled:opacity-50"
        style={{
          backgroundColor: "var(--martis-surface)",
          borderColor: "var(--martis-border)",
          color: "var(--martis-text)",
        }}
      >
        <Lightning size={14} />
        {label ?? t("actions")}
        <CaretDown size={12} />
      </button>

      {open && createPortal(
        <div
          ref={menuRef}
          className="rounded-lg border shadow-lg"
          style={{
            position: "fixed",
            top: menuPos.top,
            left: menuPos.left,
            minWidth: menuPos.width,
            zIndex: 9980,
            backgroundColor: "var(--martis-card)",
            borderColor: "var(--martis-border)",
          }}
        >
          <div className="py-1">
            {tree.map((item, idx) => {
              if (isGroup(item)) {
                const key = `grp-${item.label}-${idx}`
                return (
                  <div
                    key={key}
                    data-action-submenu="true"
                    className="relative"
                    onMouseEnter={(e) => {
                      setOpenGroup(key)
                      const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
                      setGroupRects((prev) => new Map(prev).set(key, rect))
                    }}
                    onMouseLeave={() => setOpenGroup(null)}
                  >
                    <div
                      className="flex w-full items-center justify-between gap-2 px-4 py-2 text-left text-sm transition-colors cursor-default"
                      style={{ color: "var(--martis-text)" }}
                      onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "var(--martis-hover)")}
                      onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
                    >
                      <span className="font-medium">{item.label}</span>
                      <CaretRight size={12} />
                    </div>
                    {openGroup === key && (
                      <SubMenu
                        group={item}
                        onSelect={handleSelect}
                        parentRect={groupRects.get(key) ?? null}
                      />
                    )}
                  </div>
                )
              }

              // Separator before first group if there are ungrouped items before it
              return (
                <button
                  key={item.uriKey}
                  type="button"
                  onClick={() => handleSelect(item)}
                  className="flex w-full items-center gap-2 px-4 py-2 text-left text-sm transition-colors"
                  style={{ color: item.destructive ? "#dc2626" : "var(--martis-text)" }}
                  onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "var(--martis-hover)")}
                  onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
                >
                  <ActionIcon action={item} />
                  {item.name}
                </button>
              )
            })}
          </div>
        </div>,
        document.body,
      )}
    </>
  )
}
