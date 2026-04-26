import { useState, useRef, useEffect, useCallback, type ReactNode } from "react"
import { createPortal } from "react-dom"
import { useNavigate } from "react-router-dom"
import { useQuery } from "@tanstack/react-query"
import { api } from "@/lib/api"
import { useTranslation } from "react-i18next"
import {
  MagnifyingGlassIcon,
  DatabaseIcon,
  LightningIcon,
  ClockCounterClockwiseIcon,
  FileIcon,
  ArrowRightIcon,
} from "@phosphor-icons/react"
import { ResourceIcon } from "@/components/ResourceIcon"
import { isMacPlatform } from "@/lib/platform"

interface GlobalSearchProps {
  onClose: () => void
}

interface PaletteResource {
  key: string
  uriKey: string
  label: string
  icon: string | null
  group: string | null
  url: string
}

interface PaletteAction {
  key: string
  label: string
  icon: string | null
  destructive: boolean
  resource: string
  resourceUriKey: string
  url: string
}

interface PaletteRecent {
  key: number
  label: string
  subtitle: string | null
  url: string | null
  created_at: string
}

interface PaletteResponse {
  resources: PaletteResource[]
  actions: PaletteAction[]
  recent: PaletteRecent[]
}

interface SearchRecordItem {
  id: number | string
  title: string
  subtitle: string | null
  url: string
}

interface SearchRecordGroup {
  resource: string
  label: string
  items: SearchRecordItem[]
  /** Total matches in the resource — present only when the returned set
   *  hit the resource's per-search limit. Used to render the "View all"
   *  footer item with the real count. */
  total?: number
  viewAllUrl: string
}

interface SearchRecordsResponse {
  results: SearchRecordGroup[]
}

type PaletteItem =
  | { kind: 'resource'; label: string; hint: string | null; url: string; icon: string | null }
  | { kind: 'action'; label: string; hint: string | null; url: string; icon: string | null; destructive: boolean }
  | { kind: 'recent'; label: string; hint: string | null; url: string | null; icon: ReactNode }
  | { kind: 'record'; label: string; hint: string | null; url: string }
  | { kind: 'view-all'; label: string; hint: string | null; url: string }

interface PaletteSection {
  label: string
  items: PaletteItem[]
}

const MIN_QUERY_LEN_RECORDS = 2

function matches(item: PaletteItem, query: string): boolean {
  if (!query) return true
  const q = query.toLowerCase()
  return (
    item.label.toLowerCase().includes(q) ||
    (item.hint?.toLowerCase().includes(q) ?? false)
  )
}

export function GlobalSearch({ onClose }: GlobalSearchProps) {
  const { t } = useTranslation("navigation")
  const navigate = useNavigate()
  const inputRef = useRef<HTMLInputElement>(null)
  const listRef = useRef<HTMLDivElement>(null)
  const [query, setQuery] = useState("")
  const [debouncedQuery, setDebouncedQuery] = useState("")
  const [activeIndex, setActiveIndex] = useState(0)
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  // Palette aggregate (resources + standalone actions + recent events).
  // Fresh on every open — the SPA already caches it for 30 s.
  const { data: palette } = useQuery<PaletteResponse>({
    queryKey: ["command-palette"],
    queryFn: () => api.get("/api/command-palette"),
    staleTime: 1000 * 30,
  })

  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current)
    if (query.trim().length < MIN_QUERY_LEN_RECORDS) {
      setDebouncedQuery("")
      return
    }
    debounceRef.current = setTimeout(() => setDebouncedQuery(query.trim()), 300)
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current)
    }
  }, [query])

  // Record-level hits via the existing unified search endpoint.
  const { data: searchResponse, isFetching: searchingRecords } = useQuery<SearchRecordsResponse>({
    queryKey: ["global-search", debouncedQuery],
    queryFn: () =>
      api.get<SearchRecordsResponse>(`/api/search?q=${encodeURIComponent(debouncedQuery)}`),
    enabled: debouncedQuery.length >= MIN_QUERY_LEN_RECORDS,
    staleTime: 1000 * 10,
  })

  // Build the rendered sections from palette data + live record hits.
  const sections: PaletteSection[] = []

  const resourceItems: PaletteItem[] = (palette?.resources ?? [])
    .map((r) => ({
      kind: 'resource' as const,
      label: r.label,
      hint: r.group,
      url: r.url,
      icon: r.icon,
    }))
    .filter((i) => matches(i, query))
  if (resourceItems.length > 0) {
    sections.push({ label: t('palette_resources', 'Resources'), items: resourceItems })
  }

  const actionItems: PaletteItem[] = (palette?.actions ?? [])
    .map((a) => ({
      kind: 'action' as const,
      label: a.label,
      hint: a.resource,
      url: a.url,
      icon: a.icon,
      destructive: a.destructive,
    }))
    .filter((i) => matches(i, query))
  if (actionItems.length > 0) {
    sections.push({ label: t('palette_actions', 'Actions'), items: actionItems })
  }

  const recentItems: PaletteItem[] = (palette?.recent ?? [])
    .filter((r) => r.url !== null)
    .map((r) => ({
      kind: 'recent' as const,
      label: r.label,
      hint: r.subtitle,
      url: r.url,
      icon: <ClockCounterClockwiseIcon size={16} />,
    }))
    .filter((i) => matches(i, query))
  if (recentItems.length > 0 && !query) {
    sections.push({ label: t('palette_recent', 'Recent activity'), items: recentItems })
  }

  // ⭐ Differential 2 — render each resource as its own section with a
  // trailing "View all N matches in {resource}" item when the backend
  // signalled overflow (`total` present + > items.length). Promotes
  // discoverability when a query has dozens of hits without forcing
  // the user to leave the palette.
  for (const group of searchResponse?.results ?? []) {
    const groupItems: PaletteItem[] = group.items.map<PaletteItem>((item) => ({
      kind: 'record',
      label: item.title,
      hint: item.subtitle ?? group.label,
      url: item.url,
    }))

    if (typeof group.total === 'number' && group.total > group.items.length) {
      groupItems.push({
        kind: 'view-all',
        label: t('palette_view_all', {
          count: group.total,
          resource: group.label,
          defaultValue: 'View all {{count}} in {{resource}}',
        }),
        hint: null,
        url: group.viewAllUrl,
      })
    }

    if (groupItems.length > 0) {
      sections.push({ label: group.label, items: groupItems })
    }
  }

  // Flatten for keyboard navigation — the click targets must line up with
  // the same order rendered below.
  const flatItems = sections.flatMap((s) => s.items)

  useEffect(() => { inputRef.current?.focus() }, [])
  useEffect(() => { setActiveIndex(0) }, [query, flatItems.length])

  useEffect(() => {
    const active = listRef.current?.querySelector<HTMLElement>('[data-cmdk-active="true"]')
    active?.scrollIntoView({ block: 'nearest' })
  }, [activeIndex])

  const runItem = useCallback((item: PaletteItem) => {
    if (item.url) {
      if ('url' in item && item.url) {
        navigate(item.url)
        onClose()
      }
    }
  }, [navigate, onClose])

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === 'Escape') {
      onClose()
    } else if (e.key === 'ArrowDown') {
      e.preventDefault()
      setActiveIndex((i) => Math.min(i + 1, flatItems.length - 1))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setActiveIndex((i) => Math.max(i - 1, 0))
    } else if (e.key === 'Enter' && flatItems[activeIndex]) {
      e.preventDefault()
      runItem(flatItems[activeIndex])
    }
  }

  let flatCursor = 0

  // Portal to `document.body` so the scrim sits in the root stacking
  // context and fully blocks clicks on sidebar / topbar (both of which
  // create their own stacking contexts at z:10 / z:auto). Without the
  // portal the scrim's z:9995 is scoped to the Topbar component — a
  // user can click a sidebar item while the palette is "open".
  return createPortal(
    <div className="martis-cmdk-scrim" onClick={onClose}>
      <div className="martis-cmdk" onClick={(e) => e.stopPropagation()} onKeyDown={handleKeyDown}>
        <div className="martis-cmdk-search">
          <MagnifyingGlassIcon size={16} />
          <input
            ref={inputRef}
            type="text"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={t('palette_placeholder', 'Type a command or search…')}
            autoFocus
          />
          <kbd className="martis-kbd">esc</kbd>
        </div>

        <div className="martis-cmdk-list" ref={listRef}>
          {flatItems.length === 0 && !searchingRecords && (
            <div className="martis-cmdk-empty">
              {t('no_results', 'No results found.')}
            </div>
          )}

          {sections.map((section) => (
            <div key={section.label}>
              <div className="martis-cmdk-group">{section.label}</div>
              {section.items.map((item) => {
                const globalIdx = flatCursor++
                const isActive = globalIdx === activeIndex
                const icon = renderItemIcon(item)
                return (
                  <button
                    key={`${section.label}:${globalIdx}:${item.label}`}
                    type="button"
                    className={`martis-cmdk-item ${isActive ? 'is-active' : ''}`}
                    data-cmdk-active={isActive ? 'true' : undefined}
                    onClick={() => runItem(item)}
                    onMouseEnter={() => setActiveIndex(globalIdx)}
                  >
                    {icon}
                    <span className="martis-cmdk-name">{item.label}</span>
                    {item.hint && <span className="martis-cmdk-hint">{item.hint}</span>}
                  </button>
                )
              })}
            </div>
          ))}

          {searchingRecords && (searchResponse?.results.length ?? 0) === 0 && debouncedQuery.length >= MIN_QUERY_LEN_RECORDS && (
            <div className="martis-cmdk-empty">
              {t('searching', 'Searching...')}
            </div>
          )}
        </div>

        <div className="martis-cmdk-foot">
          <span><kbd className="martis-kbd">&uarr;&darr;</kbd>{t('navigate', 'navigate')}</span>
          <span><kbd className="martis-kbd">&crarr;</kbd>{t('select', 'select')}</span>
          <span>
            <kbd className="martis-kbd">{isMacPlatform() ? '\u2318K' : 'Ctrl K'}</kbd>
            <kbd className="martis-kbd">/</kbd>
            {t('palette_toggle', 'toggle')}
          </span>
          <span className="martis-cmdk-foot-spacer" />
          <span>{t('palette_results', { count: flatItems.length, defaultValue: '{{count}} results' })}</span>
        </div>
      </div>
    </div>,
    document.body,
  )
}

function renderItemIcon(item: PaletteItem): ReactNode {
  if (item.kind === 'resource') {
    return item.icon
      ? <ResourceIcon iconName={item.icon} size={16} />
      : <DatabaseIcon size={16} />
  }
  if (item.kind === 'action') {
    return item.icon
      ? <ResourceIcon iconName={item.icon} size={16} />
      : <LightningIcon size={16} />
  }
  if (item.kind === 'record') {
    return <FileIcon size={16} />
  }
  if (item.kind === 'view-all') {
    return <ArrowRightIcon size={16} weight="bold" />
  }
  return item.icon ?? <ClockCounterClockwiseIcon size={16} />
}
