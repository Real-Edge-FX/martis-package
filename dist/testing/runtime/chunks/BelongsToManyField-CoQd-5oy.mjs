import { jsx, jsxs, Fragment } from "react/jsx-runtime";
import { useState, useRef, useEffect, useCallback, useMemo } from "react";
import { createPortal } from "react-dom";
import { u as useTranslation, v as useToast, x as useNavigate, y as useActionResponseModal, n as useModalHistoryLock, z as pathSegment, j as useQuery, l as useMutation, A as ApiError, C as martisEventBus, m as api, q as FieldInput, D as fieldErrorProps, f as useRelationParent, o as useHiddenAttributes, p as withoutHiddenFields, E as lockImmutableFields, t as nestedErrorsOf, i as useQueryClient, h as apiPath, k as useEscapeLayer, r as recordHref, w as withQuery, F as FieldDisplay } from "./index-BhNW1fGr.mjs";
import { h as handleActionResponse, A as ActionDryRunPreview, R as RelationshipTableShell, D as DataTable, C as Column, P as Pagination } from "./RelationshipTableShell-B2nP2yLX.mjs";
import { WarningIcon, LightningIcon, XIcon, PencilSimpleIcon, FloppyDiskIcon, LinkSimpleIcon, LinkBreakIcon, CaretDownIcon, PlusIcon, MagnifyingGlassIcon } from "@phosphor-icons/react";
const MODAL_SIZE_MAP$1 = {
  sm: "24rem",
  md: "28rem",
  lg: "32rem",
  xl: "36rem",
  "2xl": "42rem",
  "3xl": "48rem",
  "4xl": "56rem",
  "5xl": "64rem",
  "6xl": "72rem",
  "7xl": "80rem"
};
function PivotActionModal({
  actionsUrl,
  resourceKey,
  action,
  selectedIds,
  onSuccess,
  onClose
}) {
  var _a, _b;
  const { t } = useTranslation("actions");
  const { addToast } = useToast();
  const navigate = useNavigate();
  const showModal = useActionResponseModal();
  useModalHistoryLock(true);
  const [fieldValues, setFieldValues] = useState({});
  const [fieldErrors, setFieldErrors] = useState({});
  const [preview, setPreview] = useState(void 0);
  const [animVisible, setAnimVisible] = useState(false);
  const autoExecuted = useRef(false);
  useEffect(() => {
    requestAnimationFrame(() => setAnimVisible(true));
    autoExecuted.current = false;
  }, []);
  const handleBackdropClose = useCallback(() => {
    setAnimVisible(false);
    setTimeout(onClose, 200);
  }, [onClose]);
  useEffect(() => {
    function handleKey(e) {
      if (e.key === "Escape") {
        e.preventDefault();
        onClose();
      }
    }
    document.addEventListener("keydown", handleKey);
    return () => document.removeEventListener("keydown", handleKey);
  }, [onClose]);
  const actionUrl = `${actionsUrl}/${pathSegment(action.uriKey)}`;
  const fieldsQuery = useQuery({
    queryKey: ["pivot-action-fields", actionsUrl, action.uriKey],
    queryFn: ({ signal }) => api.get(`${actionUrl}/fields`, signal),
    enabled: !!action
  });
  const fields = ((_b = (_a = fieldsQuery.data) == null ? void 0 : _a.data) == null ? void 0 : _b.fields) ?? [];
  const executeMutation = useMutation({
    // Each run starts without the errors of the last one, as every form
    // clears its errors before a save.
    onMutate: () => setFieldErrors({}),
    mutationFn: (params) => api.post(
      actionUrl,
      {
        resources: selectedIds,
        fields: fieldValues,
        dryRun: params.dryRun ?? false
      }
    ),
    onSuccess: (res, params) => {
      var _a2;
      if (params.dryRun) {
        setPreview(((_a2 = res == null ? void 0 : res.data) == null ? void 0 : _a2.preview) ?? null);
        return;
      }
      martisEventBus.emit("martis:action-executed", { resourceKey, action: action.uriKey, ids: selectedIds.map(String) });
      handleActionResponse(res == null ? void 0 : res.data, { t, addToast, navigate, hide: onClose, refresh: onSuccess, showModal });
    },
    onError: (err) => {
      if (err instanceof ApiError && err.errors && err.errors.length > 0) {
        const mapped = {};
        for (const e of err.errors) {
          const fieldKey = e.field.replace(/^fields\./, "");
          if (!mapped[fieldKey]) mapped[fieldKey] = e.message;
        }
        if (Object.keys(mapped).length > 0) {
          setFieldErrors(mapped);
          addToast("error", err.message || t("action_failed"));
          return;
        }
      }
      addToast("error", (err instanceof ApiError ? err.message : err.message) ?? t("action_failed"));
    }
  });
  const hasFields = fields.length > 0;
  const needsConfirmation = action.withConfirmation || hasFields || action.supportsDryRun;
  if (!needsConfirmation && !autoExecuted.current && !executeMutation.isPending) {
    autoExecuted.current = true;
    setTimeout(() => executeMutation.mutate({}), 0);
    return null;
  }
  if (!needsConfirmation) return null;
  const modalWidth = MODAL_SIZE_MAP$1[action.modalSize ?? "md"] ?? MODAL_SIZE_MAP$1["md"];
  return createPortal(/* @__PURE__ */ jsx(
    "div",
    {
      className: "martis-modal-scrim",
      style: { opacity: animVisible ? 1 : 0, transition: "opacity 200ms ease" },
      onClick: handleBackdropClose,
      children: /* @__PURE__ */ jsxs(
        "div",
        {
          role: "dialog",
          "aria-modal": "true",
          className: "martis-modal-surface",
          style: {
            maxWidth: modalWidth,
            transform: animVisible ? "scale(1)" : "scale(0.95)",
            transition: "transform 200ms ease",
            borderTop: action.destructive ? "3px solid var(--martis-danger)" : void 0
          },
          onClick: (e) => e.stopPropagation(),
          children: [
            /* @__PURE__ */ jsxs("div", { className: "martis-modal-head", children: [
              /* @__PURE__ */ jsxs("div", { className: "flex items-center gap-3", children: [
                action.destructive ? /* @__PURE__ */ jsx(WarningIcon, { size: 18, weight: "fill", style: { color: "var(--martis-danger)" } }) : /* @__PURE__ */ jsx(LightningIcon, { size: 18, weight: "fill", style: { color: "var(--martis-accent)" } }),
                /* @__PURE__ */ jsx("h3", { className: "martis-modal-head-title", children: action.name })
              ] }),
              /* @__PURE__ */ jsx(
                "button",
                {
                  type: "button",
                  onClick: onClose,
                  className: "martis-modal-close",
                  "aria-label": action.cancelButtonText ?? t("cancel"),
                  children: /* @__PURE__ */ jsx(XIcon, { size: 16 })
                }
              )
            ] }),
            /* @__PURE__ */ jsxs("div", { className: "martis-modal-body", children: [
              action.confirmText && /* @__PURE__ */ jsx("p", { className: "mb-4", children: action.confirmText }),
              hasFields && /* @__PURE__ */ jsx("div", { className: "space-y-4", children: fields.map((f) => /* @__PURE__ */ jsxs("div", { children: [
                /* @__PURE__ */ jsxs("label", { className: "mb-1 block text-sm font-medium", style: { color: "var(--martis-text)" }, children: [
                  f.label,
                  f.required && /* @__PURE__ */ jsx("span", { className: "ml-1", style: { color: "var(--martis-danger)" }, children: "*" })
                ] }),
                /* @__PURE__ */ jsx(
                  FieldInput,
                  {
                    field: f,
                    value: fieldValues[f.attribute] ?? "",
                    onChange: (val) => {
                      setFieldValues((prev) => ({ ...prev, [f.attribute]: val }));
                      setPreview(void 0);
                    },
                    ...fieldErrorProps(fieldErrors, f.attribute),
                    context: "create",
                    actionEndpoint: actionUrl
                  }
                )
              ] }, f.attribute)) }),
              preview !== void 0 && /* @__PURE__ */ jsx(ActionDryRunPreview, { preview })
            ] }),
            /* @__PURE__ */ jsxs("div", { className: "martis-modal-foot", children: [
              action.supportsDryRun && /* @__PURE__ */ jsx(
                "button",
                {
                  type: "button",
                  onClick: () => executeMutation.mutate({ dryRun: true }),
                  disabled: executeMutation.isPending,
                  className: "martis-btn-secondary",
                  children: t("preview")
                }
              ),
              /* @__PURE__ */ jsxs(
                "button",
                {
                  type: "button",
                  onClick: onClose,
                  disabled: executeMutation.isPending,
                  className: "martis-btn-secondary",
                  children: [
                    /* @__PURE__ */ jsx(XIcon, { size: 14 }),
                    action.cancelButtonText ?? t("cancel")
                  ]
                }
              ),
              /* @__PURE__ */ jsxs(
                "button",
                {
                  type: "button",
                  onClick: () => executeMutation.mutate({}),
                  disabled: executeMutation.isPending,
                  className: action.destructive ? "martis-btn-danger" : "martis-btn-primary",
                  children: [
                    /* @__PURE__ */ jsx(LightningIcon, { size: 14 }),
                    executeMutation.isPending ? t("please_wait") : action.confirmButtonText ?? t("run_action")
                  ]
                }
              )
            ] })
          ]
        }
      )
    }
  ), document.body);
}
function useAttachFormDraft(field, formValues) {
  const dependentFieldNames = useMemo(() => {
    const meta = field == null ? void 0 : field.dependsOn;
    return Array.isArray(meta == null ? void 0 : meta.fields) ? meta.fields : [];
  }, [field]);
  const snapshot = useMemo(() => {
    if (dependentFieldNames.length === 0 || !formValues) return {};
    const out = {};
    for (const name of dependentFieldNames) {
      if (Object.prototype.hasOwnProperty.call(formValues, name)) {
        out[name] = formValues[name];
      }
    }
    return out;
  }, [dependentFieldNames, formValues]);
  const appendFormDraft = useCallback((params) => {
    for (const [k, v] of Object.entries(snapshot)) {
      if (v === null || v === void 0) continue;
      if (typeof v === "string" || typeof v === "number" || typeof v === "boolean") {
        params.set(`form[${k}]`, String(v));
      }
    }
  }, [snapshot]);
  return { snapshot, appendFormDraft };
}
function withFormDraft(path, appendFormDraft) {
  const params = new URLSearchParams();
  appendFormDraft(params);
  const query = params.toString();
  return query ? `${path}?${query}` : path;
}
function pivotRowActions(opts) {
  const showEditPivot = !opts.readOnly && opts.pivotFieldsCount > 0 && !opts.hideEditAction;
  const showDetach = !opts.readOnly && opts.canDetach && !opts.hideDeleteAction;
  return { showEditPivot, showDetach, hasAny: showEditPivot || showDetach };
}
const MODAL_SIZE_MAP = {
  sm: "24rem",
  md: "28rem",
  lg: "32rem",
  xl: "36rem",
  "2xl": "42rem",
  "3xl": "48rem",
  "4xl": "56rem",
  "5xl": "64rem",
  "6xl": "72rem",
  "7xl": "80rem"
};
function BelongsToManyFieldDisplay({ field, value }) {
  const { id: parentId } = useRelationParent();
  if (typeof value === "number") {
    return /* @__PURE__ */ jsx(BelongsToManyCountBadge, { count: value });
  }
  if (!parentId) return null;
  return /* @__PURE__ */ jsx(BelongsToManyDetailPanel, { field });
}
function BelongsToManyCountBadge({ count }) {
  return /* @__PURE__ */ jsxs(
    "span",
    {
      className: "martis-badge",
      style: {
        backgroundColor: "var(--martis-surface)",
        color: "var(--martis-text)",
        borderColor: "var(--martis-border)"
      },
      children: [
        /* @__PURE__ */ jsx(LinkSimpleIcon, { size: 11 }),
        count
      ]
    }
  );
}
function BelongsToManyDetailPanel({ field, readOnly = false, formValues }) {
  var _a, _b;
  const { t: tAct } = useTranslation("actions");
  const { t: tMsg } = useTranslation("messages");
  const qc = useQueryClient();
  const meta = field.belongsToManyMeta;
  const relationship = field.relationship;
  const relatedResource = field.relatedResource;
  const collapsable = !!field.collapsable;
  const collapsedByDefault = !!field.collapsedByDefault;
  const pivotFields = field.pivotFields ?? [];
  const searchable = !!field.searchable;
  const modalSize = field.modalSize ?? "2xl";
  const modalHeight = field.modalHeight ?? null;
  const withSubtitles = !!field.withSubtitles;
  const subtitleAttribute = field.subtitleAttribute ?? "subtitle";
  const { resource: parentResource, id: parentId } = useRelationParent();
  const [showAttachModal, setShowAttachModal] = useState(false);
  const [detachTarget, setDetachTarget] = useState(null);
  const [detachError, setDetachError] = useState(null);
  const [editTarget, setEditTarget] = useState(null);
  const [selectedRows, setSelectedRows] = useState([]);
  const [activePivotAction, setActivePivotAction] = useState(null);
  const [openPivotGroup, setOpenPivotGroup] = useState(null);
  const pivotGroupRefs = useRef({});
  useEffect(() => {
    setSelectedRows([]);
  }, [parentId, parentResource]);
  const pivotActionsUrl = apiPath`/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}/actions`;
  const pivotActionsQuery = useQuery({
    queryKey: ["pivot-actions", parentResource, parentId, relationship],
    queryFn: ({ signal }) => api.get(`${pivotActionsUrl}?context=detail`, signal),
    enabled: !readOnly && !!parentResource && !!parentId && !!relationship
  });
  const pivotActions = readOnly ? [] : ((_b = (_a = pivotActionsQuery.data) == null ? void 0 : _a.data) == null ? void 0 : _b.actions) ?? [];
  const hasPivotActions = pivotActions.length > 0;
  useEffect(() => {
    function handleClickOutside(e) {
      const openGroup = openPivotGroup === null ? null : pivotGroupRefs.current[openPivotGroup];
      if (openGroup && !openGroup.contains(e.target)) {
        setOpenPivotGroup(null);
      }
    }
    if (openPivotGroup !== null) {
      document.addEventListener("mousedown", handleClickOutside);
      return () => document.removeEventListener("mousedown", handleClickOutside);
    }
  }, [openPivotGroup]);
  useEscapeLayer(openPivotGroup !== null, () => setOpenPivotGroup(null));
  const detachMutation = useMutation({
    mutationFn: (relatedId) => api.delete(
      apiPath`/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}/${relatedId}/detach`
    ),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["belongs-to-many", parentResource, parentId, relationship] });
      void qc.invalidateQueries({ queryKey: ["btm-attachable", parentResource, parentId, relationship] });
      setDetachTarget(null);
      setDetachError(null);
    },
    onError: (e) => {
      setDetachError(e instanceof ApiError && e.message ? e.message : tMsg("error_detach", "The record could not be detached."));
    }
  });
  const pivotActionGroups = pivotActions.reduce((acc, action) => {
    const label = action.pivotLabel ?? tAct("actions", "Actions");
    if (!acc[label]) acc[label] = [];
    acc[label].push(action);
    return acc;
  }, {});
  const showAttachButton = !readOnly && !!(meta == null ? void 0 : meta.canAttach) && !(meta == null ? void 0 : meta.hideCreateButton);
  const { showEditPivot, showDetach, hasAny: showRowActionsExtras } = pivotRowActions({
    readOnly,
    pivotFieldsCount: pivotFields.length,
    canDetach: !!(meta == null ? void 0 : meta.canDetach),
    hideEditAction: !!(meta == null ? void 0 : meta.hideEditAction),
    hideDeleteAction: !!(meta == null ? void 0 : meta.hideDeleteAction)
  });
  return /* @__PURE__ */ jsxs(Fragment, { children: [
    /* @__PURE__ */ jsx(
      RelationshipTableShell,
      {
        title: field.label,
        relatedResource,
        collapsable,
        collapsedByDefault,
        queryKey: ["belongs-to-many", parentResource, parentId, relationship],
        fetchUrl: (params) => withQuery(apiPath`/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}`, params.toString()),
        viewUrl: (id) => recordHref(relatedResource, id),
        pivotFields,
        selectable: hasPivotActions,
        selectedRows,
        onSelectionChange: setSelectedRows,
        perPage: (meta == null ? void 0 : meta.perPage) ?? 10,
        perPageOptions: (meta == null ? void 0 : meta.perPageOptions) ?? [10, 25, 50],
        searchable,
        canCreate: false,
        canUpdate: false,
        canDelete: false,
        hideSearch: !!(meta == null ? void 0 : meta.hideSearch),
        hidePerPageSelector: !!(meta == null ? void 0 : meta.hidePerPageSelector),
        hideSoftDeleteToggle: !!(meta == null ? void 0 : meta.hideSoftDeleteToggle),
        hideRestoreAction: !!(meta == null ? void 0 : meta.hideRestoreAction),
        hideForceDeleteAction: !!(meta == null ? void 0 : meta.hideForceDeleteAction),
        hideViewAction: true,
        toolbarExtras: ({ selectedRows: selected }) => /* @__PURE__ */ jsxs(Fragment, { children: [
          hasPivotActions && Object.entries(pivotActionGroups).map(([label, actions]) => /* @__PURE__ */ jsxs("div", { className: "relative flex-shrink-0", ref: (el) => {
            pivotGroupRefs.current[label] = el;
          }, children: [
            /* @__PURE__ */ jsxs(
              "button",
              {
                type: "button",
                onClick: () => setOpenPivotGroup((open) => open === label ? null : label),
                className: "inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium flex-shrink-0",
                style: {
                  backgroundColor: selected.length > 0 ? "var(--martis-accent)" : "var(--martis-surface)",
                  color: selected.length > 0 ? "#fff" : "var(--martis-text)",
                  border: "1px solid var(--martis-border)",
                  cursor: "pointer"
                },
                children: [
                  /* @__PURE__ */ jsx(LightningIcon, { size: 14 }),
                  label,
                  selected.length > 0 && /* @__PURE__ */ jsx(
                    "span",
                    {
                      className: "inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-medium",
                      style: { backgroundColor: "rgba(255,255,255,0.25)", color: "#fff" },
                      children: selected.length
                    }
                  ),
                  /* @__PURE__ */ jsx(CaretDownIcon, { size: 12 })
                ]
              }
            ),
            openPivotGroup === label && /* @__PURE__ */ jsx(
              "div",
              {
                className: "absolute left-0 top-full z-50 mt-1 min-w-[180px] overflow-hidden rounded-lg shadow-lg",
                style: {
                  backgroundColor: "var(--martis-card)",
                  border: "1px solid var(--martis-border)"
                },
                children: actions.map((action) => /* @__PURE__ */ jsxs(
                  "button",
                  {
                    type: "button",
                    disabled: selected.length === 0 && !action.standalone,
                    onClick: () => {
                      setOpenPivotGroup(null);
                      setActivePivotAction(action);
                    },
                    className: "flex w-full items-center gap-2 px-4 py-2.5 text-sm transition-colors disabled:opacity-40 disabled:cursor-not-allowed",
                    style: {
                      color: action.destructive ? "var(--martis-danger)" : "var(--martis-text)",
                      background: "none",
                      border: "none",
                      cursor: selected.length === 0 && !action.standalone ? "not-allowed" : "pointer",
                      textAlign: "left"
                    },
                    onMouseEnter: (e) => {
                      if (selected.length > 0 || action.standalone)
                        e.currentTarget.style.backgroundColor = "var(--martis-surface)";
                    },
                    onMouseLeave: (e) => {
                      e.currentTarget.style.backgroundColor = "transparent";
                    },
                    children: [
                      /* @__PURE__ */ jsx(LightningIcon, { size: 14, style: { color: action.destructive ? "var(--martis-danger)" : "var(--martis-accent)" } }),
                      action.name
                    ]
                  },
                  action.uriKey
                ))
              }
            )
          ] }, label)),
          showAttachButton && /* @__PURE__ */ jsxs(
            "button",
            {
              type: "button",
              onClick: () => setShowAttachModal(true),
              className: "inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-martis-accent-contrast flex-shrink-0",
              style: { backgroundColor: "var(--martis-accent)" },
              children: [
                /* @__PURE__ */ jsx(PlusIcon, { size: 14, weight: "bold" }),
                tAct("attach", "Attach")
              ]
            }
          )
        ] }),
        rowActionsExtras: showRowActionsExtras ? (row) => /* @__PURE__ */ jsxs(Fragment, { children: [
          showEditPivot && /* @__PURE__ */ jsx(
            "button",
            {
              type: "button",
              onClick: () => setEditTarget({
                id: row.id,
                title: row._title,
                pivot: row._pivot ?? {}
              }),
              className: "rounded p-1.5 transition-colors",
              style: { color: "var(--martis-text-muted)", background: "none", border: "none", cursor: "pointer" },
              "data-pr-tooltip": tAct("edit", "Edit"),
              "data-pr-position": "top",
              onMouseEnter: (e) => e.currentTarget.style.color = "var(--martis-accent)",
              onMouseLeave: (e) => e.currentTarget.style.color = "var(--martis-text-muted)",
              children: /* @__PURE__ */ jsx(PencilSimpleIcon, { size: 16 })
            }
          ),
          showDetach && /* @__PURE__ */ jsxs(
            "button",
            {
              type: "button",
              onClick: () => setDetachTarget({ id: row.id, title: row._title }),
              className: "inline-flex items-center gap-1 rounded px-2 py-1 text-xs transition-colors",
              style: { color: "var(--martis-text-muted)", background: "none", border: "1px solid var(--martis-border)", cursor: "pointer" },
              "data-pr-tooltip": tAct("detach", "Detach"),
              "data-pr-position": "top",
              onMouseEnter: (e) => {
                e.currentTarget.style.color = "var(--martis-danger)";
                e.currentTarget.style.borderColor = "var(--martis-danger)";
              },
              onMouseLeave: (e) => {
                e.currentTarget.style.color = "var(--martis-text-muted)";
                e.currentTarget.style.borderColor = "var(--martis-border)";
              },
              children: [
                /* @__PURE__ */ jsx(LinkBreakIcon, { size: 14 }),
                tAct("detach", "Detach")
              ]
            }
          )
        ] }) : void 0
      }
    ),
    detachTarget && /* @__PURE__ */ jsx(
      DetachConfirmModal,
      {
        title: detachTarget.title ?? String(detachTarget.id),
        onConfirm: () => {
          setDetachError(null);
          detachMutation.mutate(detachTarget.id);
        },
        onCancel: () => {
          setDetachTarget(null);
          setDetachError(null);
        },
        loading: detachMutation.isPending,
        error: detachError
      }
    ),
    editTarget && /* @__PURE__ */ jsx(
      EditPivotModal,
      {
        title: editTarget.title ?? String(editTarget.id),
        endpoint: apiPath`/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}/${editTarget.id}/pivot`,
        pivotEndpoint: apiPath`/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}/pivot-fields/${editTarget.id}`,
        pivotFields,
        initialValues: editTarget.pivot,
        onSuccess: () => {
          setEditTarget(null);
          void qc.invalidateQueries({ queryKey: ["belongs-to-many", parentResource, parentId, relationship] });
        },
        onCancel: () => setEditTarget(null)
      }
    ),
    activePivotAction && /* @__PURE__ */ jsx(
      PivotActionModal,
      {
        actionsUrl: pivotActionsUrl,
        resourceKey: relatedResource,
        action: activePivotAction,
        selectedIds: selectedRows.map((r) => r.id),
        onSuccess: () => {
          setActivePivotAction(null);
          setSelectedRows([]);
          void qc.invalidateQueries({ queryKey: ["belongs-to-many", parentResource, parentId, relationship] });
        },
        onClose: () => setActivePivotAction(null)
      }
    ),
    showAttachModal && /* @__PURE__ */ jsx(
      AttachModal,
      {
        parentResource,
        parentId,
        relationship,
        relatedResource,
        pivotFields,
        modalSize,
        modalHeight,
        withSubtitles,
        subtitleAttribute,
        field,
        formValues,
        onSuccess: () => {
          setShowAttachModal(false);
          void qc.invalidateQueries({ queryKey: ["belongs-to-many", parentResource, parentId, relationship] });
        },
        onClose: () => setShowAttachModal(false)
      }
    )
  ] });
}
function DetachConfirmModal({
  title,
  onConfirm,
  onCancel,
  loading,
  error
}) {
  const { t: tAct } = useTranslation("actions");
  const { t: tMsg } = useTranslation("messages");
  useModalHistoryLock(true);
  return createPortal(/* @__PURE__ */ jsx(
    "div",
    {
      className: "martis-modal-scrim",
      onClick: onCancel,
      children: /* @__PURE__ */ jsxs(
        "div",
        {
          role: "dialog",
          "aria-modal": "true",
          className: "martis-modal-surface",
          onClick: (e) => e.stopPropagation(),
          children: [
            /* @__PURE__ */ jsxs("div", { className: "martis-modal-head", children: [
              /* @__PURE__ */ jsxs("div", { className: "flex items-center gap-3", children: [
                /* @__PURE__ */ jsx(LinkBreakIcon, { size: 18, weight: "bold", style: { color: "var(--martis-danger)" } }),
                /* @__PURE__ */ jsxs("h3", { className: "martis-modal-head-title", children: [
                  tAct("detach", "Detach"),
                  " ",
                  title ? `"${title}"` : ""
                ] })
              ] }),
              /* @__PURE__ */ jsx(
                "button",
                {
                  type: "button",
                  onClick: onCancel,
                  className: "martis-modal-close",
                  "aria-label": tAct("cancel", "Cancel"),
                  children: /* @__PURE__ */ jsx(XIcon, { size: 16 })
                }
              )
            ] }),
            /* @__PURE__ */ jsxs("div", { className: "martis-modal-body", children: [
              tMsg("detach_confirm", "This record will be detached from the relationship. No data will be deleted. Continue?"),
              error && /* @__PURE__ */ jsx("p", { role: "alert", className: "mt-3 text-sm", style: { color: "var(--martis-danger)" }, children: error })
            ] }),
            /* @__PURE__ */ jsxs("div", { className: "martis-modal-foot", children: [
              /* @__PURE__ */ jsxs("button", { type: "button", onClick: onCancel, disabled: loading, className: "martis-btn-secondary", children: [
                /* @__PURE__ */ jsx(XIcon, { size: 14 }),
                tAct("cancel", "Cancel")
              ] }),
              /* @__PURE__ */ jsxs(
                "button",
                {
                  type: "button",
                  disabled: loading,
                  onClick: onConfirm,
                  className: "martis-btn-danger",
                  children: [
                    /* @__PURE__ */ jsx(LinkBreakIcon, { size: 14 }),
                    loading ? tAct("please_wait", "Please wait…") : tAct("detach", "Detach")
                  ]
                }
              )
            ] })
          ]
        }
      )
    }
  ), document.body);
}
function useDebounce(value, delay) {
  const [debouncedValue, setDebouncedValue] = useState(value);
  const timeoutRef = useRef(null);
  useEffect(() => {
    timeoutRef.current = setTimeout(() => setDebouncedValue(value), delay);
    return () => {
      if (timeoutRef.current) clearTimeout(timeoutRef.current);
    };
  }, [value, delay]);
  return debouncedValue;
}
function AttachModal({
  parentResource,
  parentId,
  relationship,
  relatedResource,
  pivotFields,
  modalSize = "2xl",
  modalHeight,
  withSubtitles = false,
  subtitleAttribute = "subtitle",
  field,
  formValues,
  onSuccess,
  onClose
}) {
  var _a, _b, _c, _d, _e;
  const { t: tAct } = useTranslation("actions");
  const { t: tMsg } = useTranslation("messages");
  const { t: tRes } = useTranslation("resources");
  useModalHistoryLock(true);
  const [search, setSearch] = useState("");
  const debouncedSearch = useDebounce(search, 300);
  const [selected, setSelected] = useState([]);
  const [pivotValues, setPivotValues] = useState(() => {
    const defaults = {};
    for (const pf of pivotFields) {
      if (pf.defaultValue != null) defaults[pf.attribute] = pf.defaultValue;
    }
    return defaults;
  });
  const [error, setError] = useState(null);
  const [fieldErrors, setFieldErrors] = useState({});
  const [attachPage, setAttachPage] = useState(1);
  const [attachPerPage, setAttachPerPage] = useState(15);
  const perPageOptions = [10, 15, 25, 50];
  const schemaQuery = useQuery({
    queryKey: ["schema", relatedResource],
    queryFn: ({ signal }) => api.get(apiPath`/api/resources/${relatedResource}/schema`, signal),
    enabled: !!relatedResource
  });
  const { snapshot: dependentFormSnapshot, appendFormDraft } = useAttachFormDraft(field, formValues);
  const attachableQuery = useQuery({
    queryKey: ["btm-attachable", parentResource, parentId, relationship, debouncedSearch, attachPage, attachPerPage, dependentFormSnapshot],
    queryFn: ({ signal }) => {
      const params = new URLSearchParams({ per_page: String(attachPerPage), page: String(attachPage) });
      if (debouncedSearch) params.set("search", debouncedSearch);
      appendFormDraft(params);
      return api.get(
        withQuery(apiPath`/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}/attachable`, params.toString()),
        signal
      );
    },
    enabled: !!parentResource && !!parentId && !!relationship
  });
  const attachMutation = useMutation({
    mutationFn: (payload) => api.post(
      withFormDraft(apiPath`/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}/attach`, appendFormDraft),
      payload
    ),
    onSuccess: () => {
      onSuccess();
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        const byField = e.errorsByField();
        setFieldErrors(byField);
        setError(Object.keys(byField).length === 0 ? e.message : null);
      } else {
        setFieldErrors({});
        setError((e == null ? void 0 : e.message) ?? "Failed to attach.");
      }
    }
  });
  const records = ((_a = attachableQuery.data) == null ? void 0 : _a.data) ?? [];
  const pagination = (_b = attachableQuery.data) == null ? void 0 : _b.meta;
  const attachHidden = useHiddenAttributes({
    _hidden: (_d = (_c = attachableQuery.data) == null ? void 0 : _c.meta) == null ? void 0 : _d.hiddenPivotFields
  });
  const shownPivotFields = useMemo(() => withoutHiddenFields(pivotFields, attachHidden), [pivotFields, attachHidden]);
  const schema = (_e = schemaQuery.data) == null ? void 0 : _e.data;
  const indexFields = (schema == null ? void 0 : schema.fieldsForIndex) ?? [];
  function handleAttach() {
    if (selected.length === 0) return;
    setError(null);
    setFieldErrors({});
    if (selected.length === 1) {
      const payload = { related_id: selected[0].id, ...pivotValues };
      attachMutation.mutate(payload);
    } else {
      const payload = { related_ids: selected.map((s) => s.id), ...pivotValues };
      attachMutation.mutate(payload);
    }
  }
  const modalMaxWidth = MODAL_SIZE_MAP[modalSize] ?? MODAL_SIZE_MAP["2xl"];
  return createPortal(/* @__PURE__ */ jsx(
    "div",
    {
      className: "martis-modal-scrim",
      onClick: onClose,
      children: /* @__PURE__ */ jsxs(
        "div",
        {
          role: "dialog",
          "aria-modal": "true",
          className: "martis-modal-surface",
          style: { maxWidth: modalMaxWidth, maxHeight: modalHeight ?? "85vh" },
          onClick: (e) => e.stopPropagation(),
          children: [
            /* @__PURE__ */ jsxs("div", { className: "martis-modal-head", children: [
              /* @__PURE__ */ jsxs("div", { className: "flex items-center gap-2", children: [
                /* @__PURE__ */ jsx("h3", { className: "martis-modal-head-title", children: tAct("attach_related", "Attach Record") }),
                selected.length > 0 && /* @__PURE__ */ jsx(
                  "span",
                  {
                    className: "martis-badge",
                    style: { backgroundColor: "var(--martis-accent)", color: "var(--martis-accent-contrast, #ffffff)", borderColor: "transparent" },
                    children: selected.length
                  }
                )
              ] }),
              /* @__PURE__ */ jsx(
                "button",
                {
                  type: "button",
                  onClick: onClose,
                  className: "martis-modal-close",
                  "aria-label": tAct("cancel", "Cancel"),
                  children: /* @__PURE__ */ jsx(XIcon, { size: 16 })
                }
              )
            ] }),
            /* @__PURE__ */ jsx("div", { className: "shrink-0 border-0 border-b border-solid px-6 py-3", style: { borderColor: "var(--martis-border)" }, children: /* @__PURE__ */ jsxs("div", { className: "flex items-center gap-3", children: [
              /* @__PURE__ */ jsxs("div", { className: "relative flex-1", children: [
                /* @__PURE__ */ jsx("span", { className: "absolute inset-y-0 left-3 flex items-center pointer-events-none", children: /* @__PURE__ */ jsx(MagnifyingGlassIcon, { size: 14, style: { color: "var(--martis-text-muted)" } }) }),
                /* @__PURE__ */ jsx(
                  "input",
                  {
                    type: "text",
                    value: search,
                    onChange: (e) => {
                      setSearch(e.target.value);
                      setAttachPage(1);
                    },
                    placeholder: tMsg("search", "Search…"),
                    className: "martis-resource-search block w-full rounded-md py-2 pl-9 pr-8 text-sm focus:outline-none focus:ring-1",
                    style: {
                      backgroundColor: "var(--martis-input-bg)",
                      border: "1px solid var(--martis-border)",
                      color: "var(--martis-text)"
                    },
                    autoFocus: true
                  }
                ),
                search && /* @__PURE__ */ jsx(
                  "button",
                  {
                    type: "button",
                    onClick: () => {
                      setSearch("");
                      setAttachPage(1);
                    },
                    className: "absolute inset-y-0 right-2 flex items-center",
                    style: { cursor: "pointer", background: "none", border: "none" },
                    "data-pr-tooltip": tMsg("clear", "Clear"),
                    "data-pr-position": "top",
                    children: /* @__PURE__ */ jsx(XIcon, { size: 14, weight: "bold", style: { color: "var(--martis-danger)" } })
                  }
                )
              ] }),
              /* @__PURE__ */ jsxs("div", { className: "flex items-center gap-2 flex-shrink-0", children: [
                /* @__PURE__ */ jsxs("label", { className: "text-xs martis-text-muted whitespace-nowrap", children: [
                  tRes("per_page", "Per page"),
                  ":"
                ] }),
                /* @__PURE__ */ jsx(
                  "select",
                  {
                    value: attachPerPage,
                    onChange: (e) => {
                      setAttachPerPage(Number(e.target.value));
                      setAttachPage(1);
                    },
                    className: "martis-perpage-select",
                    children: perPageOptions.map((opt) => /* @__PURE__ */ jsx("option", { value: opt, children: opt }, opt))
                  }
                )
              ] })
            ] }) }),
            /* @__PURE__ */ jsx("div", { className: "min-h-0 flex-1 overflow-auto px-6", children: /* @__PURE__ */ jsxs(
              DataTable,
              {
                value: records,
                loading: attachableQuery.isLoading,
                dataKey: "id",
                selectionMode: "multiple",
                selection: selected,
                onSelectionChange: (e) => setSelected(e.value),
                emptyMessage: /* @__PURE__ */ jsx("div", { className: "py-8 text-center text-sm", style: { color: "var(--martis-text-muted)" }, children: tMsg("no_records_available", "No records available.") }),
                className: "w-full martis-datatable martis-datatable-striped",
                tableClassName: "min-w-full",
                children: [
                  /* @__PURE__ */ jsx(Column, { selectionMode: "multiple", headerStyle: { width: "3rem" } }),
                  indexFields.map((f, idx) => /* @__PURE__ */ jsx(
                    Column,
                    {
                      field: f.attribute,
                      header: /* @__PURE__ */ jsx("span", { className: "text-xs font-medium uppercase tracking-wider text-gray-500", children: f.label }),
                      body: (row) => /* @__PURE__ */ jsxs("div", { children: [
                        /* @__PURE__ */ jsx(FieldDisplay, { field: f, value: row[f.attribute], resourceKey: relatedResource }),
                        withSubtitles && idx === 0 && row[subtitleAttribute] != null && /* @__PURE__ */ jsx("div", { className: "text-xs mt-0.5", style: { color: "var(--martis-text-muted)" }, children: String(row[subtitleAttribute]) })
                      ] })
                    },
                    f.attribute
                  ))
                ]
              }
            ) }),
            pagination && /* @__PURE__ */ jsx("div", { className: "shrink-0 px-6 py-2", children: /* @__PURE__ */ jsx(
              Pagination,
              {
                currentPage: pagination.current_page,
                lastPage: pagination.last_page,
                total: pagination.total,
                perPage: pagination.per_page ?? attachPerPage,
                from: pagination.from,
                to: pagination.to,
                onPageChange: setAttachPage
              }
            ) }),
            shownPivotFields.length > 0 && selected.length > 0 && /* @__PURE__ */ jsxs(
              "div",
              {
                className: "shrink-0 space-y-4 border-0 border-t border-solid px-6 py-4",
                style: { borderColor: "var(--martis-border)" },
                children: [
                  /* @__PURE__ */ jsx("p", { className: "text-xs font-medium uppercase tracking-wider", style: { color: "var(--martis-text-muted)" }, children: tAct("pivot_fields", "Pivot Fields") }),
                  shownPivotFields.map((pf) => {
                    const isRequired = !!pf.required;
                    const fieldError = fieldErrors[pf.attribute];
                    return /* @__PURE__ */ jsxs("div", { children: [
                      /* @__PURE__ */ jsxs("label", { className: "mb-1 block text-sm font-medium", style: { color: "var(--martis-text)" }, children: [
                        pf.label,
                        isRequired && /* @__PURE__ */ jsx("span", { className: "ml-1", style: { color: "var(--martis-danger)" }, children: "*" })
                      ] }),
                      /* @__PURE__ */ jsx(
                        FieldInput,
                        {
                          field: pf,
                          value: pivotValues[pf.attribute] ?? null,
                          onChange: (v) => setPivotValues((prev) => ({ ...prev, [pf.attribute]: v })),
                          context: "create",
                          pivotEndpoint: apiPath`/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}/pivot-fields`,
                          nestedErrors: nestedErrorsOf(fieldErrors, pf.attribute)
                        }
                      ),
                      fieldError && /* @__PURE__ */ jsx("p", { className: "mt-1 text-xs", style: { color: "var(--martis-danger)" }, children: fieldError })
                    ] }, pf.attribute);
                  })
                ]
              }
            ),
            error && /* @__PURE__ */ jsx(
              "div",
              {
                className: "shrink-0 mx-6 mb-3 rounded-lg px-4 py-3 text-sm",
                style: {
                  border: "1px solid color-mix(in srgb, var(--martis-danger) 30%, transparent)",
                  backgroundColor: "color-mix(in srgb, var(--martis-danger) 10%, transparent)",
                  color: "var(--martis-danger)"
                },
                children: error
              }
            ),
            /* @__PURE__ */ jsxs("div", { className: "martis-modal-foot", children: [
              /* @__PURE__ */ jsxs("button", { type: "button", onClick: onClose, className: "martis-btn-secondary", children: [
                /* @__PURE__ */ jsx(XIcon, { size: 14 }),
                tAct("cancel", "Cancel")
              ] }),
              /* @__PURE__ */ jsxs(
                "button",
                {
                  type: "button",
                  disabled: selected.length === 0 || attachMutation.isPending,
                  onClick: handleAttach,
                  className: "martis-btn-primary",
                  children: [
                    /* @__PURE__ */ jsx(LinkSimpleIcon, { size: 14 }),
                    attachMutation.isPending ? tAct("please_wait", "Please wait…") : selected.length > 1 ? `${tAct("attach", "Attach")} (${selected.length})` : tAct("attach", "Attach")
                  ]
                }
              )
            ] })
          ]
        }
      )
    }
  ), document.body);
}
function BelongsToManyFieldInput({ field, formValues }) {
  const { id: parentId } = useRelationParent();
  if (!parentId) return null;
  return /* @__PURE__ */ jsx(BelongsToManyDetailPanel, { field, formValues });
}
function EditPivotModal({
  title,
  endpoint,
  pivotEndpoint,
  pivotFields,
  initialValues,
  onSuccess,
  onCancel
}) {
  const { t: tAct } = useTranslation("actions");
  const hidden = useHiddenAttributes(initialValues);
  const shownFields = useMemo(() => withoutHiddenFields(pivotFields, hidden), [pivotFields, hidden]);
  const fields = useMemo(() => lockImmutableFields(shownFields), [shownFields]);
  useModalHistoryLock(true);
  const [values, setValues] = useState(() => {
    const seeded = {};
    for (const pf of shownFields) {
      seeded[pf.attribute] = initialValues[pf.attribute] ?? null;
    }
    return seeded;
  });
  const [error, setError] = useState(null);
  const [fieldErrors, setFieldErrors] = useState({});
  const updateMutation = useMutation({
    mutationFn: (payload) => api.put(endpoint, payload),
    onSuccess: () => {
      onSuccess();
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        const byField = e.errorsByField();
        setFieldErrors(byField);
        setError(Object.keys(byField).length === 0 ? e.message : null);
      } else {
        setFieldErrors({});
        setError((e == null ? void 0 : e.message) ?? "Failed to update.");
      }
    }
  });
  function handleSave() {
    setError(null);
    setFieldErrors({});
    updateMutation.mutate(values);
  }
  return createPortal(/* @__PURE__ */ jsx(
    "div",
    {
      className: "martis-modal-scrim",
      onClick: onCancel,
      children: /* @__PURE__ */ jsxs(
        "div",
        {
          role: "dialog",
          "aria-modal": "true",
          className: "martis-modal-surface",
          onClick: (e) => e.stopPropagation(),
          children: [
            /* @__PURE__ */ jsxs("div", { className: "martis-modal-head", children: [
              /* @__PURE__ */ jsxs("div", { className: "flex items-center gap-3", children: [
                /* @__PURE__ */ jsx(PencilSimpleIcon, { size: 18, weight: "bold", style: { color: "var(--martis-accent)" } }),
                /* @__PURE__ */ jsxs("h3", { className: "martis-modal-head-title", children: [
                  tAct("edit", "Edit"),
                  " ",
                  title ? `"${title}"` : ""
                ] })
              ] }),
              /* @__PURE__ */ jsx(
                "button",
                {
                  type: "button",
                  onClick: onCancel,
                  className: "martis-modal-close",
                  "aria-label": tAct("cancel", "Cancel"),
                  children: /* @__PURE__ */ jsx(XIcon, { size: 16 })
                }
              )
            ] }),
            /* @__PURE__ */ jsxs("div", { className: "martis-modal-body space-y-4", children: [
              fields.map((pf) => {
                const isRequired = !!pf.required;
                const fieldError = fieldErrors[pf.attribute];
                return /* @__PURE__ */ jsxs("div", { children: [
                  /* @__PURE__ */ jsxs("label", { className: "mb-1 block text-sm font-medium", style: { color: "var(--martis-text)" }, children: [
                    pf.label,
                    isRequired && /* @__PURE__ */ jsx("span", { className: "ml-1", style: { color: "var(--martis-danger)" }, children: "*" })
                  ] }),
                  /* @__PURE__ */ jsx(
                    FieldInput,
                    {
                      field: pf,
                      value: values[pf.attribute] ?? null,
                      onChange: (v) => setValues((prev) => ({ ...prev, [pf.attribute]: v })),
                      context: "update",
                      pivotEndpoint,
                      nestedErrors: nestedErrorsOf(fieldErrors, pf.attribute)
                    }
                  ),
                  fieldError && /* @__PURE__ */ jsx("p", { className: "mt-1 text-xs", style: { color: "var(--martis-danger)" }, children: fieldError })
                ] }, pf.attribute);
              }),
              error && /* @__PURE__ */ jsx(
                "div",
                {
                  className: "rounded-lg px-4 py-3 text-sm",
                  style: {
                    border: "1px solid color-mix(in srgb, var(--martis-danger) 30%, transparent)",
                    backgroundColor: "color-mix(in srgb, var(--martis-danger) 10%, transparent)",
                    color: "var(--martis-danger)"
                  },
                  children: error
                }
              )
            ] }),
            /* @__PURE__ */ jsxs("div", { className: "martis-modal-foot", children: [
              /* @__PURE__ */ jsxs("button", { type: "button", onClick: onCancel, disabled: updateMutation.isPending, className: "martis-btn-secondary", children: [
                /* @__PURE__ */ jsx(XIcon, { size: 14 }),
                tAct("cancel", "Cancel")
              ] }),
              /* @__PURE__ */ jsxs(
                "button",
                {
                  type: "button",
                  disabled: updateMutation.isPending,
                  onClick: handleSave,
                  className: "martis-btn-primary",
                  children: [
                    /* @__PURE__ */ jsx(FloppyDiskIcon, { size: 14 }),
                    updateMutation.isPending ? tAct("please_wait", "Please wait…") : tAct("save", "Save")
                  ]
                }
              )
            ] })
          ]
        }
      )
    }
  ), document.body);
}
const BelongsToManyField = /* @__PURE__ */ Object.freeze(/* @__PURE__ */ Object.defineProperty({
  __proto__: null,
  BelongsToManyFieldDisplay,
  BelongsToManyFieldInput,
  EditPivotModal
}, Symbol.toStringTag, { value: "Module" }));
export {
  BelongsToManyField as B,
  EditPivotModal as E,
  PivotActionModal as P,
  pivotRowActions as p,
  useAttachFormDraft as u,
  withFormDraft as w
};
