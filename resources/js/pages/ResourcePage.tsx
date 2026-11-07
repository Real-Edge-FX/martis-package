import { useState } from 'react'
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
import { NotFoundPage } from '@/pages/NotFound'
import { drawerSentinelOnTop, inPlaceMarker, inPlaceState, opensDetailInPlace } from '@/lib/inPlaceDetail'

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
  const inPlace = id !== undefined && !!schema && opensDetailInPlace(schema, resource!)

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

  usePageTitle(detailDocumentTitle(schema, record))

  const marker = inPlaceMarker(location.state)
  const indexPath = routePath`/resources/${resource}`

  // Close back to the index. An entry opened from the index steps back to
  // it (the list keeps its URL, page and sort), past the sentinel the
  // bundled drawer pushes on top while it is open; a deep-linked record
  // moves to the index (over the sentinel, when there is one), marked as
  // reached from here so the shell treats it as the same page.
  const close = () => {
    const sentinel = drawerSentinelOnTop()
    if (marker !== null && marker.from === undefined) {
      navigate(-(marker.depth + (sentinel ? 1 : 0)))
      return
    }
    navigate(indexPath, {
      replace: sentinel,
      state: inPlaceState({ backdrop: indexPath, depth: 1, from: location.pathname }),
    })
  }

  // Another record of this resource opens in the same place.
  const view = (viewId: string | number) => {
    if (String(viewId) === id) return
    const depth = marker !== null && marker.from === undefined ? marker.depth + 1 : 1
    navigate(routePath`/resources/${resource}/${viewId}`, {
      state: inPlaceState({ backdrop: indexPath, depth, ...(marker === null || marker.from !== undefined ? { from: location.pathname } : {}) }),
    })
  }

  const detail = schema.overrides!.detail!
  const DetailComponent = componentRegistry.resolve(detail.component)
  if (!DetailComponent) return <NotFoundPage />

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
