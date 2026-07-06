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
  ListBulletsIcon,
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
  /** Optional avatar / thumbnail URL surfaced via Resource::searchImage(). */
  image?: string | null
  url: string
  // Hosts may attach arbitrary fields via Resource::globalSearchResult();
  // a future override slot can pluck them here without touching this type.
  [extra: string]: unknown
}

interface SearchRecordGroup {
  resource: string
  label: string
  items: SearchRecordItem[]
  /** Total matches in the resource — present only when the returned set
   *  hit the resource's per-search limit. Used to render the "View all"
   *  footer item with the real count. */
  total?: number
  /** Destination for the "View all" affordance. Null when the resource has
   *  no listing page (non-routable without a searchIndexUrl) — the frontend
   *  then renders the count as a non-clickable label instead of a dead link. */
  viewAllUrl: string | null
}

interface SearchRecordsResponse {
  results: SearchRecordGroup[]
}

type PaletteItem =
  | { kind: 'resource'; label: string; hint: string | null; url: string; icon: string | null }
  | { kind: 'action'; label: string; hint: string | null; url: string; icon: string | null; destructive: boolean }
  | { kind: 'recent'; label: string; hint: string | null; url: string | null; icon: ReactNode }
  | { kind: 'record'; label: string; hint: string | null; url: string; image?: string | null }
  | { kind: 'view-all'; label: string; hint: string | null; url: string | null }
  | { kind: 'recent-query'; label: string; hint: string | null; url: null; query: string }

interface PaletteSection {
  label: string
  items: PaletteItem[]
}

const MIN_QUERY_LEN_RECORDS = 2

// Recent queries — persisted in sessionStorage under this key so they
// survive client-side navigation but die with the tab. Capped at 5 to
// keep the empty-state footer short and the storage payload tiny.
const RECENT_QUERIES_KEY = 'martis:cmdk-recent-queries'
const RECENT_QUERIES_LIMIT = 5

function readRecentQueries(): string[] {
  try {
    if (typeof window === 'undefined') return []
    const raw = window.sessionStorage.getItem(RECENT_QUERIES_KEY)
    if (!raw) return []
    const parsed = JSON.parse(raw) as unknown
    return Array.isArray(parsed) ? parsed.filter((q): q is string => typeof q === 'string') : []
  } catch {
    return []
  }
}

function pushRecentQuery(q: string): void {
  try {
    if (typeof window === 'undefined') return
    const trimmed = q.trim()
    if (trimmed.length < MIN_QUERY_LEN_RECORDS) return
    const existing = readRecentQueries().filter((r) => r !== trimmed)
    const next = [trimmed, ...existing].slice(0, RECENT_QUERIES_LIMIT)
    window.sessionStorage.setItem(RECENT_QUERIES_KEY, JSON.stringify(next))
  } catch {
    // sessionStorage may throw under quota or privacy modes — drop silently.
  }
}

// A palette row is interactive when running it does something: recent-query
// rows re-fill the input, everything else navigates via its `url`. A
// `view-all` row whose `url` is null (non-routable resource, no listing) is
// the sole non-interactive case — it renders as a static count label and is
// skipped by keyboard navigation so Enter never lands on a dead row.
function isInteractive(item: PaletteItem): boolean {
  return item.kind === 'recent-query' || item.url != null
}

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
  const [recentQueries, setRecentQueries] = useState<string[]>(() => readRecentQueries())
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

  // Persist any query that produced at least one record group so it
  // shows up in "Recent searches" next time the palette opens. We
  // deliberately gate on `results.length > 0` to keep the list signal-
  // heavy — empty searches don't pollute it.
  useEffect(() => {
    if (!debouncedQuery) return
    if (!searchResponse) return
    if (searchResponse.results.length === 0) return
    pushRecentQuery(debouncedQuery)
    setRecentQueries(readRecentQueries())
  }, [debouncedQuery, searchResponse])

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

  // Recent queries — only when the input is empty. Clicking re-runs
  // the query by writing it back into the input, which triggers the
  // debounced /api/search call. Backend-free: pure sessionStorage.
  if (!query && recentQueries.length > 0) {
    const recentQueryItems: PaletteItem[] = recentQueries.map((q) => ({
      kind: 'recent-query' as const,
      label: q,
      hint: null,
      url: null,
      query: q,
    }))
    sections.push({
      label: t('palette_recent_searches', 'Recent searches'),
      items: recentQueryItems,
    })
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
      image: item.image ?? null,
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
    // Recent-query items don't navigate — they re-fill the input so
    // the live `/api/search` debounce kicks in.
    if (item.kind === 'recent-query') {
      setQuery(item.query)
      setDebouncedQuery(item.query)
      inputRef.current?.focus()
      return
    }

    if (item.url) {
      navigate(item.url)
      onClose()
    }
  }, [navigate, onClose])

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === 'Escape') {
      onClose()
    } else if (e.key === 'ArrowDown') {
      e.preventDefault()
      setActiveIndex((i) => {
        for (let j = i + 1; j < flatItems.length; j++) {
          if (isInteractive(flatItems[j])) return j
        }
        return i
      })
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setActiveIndex((i) => {
        for (let j = i - 1; j >= 0; j--) {
          if (isInteractive(flatItems[j])) return j
        }
        return i
      })
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
                const recordImage = item.kind === 'record' ? (item.image ?? null) : null
                const icon = recordImage
                  ? <img src={recordImage} alt="" className="martis-cmdk-avatar" />
                  : renderItemIcon(item)

                // Non-interactive rows (a view-all count with no listing
                // destination) render as a static label: no button, no
                // active state, no hover selection — the click that "did
                // nothing" is gone because there is nothing to click.
                if (!isInteractive(item)) {
                  return (
                    <div
                      key={`${section.label}:${globalIdx}:${item.label}`}
                      className="martis-cmdk-item is-static"
                      aria-disabled="true"
                    >
                      {icon}
                      <span className="martis-cmdk-name">{item.label}</span>
                      {item.hint && <span className="martis-cmdk-hint">{item.hint}</span>}
                    </div>
                  )
                }

                const isActive = globalIdx === activeIndex
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
    // Arrow (navigates) only when there's a destination; otherwise a
    // neutral list glyph so the static count doesn't imply an action.
    return item.url != null
      ? <ArrowRightIcon size={16} weight="bold" />
      : <ListBulletsIcon size={16} />
  }
  if (item.kind === 'recent-query') {
    return <MagnifyingGlassIcon size={16} />
  }
  return item.icon ?? <ClockCounterClockwiseIcon size={16} />
}
