import { useState, useRef, useEffect, useCallback, useMemo } from "react"
import { createPortal } from "react-dom"
import { useTranslation } from "react-i18next"
import { LightningIcon, CaretDownIcon, CaretRightIcon, WarningIcon } from "@phosphor-icons/react"
import { ResourceIcon } from "@/components/ResourceIcon"
import type { ActionMeta } from "./ActionModal"

interface ActionDropdownProps {
  actions: ActionMeta[]
  onSelect: (action: ActionMeta) => void
  disabled?: boolean
  label?: string
  disabledActions?: Set<string>
}

interface ActionGroup {
  label: string
  children: Array<ActionMeta | ActionGroup>
}

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
  const topGroups = new Map<string, ActionGroup>()

  for (const [key, items] of groupMap.entries()) {
    const parts = key.split(".")
    if (parts.length === 1) {
      if (!topGroups.has(parts[0])) {
        topGroups.set(parts[0], { label: parts[0], children: [] })
      }
      topGroups.get(parts[0])!.children.push(...items)
    } else {
      const topKey = parts[0]
      const subLabel = parts.slice(1).join(".")
      if (!topGroups.has(topKey)) {
        topGroups.set(topKey, { label: topKey, children: [] })
      }
      const top = topGroups.get(topKey)!
      let sub = top.children.find(
        (c): c is ActionGroup => "label" in c && c.label === subLabel,
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

function ActionIcon({ action, size = 16 }: { action: ActionMeta; size?: number }) {
  if (!action.showIcon) return null
  const color = action.iconColor ?? undefined
  if (action.icon) {
    return <ResourceIcon iconName={action.icon} size={size} color={color} />
  }
  if (action.destructive) {
    return <WarningIcon size={size} weight="fill" color={color} />
  }
  return <LightningIcon size={size} color={color} />
}

function computeSubMenuPos(parentRect: DOMRect): { top: number; left: number } {
  const menuWidth = 220
  const vw = window.innerWidth
  const vh = window.innerHeight

  let left = parentRect.right + 2
  let top = parentRect.top

  if (left + menuWidth > vw) {
    left = parentRect.left - menuWidth - 2
  }
  if (left < 0) {
    left = Math.max(4, vw - menuWidth - 4)
    top = parentRect.bottom + 4
  }
  if (top + 200 > vh) {
    top = Math.max(4, vh - 200)
  }

  return { top, left }
}

function SubMenu({
  group,
  onSelect,
  parentRect,
  disabledActions,
  onMouseEnterMenu,
  onMouseLeaveMenu,
}: {
  group: ActionGroup
  onSelect: (action: ActionMeta) => void
  parentRect: DOMRect | null
  disabledActions?: Set<string>
  onMouseEnterMenu?: () => void
  onMouseLeaveMenu?: () => void
}) {
  const menuRef = useRef<HTMLDivElement>(null)
  const [openChild, setOpenChild] = useState<string | null>(null)
  const [childRects, setChildRects] = useState<Map<string, DOMRect>>(new Map())
  const closeTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  function clearTimer() {
    if (closeTimer.current) { clearTimeout(closeTimer.current); closeTimer.current = null }
  }
  function startCloseChildTimer(key: string) {
    closeTimer.current = setTimeout(() => { setOpenChild(prev => prev === key ? null : prev) }, 180)
  }

  if (!parentRect) return null

  const pos = computeSubMenuPos(parentRect)

  return createPortal(
    <div
      ref={menuRef}
      data-action-submenu="true"
      className="rounded-lg border shadow-lg"
      style={{
        position: "fixed",
        top: pos.top,
        left: pos.left,
        minWidth: 200,
        maxWidth: "calc(100vw - 16px)",
        zIndex: 9981,
        backgroundColor: "var(--martis-card)",
        borderColor: "var(--martis-border)",
      }}
      onMouseEnter={() => { clearTimer(); onMouseEnterMenu?.() }}
      onMouseLeave={() => onMouseLeaveMenu?.()}
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
                  clearTimer()
                  setOpenChild(key)
                  const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
                  setChildRects((prev) => new Map(prev).set(key, rect))
                }}
                onMouseLeave={() => startCloseChildTimer(key)}
                onClick={(e) => {
                  e.stopPropagation()
                  const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
                  setChildRects((prev) => new Map(prev).set(key, rect))
                  setOpenChild((prev) => (prev === key ? null : key))
                }}
              >
                <div
                  className="flex w-full items-center justify-between gap-2 px-4 py-2 text-left text-sm transition-colors cursor-pointer"
                  style={{ color: "var(--martis-text)" }}
                  onMouseEnter={(e) =>
                    ((e.currentTarget as HTMLElement).style.backgroundColor = "var(--martis-hover)")
                  }
                  onMouseLeave={(e) =>
                    ((e.currentTarget as HTMLElement).style.backgroundColor = "transparent")
                  }
                >
                  <span className="font-medium">{child.label}</span>
                  <CaretRightIcon size={12} />
                </div>
                {openChild === key && (
                  <SubMenu
                    group={child}
                    onSelect={onSelect}
                    parentRect={childRects.get(key) ?? null}
                    disabledActions={disabledActions}
                    onMouseEnterMenu={clearTimer}
                    onMouseLeaveMenu={() => startCloseChildTimer(key)}
                  />
                )}
              </div>
            )
          }
          const isDisabled = disabledActions?.has(child.uriKey) ?? false
          return (
            <button
              key={child.uriKey}
              type="button"
              disabled={isDisabled}
              onClick={(e) => {
                e.stopPropagation()
                if (!isDisabled) onSelect(child)
              }}
              className="flex w-full items-center gap-2 px-4 py-2 text-left text-sm transition-colors disabled:opacity-40 disabled:cursor-default"
              style={{
                color: isDisabled
                  ? "var(--martis-text-muted)"
                  : child.destructive
                    ? "var(--martis-danger)"
                    : "var(--martis-text)",
              }}
              onMouseEnter={(e) => {
                if (!isDisabled)
                  (e.currentTarget as HTMLElement).style.backgroundColor = "var(--martis-hover)"
              }}
              onMouseLeave={(e) =>
                ((e.currentTarget as HTMLElement).style.backgroundColor = "transparent")
              }
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

export function ActionDropdown({ actions, onSelect, disabled, label, disabledActions }: ActionDropdownProps) {
  const { t } = useTranslation("actions")
  const [open, setOpen] = useState(false)
  const btnRef = useRef<HTMLButtonElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const [menuPos, setMenuPos] = useState<{ top: number; left: number; width: number }>({
    top: 0,
    left: 0,
    width: 0,
  })
  const [openGroup, setOpenGroup] = useState<string | null>(null)
  const [groupRects, setGroupRects] = useState<Map<string, DOMRect>>(new Map())
  const closeTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  function clearTimer() {
    if (closeTimer.current) { clearTimeout(closeTimer.current); closeTimer.current = null }
  }
  function startCloseGroupTimer(key: string) {
    closeTimer.current = setTimeout(() => { setOpenGroup(prev => prev === key ? null : prev) }, 180)
  }

  const tree = useMemo(() => buildGroupTree(actions), [actions])

  const updatePosition = useCallback(() => {
    if (btnRef.current) {
      const rect = btnRef.current.getBoundingClientRect()
      const menuWidth = Math.max(rect.width, 220)
      const vw = window.innerWidth

      setMenuPos({
        top: rect.bottom + 4,
        left: Math.min(rect.left, vw - menuWidth - 8),
        width: menuWidth,
      })
    }
  }, [])

  useEffect(() => {
    if (!open) return
    updatePosition()
    function handleClick(e: MouseEvent) {
      const target = e.target as HTMLElement
      if (menuRef.current && menuRef.current.contains(target)) return
      if (btnRef.current && btnRef.current.contains(target)) return
      if (target.closest("[data-action-submenu]")) return
      setOpen(false)
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
        <LightningIcon size={14} />
        {label ?? t("actions")}
        <CaretDownIcon size={12} />
      </button>

      {open &&
        createPortal(
          <div
            ref={menuRef}
            className="rounded-lg border shadow-lg"
            style={{
              position: "fixed",
              top: menuPos.top,
              left: menuPos.left,
              minWidth: menuPos.width,
              maxWidth: "calc(100vw - 16px)",
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
                        clearTimer()
                        setOpenGroup(key)
                        const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
                        setGroupRects((prev) => new Map(prev).set(key, rect))
                      }}
                      onMouseLeave={() => startCloseGroupTimer(key)}
                      onClick={(e) => {
                        e.stopPropagation()
                        const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
                        setGroupRects((prev) => new Map(prev).set(key, rect))
                        setOpenGroup((prev) => (prev === key ? null : key))
                      }}
                    >
                      <div
                        className="flex w-full items-center justify-between gap-2 px-4 py-2 text-left text-sm transition-colors cursor-pointer"
                        style={{ color: "var(--martis-text)" }}
                        onMouseEnter={(e) =>
                          ((e.currentTarget as HTMLElement).style.backgroundColor =
                            "var(--martis-hover)")
                        }
                        onMouseLeave={(e) =>
                          ((e.currentTarget as HTMLElement).style.backgroundColor = "transparent")
                        }
                      >
                        <span className="font-medium">{item.label}</span>
                        <CaretRightIcon size={12} />
                      </div>
                      {openGroup === key && (
                        <SubMenu
                          group={item}
                          onSelect={handleSelect}
                          parentRect={groupRects.get(key) ?? null}
                          disabledActions={disabledActions}
                          onMouseEnterMenu={clearTimer}
                          onMouseLeaveMenu={() => startCloseGroupTimer(key)}
                        />
                      )}
                    </div>
                  )
                }

                const isDisabled = disabledActions?.has(item.uriKey) ?? false
                return (
                  <button
                    key={item.uriKey}
                    type="button"
                    disabled={isDisabled}
                    onClick={(e) => {
                      e.stopPropagation()
                      if (!isDisabled) handleSelect(item)
                    }}
                    className="flex w-full items-center gap-2 px-4 py-2 text-left text-sm transition-colors disabled:opacity-40 disabled:cursor-default"
                    style={{
                      color: isDisabled
                        ? "var(--martis-text-muted)"
                        : item.destructive
                          ? "var(--martis-danger)"
                          : "var(--martis-text)",
                    }}
                    onMouseEnter={(e) => {
                      if (!isDisabled)
                        (e.currentTarget as HTMLElement).style.backgroundColor = "var(--martis-hover)"
                    }}
                    onMouseLeave={(e) =>
                      ((e.currentTarget as HTMLElement).style.backgroundColor = "transparent")
                    }
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
