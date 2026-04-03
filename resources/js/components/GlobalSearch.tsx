import { useState, useRef, useEffect, useCallback } from "react"
import { useNavigate } from "react-router-dom"
import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api"
import type { NavigationGroup } from "@/types"
import { useTranslation } from "react-i18next"
import { MagnifyingGlass, Database, CaretRight } from "@phosphor-icons/react"

interface GlobalSearchProps {
  onClose: () => void
}

export function GlobalSearch({ onClose }: GlobalSearchProps) {
  const { t } = useTranslation("navigation")
  const navigate = useNavigate()
  const inputRef = useRef<HTMLInputElement>(null)
  const [query, setQuery] = useState("")
  const [activeIndex, setActiveIndex] = useState(0)

  const { data: groups = [] } = useQuery<NavigationGroup[]>({
    queryKey: ["navigation"],
    queryFn: () => api.get("/api/navigation"),
    staleTime: 1000 * 60,
  })

  const allResources = groups.flatMap((g) =>
    g.resources.map((r) => ({ ...r, groupLabel: g.label })),
  )

  const filtered = query.trim()
    ? allResources.filter(
        (r) =>
          r.label.toLowerCase().includes(query.toLowerCase()) ||
          r.uriKey.toLowerCase().includes(query.toLowerCase()) ||
          (r.groupLabel ?? "").toLowerCase().includes(query.toLowerCase()),
      )
    : allResources

  // Focus input on mount
  useEffect(() => {
    inputRef.current?.focus()
  }, [])

  // Reset active index when results change
  useEffect(() => {
    setActiveIndex(0)
  }, [query])

  const goTo = useCallback(
    (uriKey: string) => {
      navigate(`/resources/${uriKey}`)
      onClose()
    },
    [navigate, onClose],
  )

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === "Escape") {
      onClose()
    } else if (e.key === "ArrowDown") {
      e.preventDefault()
      setActiveIndex((i) => Math.min(i + 1, filtered.length - 1))
    } else if (e.key === "ArrowUp") {
      e.preventDefault()
      setActiveIndex((i) => Math.max(i - 1, 0))
    } else if (e.key === "Enter" && filtered[activeIndex]) {
      goTo(filtered[activeIndex].uriKey)
    }
  }

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
          {filtered.length === 0 && (
            <div className="px-4 py-8 text-center text-sm" style={{ color: "var(--martis-text-muted)" }}>
              {t("no_results", "No results found.")}
            </div>
          )}
          {filtered.map((r, i) => (
            <div
              key={r.uriKey}
              className={`martis-search-item ${i === activeIndex ? "active" : ""}`}
              onClick={() => goTo(r.uriKey)}
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
