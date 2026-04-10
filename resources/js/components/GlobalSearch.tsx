import { useState, useRef, useEffect, useCallback } from "react"
import { useNavigate } from "react-router-dom"
import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api"
import type { NavigationGroup } from "@/types"
import { useTranslation } from "react-i18next"
import { MagnifyingGlass, Database, CaretRight, File, Spinner } from "@phosphor-icons/react"

interface GlobalSearchProps {
  onClose: () => void
}

interface SearchResultItem {
  id: number | string
  title: string
  subtitle: string | null
  url: string
}

interface SearchResultGroup {
  resource: string
  label: string
  items: SearchResultItem[]
}

interface GlobalSearchResponse {
  results: SearchResultGroup[]
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

  // Debounce the query for the unified search endpoint
  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current)
    if (query.trim().length < 2) {
      setDebouncedQuery("")
      return
    }
    debounceRef.current = setTimeout(() => {
      setDebouncedQuery(query.trim())
    }, 300)
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current)
    }
  }, [query])

  // Unified global search — single request, grouped results with subtitle
  const { data: searchResponse, isFetching: searchingRecords } = useQuery<GlobalSearchResponse>({
    queryKey: ["global-search", debouncedQuery],
    queryFn: () =>
      api.get<GlobalSearchResponse>(`/api/search?q=${encodeURIComponent(debouncedQuery)}`),
    enabled: debouncedQuery.length >= 2,
    staleTime: 1000 * 10,
  })

  const searchGroups = searchResponse?.results ?? []

  // Flatten all record results for keyboard navigation
  const allRecordItems = searchGroups.flatMap((group) =>
    group.items.map((item) => ({ ...item, resourceLabel: group.label })),
  )

  // Build unified navigation list
  type NavItem =
    | { type: "resource"; uriKey: string; label: string; groupLabel?: string; icon?: string }
    | { type: "record"; item: SearchResultItem; resourceLabel: string }

  const navItems: NavItem[] = []

  for (const r of filteredResources) {
    navItems.push({
      type: "resource",
      uriKey: r.uriKey,
      label: r.label,
      groupLabel: r.groupLabel ?? undefined,
      icon: ((r as Record<string, unknown>).icon as string | null) ?? undefined,
    })
  }

  for (const rec of allRecordItems) {
    navItems.push({ type: "record", item: rec, resourceLabel: rec.resourceLabel })
  }

  const resourceEndIndex = filteredResources.length

  // Focus input on mount
  useEffect(() => {
    inputRef.current?.focus()
  }, [])

  // Reset active index when results change
  useEffect(() => {
    setActiveIndex(0)
  }, [query, allRecordItems.length])

  const goToItem = useCallback(
    (item: NavItem) => {
      if (item.type === "resource") {
        navigate(`/resources/${item.uriKey}`)
      } else {
        navigate(item.item.url)
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

  const hasRecords = allRecordItems.length > 0

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
          {searchingRecords && (
            <Spinner
              size={14}
              className="absolute right-4 top-1/2 -translate-y-1/2 animate-spin"
              style={{ color: "var(--martis-text-muted)" }}
            />
          )}
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
                <div
                  className="px-4 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wider"
                  style={{ color: "var(--martis-text-muted)" }}
                >
                  {t("section_resources", "Resources")}
                </div>
              )}
              {filteredResources.map((r, i) => (
                <div
                  key={`res-${r.uriKey}`}
                  className={`martis-search-item ${i === activeIndex ? "active" : ""}`}
                  onClick={() => goToItem(navItems[i])}
                >
                  <Database size={14} style={{ color: "var(--martis-accent)" }} />
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-medium">{r.label}</div>
                    {r.groupLabel && (
                      <div className="text-xs truncate" style={{ color: "var(--martis-text-muted)" }}>
                        {r.groupLabel}
                      </div>
                    )}
                  </div>
                  <CaretRight size={12} style={{ color: "var(--martis-text-muted)" }} />
                </div>
              ))}
            </>
          )}

          {/* Records grouped by resource — with subtitle */}
          {hasRecords &&
            searchGroups.map((group) => (
              <div key={group.resource}>
                <div
                  className="px-4 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wider"
                  style={{ color: "var(--martis-text-muted)" }}
                >
                  {group.label}
                </div>
                {group.items.map((item) => {
                  const flatIndex = allRecordItems.findIndex(
                    (r) => r.id === item.id && r.url === item.url,
                  )
                  const navIndex = resourceEndIndex + flatIndex
                  return (
                    <div
                      key={`rec-${group.resource}-${item.id}`}
                      className={`martis-search-item ${navIndex === activeIndex ? "active" : ""}`}
                      onClick={() => goToItem(navItems[navIndex])}
                    >
                      <File size={14} style={{ color: "var(--martis-accent)" }} />
                      <div className="flex-1 min-w-0">
                        <div className="text-sm font-medium truncate">{item.title}</div>
                        {item.subtitle && (
                          <div className="text-xs truncate" style={{ color: "var(--martis-text-muted)" }}>
                            {item.subtitle}
                          </div>
                        )}
                      </div>
                      <CaretRight size={12} style={{ color: "var(--martis-text-muted)" }} />
                    </div>
                  )
                })}
              </div>
            ))}

          {/* Loading indicator when no results yet */}
          {searchingRecords && !hasRecords && debouncedQuery.length >= 2 && (
            <div className="px-4 py-4 text-center text-xs" style={{ color: "var(--martis-text-muted)" }}>
              {t("searching", "Searching...")}
            </div>
          )}
        </div>

        <div
          className="flex items-center gap-4 border-t px-4 py-2 text-xs"
          style={{ borderColor: "var(--martis-border)", color: "var(--martis-text-muted)" }}
        >
          <span>
            <kbd
              className="rounded px-1 py-0.5 text-[10px] font-mono"
              style={{ backgroundColor: "var(--martis-hover)", border: "1px solid var(--martis-search-border)" }}
            >
              &uarr;&darr;
            </kbd>{" "}
            {t("navigate", "navigate")}
          </span>
          <span>
            <kbd
              className="rounded px-1 py-0.5 text-[10px] font-mono"
              style={{ backgroundColor: "var(--martis-hover)", border: "1px solid var(--martis-search-border)" }}
            >
              &crarr;
            </kbd>{" "}
            {t("select", "select")}
          </span>
          <span>
            <kbd
              className="rounded px-1 py-0.5 text-[10px] font-mono"
              style={{ backgroundColor: "var(--martis-hover)", border: "1px solid var(--martis-search-border)" }}
            >
              esc
            </kbd>{" "}
            {t("close", "close")}
          </span>
        </div>
      </div>
    </div>
  )
}
