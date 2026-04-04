import { useState, useRef, useEffect, useCallback } from "react"
import { useNavigate } from "react-router-dom"
import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api"
import type { NavigationGroup, PaginatedResponse } from "@/types"
import { useTranslation } from "react-i18next"
import { MagnifyingGlass, Database, CaretRight, File } from "@phosphor-icons/react"

interface GlobalSearchProps {
  onClose: () => void
}

interface RecordResult {
  id: number | string
  _title: string
  _resource: { uriKey: string; singularLabel: string; icon?: string }
}

export function GlobalSearch({ onClose }: GlobalSearchProps) {
  const { t } = useTranslation("navigation")
  const navigate = useNavigate()
  const inputRef = useRef<HTMLInputElement>(null)
  const [query, setQuery] = useState("")
  const [debouncedQuery, setDebouncedQuery] = useState("")
  const [activeIndex, setActiveIndex] = useState(0)
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const { data: groups = [] } = useQuery<NavigationGroup[]>({
    queryKey: ["navigation"],
    queryFn: () => api.get("/api/navigation"),
    staleTime: 1000 * 60,
  })

  const allResources = groups.flatMap((g) =>
    g.resources.map((r) => ({ ...r, groupLabel: g.label })),
  )

  const filteredResources = query.trim()
    ? allResources.filter(
        (r) =>
          r.label.toLowerCase().includes(query.toLowerCase()) ||
          r.uriKey.toLowerCase().includes(query.toLowerCase()) ||
          (r.groupLabel ?? "").toLowerCase().includes(query.toLowerCase()),
      )
    : allResources

  // Debounce search query for record search
  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current)
    if (query.trim().length < 2) {
      setDebouncedQuery("")
      return
    }
    debounceRef.current = setTimeout(() => {
      setDebouncedQuery(query.trim())
    }, 300)
    return () => { if (debounceRef.current) clearTimeout(debounceRef.current) }
  }, [query])

  // Search records across all resources
  const { data: recordResults = [], isFetching: searchingRecords } = useQuery<RecordResult[]>({
    queryKey: ["global-search", debouncedQuery],
    queryFn: async () => {
      if (!debouncedQuery || debouncedQuery.length < 2) return []
      const searches = allResources.map(async (r) => {
        try {
          const res = await api.get<PaginatedResponse<Record<string, unknown>>>(
            `/api/resources/${r.uriKey}?search=${encodeURIComponent(debouncedQuery)}&per_page=5`
          )
          return (res.data ?? []).map((record: Record<string, unknown>) => ({
            id: record.id as number | string,
            _title: (record._title as string) ?? `#${record.id}`,
            _resource: {
              uriKey: r.uriKey,
              singularLabel: r.singularLabel ?? r.label,
              icon: ((r as Record<string, unknown>).icon as string | null) ?? undefined,
            },
          }))
        } catch {
          return []
        }
      })
      const results = await Promise.all(searches)
      return results.flat()
    },
    enabled: debouncedQuery.length >= 2,
    staleTime: 1000 * 10,
  })

  // Build unified list for keyboard navigation
  type NavItem =
    | { type: "resource"; uriKey: string; label: string; groupLabel?: string; icon?: string }
    | { type: "record"; id: number | string; title: string; resourceUriKey: string; resourceLabel: string; icon?: string }

  const navItems: NavItem[] = []

  // Add resources
  for (const r of filteredResources) {
    navItems.push({
      type: "resource",
      uriKey: r.uriKey,
      label: r.label,
      groupLabel: r.groupLabel ?? undefined,
      icon: ((r as Record<string, unknown>).icon as string | null) ?? undefined,
    })
  }

  // Add records
  for (const rec of recordResults) {
    navItems.push({
      type: "record",
      id: rec.id,
      title: rec._title,
      resourceUriKey: rec._resource.uriKey,
      resourceLabel: rec._resource.singularLabel,
      icon: rec._resource.icon,
    })
  }

  // Focus input on mount
  useEffect(() => {
    inputRef.current?.focus()
  }, [])

  // Reset active index when results change
  useEffect(() => {
    setActiveIndex(0)
  }, [query, recordResults.length])

  const goToItem = useCallback(
    (item: NavItem) => {
      if (item.type === "resource") {
        navigate(`/resources/${item.uriKey}`)
      } else {
        navigate(`/resources/${item.resourceUriKey}/${item.id}`)
      }
      onClose()
    },
    [navigate, onClose],
  )

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === "Escape") {
      onClose()
    } else if (e.key === "ArrowDown") {
      e.preventDefault()
      setActiveIndex((i) => Math.min(i + 1, navItems.length - 1))
    } else if (e.key === "ArrowUp") {
      e.preventDefault()
      setActiveIndex((i) => Math.max(i - 1, 0))
    } else if (e.key === "Enter" && navItems[activeIndex]) {
      goToItem(navItems[activeIndex])
    }
  }

  const hasRecords = recordResults.length > 0
  const resourceEndIndex = filteredResources.length

  return (
    <div className="martis-search-overlay" onClick={onClose}>
      <div
        className="martis-search-modal"
        onClick={(e) => e.stopPropagation()}
        onKeyDown={handleKeyDown}
      >
        <div className="relative">
          <MagnifyingGlass
            size={14}
            className="absolute left-4 top-1/2 -translate-y-1/2"
            style={{ color: "var(--martis-text-muted)" }}
          />
          <input
            ref={inputRef}
            type="text"
            className="martis-search-input"
            placeholder={t("search_resources", "Search resources...")}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
        </div>

        <div className="martis-search-results">
          {navItems.length === 0 && !searchingRecords && (
            <div className="px-4 py-8 text-center text-sm" style={{ color: "var(--martis-text-muted)" }}>
              {t("no_results", "No results found.")}
            </div>
          )}

          {/* Resources section */}
          {filteredResources.length > 0 && (
            <>
              {hasRecords && (
                <div className="px-4 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wider" style={{ color: "var(--martis-text-muted)" }}>
                  Resources
                </div>
              )}
              {filteredResources.map((r, i) => (
                <div
                  key={`res-${r.uriKey}`}
                  className={`martis-search-item ${i === activeIndex ? "active" : ""}`}
                  onClick={() => goToItem(navItems[i])}
                >
                  <Database size={14} style={{ color: "var(--martis-accent)" }} />
                  <div className="flex-1">
                    <div className="text-sm font-medium">{r.label}</div>
                    {r.groupLabel && (
                      <div className="text-xs" style={{ color: "var(--martis-text-muted)" }}>
                        {r.groupLabel}
                      </div>
                    )}
                  </div>
                  <CaretRight size={12} style={{ color: "var(--martis-text-muted)" }} />
                </div>
              ))}
            </>
          )}

          {/* Records section */}
          {hasRecords && (
            <>
              <div className="px-4 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wider" style={{ color: "var(--martis-text-muted)" }}>
                Records
              </div>
              {recordResults.map((rec, i) => {
                const navIndex = resourceEndIndex + i
                return (
                  <div
                    key={`rec-${rec._resource.uriKey}-${rec.id}`}
                    className={`martis-search-item ${navIndex === activeIndex ? "active" : ""}`}
                    onClick={() => goToItem(navItems[navIndex])}
                  >
                    <File size={14} style={{ color: "var(--martis-accent)" }} />
                    <div className="flex-1">
                      <div className="text-sm font-medium">{rec._title}</div>
                      <div className="text-xs" style={{ color: "var(--martis-text-muted)" }}>
                        {rec._resource.singularLabel}
                      </div>
                    </div>
                    <CaretRight size={12} style={{ color: "var(--martis-text-muted)" }} />
                  </div>
                )
              })}
            </>
          )}

          {/* Loading indicator */}
          {searchingRecords && (
            <div className="px-4 py-2 text-center text-xs" style={{ color: "var(--martis-text-muted)" }}>
              Searching records...
            </div>
          )}
        </div>

        <div
          className="flex items-center gap-4 border-t px-4 py-2 text-xs"
          style={{ borderColor: "var(--martis-border)", color: "var(--martis-text-muted)" }}
        >
          <span>
            <kbd className="rounded px-1 py-0.5 text-[10px] font-mono" style={{ backgroundColor: "var(--martis-hover)", border: "1px solid var(--martis-search-border)" }}>
              &uarr;&darr;
            </kbd>{" "}
            {t("navigate", "navigate")}
          </span>
          <span>
            <kbd className="rounded px-1 py-0.5 text-[10px] font-mono" style={{ backgroundColor: "var(--martis-hover)", border: "1px solid var(--martis-search-border)" }}>
              &crarr;
            </kbd>{" "}
            {t("select", "select")}
          </span>
          <span>
            <kbd className="rounded px-1 py-0.5 text-[10px] font-mono" style={{ backgroundColor: "var(--martis-hover)", border: "1px solid var(--martis-search-border)" }}>
              esc
            </kbd>{" "}
            {t("close", "close")}
          </span>
        </div>
      </div>
    </div>
  )
}
