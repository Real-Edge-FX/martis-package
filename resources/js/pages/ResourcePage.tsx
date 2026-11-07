import { useEffect, useRef, useState } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { apiPath, routePath } from '@/lib/apiPath'
import type { OverrideProps, ResourceRecord, ResourceSchema } from '@/types'
import { componentRegistry } from '@/lib/componentRegistry'
import { resolveRedirect } from '@/lib/resolveRedirect'
import { MartisLoader } from '@/components/Loader'
import { useToast } from '@/contexts/ToastContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { ResourceIndexPage } from '@/pages/ResourceIndex'
import { ResourceDetailPage, detailDocumentTitle } from '@/pages/ResourceDetail'
import { ResourceErrorPage } from '@/pages/ResourceError'
import { drawerSentinelOnTop, hasInPlaceDetail, inPlaceMarker, inPlaceState } from '@/lib/inPlaceDetail'
import { waitForHistoryToSettle } from '@/lib/historyLock'

/**
 * Route element of both `/resources/:resource` and `/resources/:resource/:id`.
 *
 * The router keeps a route element's component instance when the URL moves
 * between two routes that render the same component, so serving both paths
 * from here keeps the index mounted while a record opens over it (see
 * `lib/inPlaceDetail.ts`): the list stays on screen, is not fetched again,
 * and the record's override (the bundled drawer, or a custom one) shows its
 * own loading state. A resource without a detail override renders the
 * full detail page, as before.
 */
export function ResourcePage() {
  const { resource, id } = useParams<{ resource: string; id?: string }>()

  // The index fetches the same query, so a row click finds it cached.
  const schemaQuery = useQuery({
    queryKey: ['schema', resource],
    queryFn: () => api.get<{ data: ResourceSchema }>(apiPath`/api/resources/${resource}/schema`),
    enabled: !!resource,
  })
  const schema = schemaQuery.data?.data
  const inPlace = id !== undefined && hasInPlaceDetail(schema)

  // Fetched here, not by the override, so a record the user may not see (or
  // that is gone) shows the error page, as the full detail page does. The
  // bundled drawer reads the same cache entry: one request.
  const recordQuery = useQuery({
    queryKey: ['resource', resource, id],
    queryFn: () => api.get<{ data: ResourceRecord }>(apiPath`/api/resources/${resource}/${id}`),
    enabled: inPlace,
  })

  if (id !== undefined) {
    if (schemaQuery.isLoading) {
      return (
        <div className="flex items-center justify-center py-20">
          <MartisLoader loading size="lg" />
        </div>
      )
    }
    if (!inPlace) {
      return <ResourceDetailPage />
    }
    if (recordQuery.isError) {
      return <ResourceErrorPage error={recordQuery.error} />
    }
  }

  // The index keeps the same position in this fragment whether or not a
  // record is open, so React keeps its instance.
  return (
    <>
      <ResourceIndexPage />
      {id !== undefined && schema && (
        <InPlaceDetail
          key={`${resource}:${id}`}
          schema={schema}
          resource={resource!}
          id={id}
          record={recordQuery.data?.data ?? null}
        />
      )}
    </>
  )
}

interface InPlaceDetailProps {
  schema: ResourceSchema
  resource: string
  id: string
  record: ResourceRecord | null
}

/**
 * The resource's detail override, opened over the index. The bundled drawer
 * (`martis:drawer-detail`) opens at once with its own skeleton; a custom
 * override receives the loaded record, as it always has.
 */
function InPlaceDetail({ schema, resource, id, record }: InPlaceDetailProps) {
  const navigate = useNavigate()
  const location = useLocation()
  const qc = useQueryClient()
  const { addToast } = useToast()
  const [editing, setEditing] = useState(false)
  const [closing, setClosing] = useState(false)
  const closingRef = useRef(false)

  usePageTitle(detailDocumentTitle(schema, record))

  // The entry's marker, as read when the record opened: kept, since the
  // location state the router reads can change under a modal's history
  // sentinel.
  const markerRef = useRef(inPlaceMarker(location.state))
  const currentMarker = inPlaceMarker(location.state)
  if (currentMarker !== null) markerRef.current = currentMarker
  const indexPath = routePath`/resources/${resource}`

  // Close back to the index. The override is taken off the screen first,
  // and the step back waits for what it leaves in the history to settle:
  // a modal over it (a delete confirmation) pops its own sentinel, the
  // bundled drawer pops its own or leaves it on top. An entry opened from
  // the index then steps back to it (the list keeps its URL, page and
  // sort), past the drawer's sentinel when it is still there; a
  // deep-linked record moves to the index (over the sentinel, when there is
  // one), marked as reached from here so the shell treats it as the same
  // page.
  const close = () => {
    if (closingRef.current) return
    closingRef.current = true
    setClosing(true)
  }

  // Runs after the commit that took the override off the screen, so the
  // cleanups of what it rendered (the drawer's and a modal's sentinel pops)
  // have been issued before the wait starts. Dropped if this record is
  // left meanwhile (a link, the back button).
  const closePathname = location.pathname
  useEffect(() => {
    if (!closing) return
    let cancelled = false
    const marker = markerRef.current
    void waitForHistoryToSettle().then(() => {
      if (cancelled) return
      const sentinel = drawerSentinelOnTop()
      if (marker !== null && marker.from === undefined) {
        navigate(-(marker.depth + (sentinel ? 1 : 0)))
        return
      }
      navigate(indexPath, {
        replace: sentinel,
        state: inPlaceState({ backdrop: indexPath, depth: 1, from: closePathname }),
      })
    })
    return () => { cancelled = true }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [closing])

  // Another record of this resource opens in the same place. It replaces
  // the top entry: the drawer's sentinel when there is one (counted in the
  // depth, since the record below stays), else this record's entry.
  const view = (viewId: string | number) => {
    if (String(viewId) === id) return
    const marker = markerRef.current
    const sentinel = drawerSentinelOnTop()
    const fromIndex = marker !== null && marker.from === undefined
    navigate(routePath`/resources/${resource}/${viewId}`, {
      replace: true,
      state: inPlaceState(
        fromIndex
          ? { backdrop: indexPath, depth: marker.depth + (sentinel ? 1 : 0) }
          : { backdrop: indexPath, depth: 1, from: location.pathname },
      ),
    })
  }

  if (closing) return null

  const detail = schema.overrides!.detail!
  const DetailComponent = componentRegistry.resolve(detail.component)
  if (!DetailComponent) return null

  const isBundledDrawer = detail.component === 'martis:drawer-detail'
  if (!record && !isBundledDrawer) {
    // A custom override expects the loaded record: hold a light loader over
    // the list, which stays on screen.
    return (
      <div
        role="status"
        aria-busy="true"
        style={{ position: 'fixed', inset: 0, zIndex: 50, display: 'flex', alignItems: 'center', justifyContent: 'center', pointerEvents: 'none' }}
      >
        <MartisLoader loading size="lg" />
      </div>
    )
  }

  const baseProps: OverrideProps = {
    schema,
    resource,
    params: detail.params ?? {},
    record,
    recordId: id,
    navigate: (to: string) => navigate(to),
    onClose: close,
    onCreated: (rec) => {
      void qc.invalidateQueries({ queryKey: ['resources', resource] })
      addToast('success', schema.messages?.created ?? 'Record created successfully.')
      const target = resolveRedirect(detail.redirectAfter, resource, rec.id)
      if (target) navigate(target)
    },
    onUpdated: (rec) => {
      void qc.invalidateQueries({ queryKey: ['resource', resource, id] })
      void qc.invalidateQueries({ queryKey: ['resources', resource] })
      addToast('success', schema.messages?.updated ?? 'Record updated successfully.')
      const target = resolveRedirect(detail.redirectAfter, resource, rec.id)
      if (target) navigate(target)
    },
    onDeleted: () => {
      void qc.invalidateQueries({ queryKey: ['resources', resource] })
      addToast('success', schema.messages?.deleted ?? 'Record deleted successfully.')
      close()
    },
    onEdit: (editId) => {
      const targetId = editId ?? id
      // The update override swaps in for the detail one, in place.
      if (schema.overrides?.update && String(targetId) === id) {
        setEditing(true)
        return
      }
      navigate(routePath`/resources/${resource}/${targetId}/edit`)
    },
    onView: view,
    addToast,
  }

  const update = schema.overrides?.update
  const UpdateComponent = editing && update ? componentRegistry.resolve(update.component) : undefined
  if (editing && update && UpdateComponent && record) {
    const U = UpdateComponent as React.ComponentType<OverrideProps>
    return (
      <U
        {...baseProps}
        params={update.params ?? {}}
        onClose={() => setEditing(false)}
        onUpdated={(rec) => {
          void qc.invalidateQueries({ queryKey: ['resource', resource, id] })
          void qc.invalidateQueries({ queryKey: ['resources', resource] })
          addToast('success', schema.messages?.updated ?? 'Record updated successfully.')
          setEditing(false)
          const target = resolveRedirect(update.redirectAfter, resource, rec.id)
          if (target) navigate(target)
        }}
      />
    )
  }

  const C = DetailComponent as React.ComponentType<OverrideProps>
  return <C {...baseProps} />
}
