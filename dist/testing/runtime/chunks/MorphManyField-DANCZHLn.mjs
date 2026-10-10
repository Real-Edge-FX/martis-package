import { jsx, jsxs } from "react/jsx-runtime";
import { R as ResourceIcon, f as useRelationParent, r as recordHref, g as routePath, h as apiPath, w as withQuery } from "./index-Bmz1EqYY.mjs";
import { R as RelationshipTableShell } from "./RelationshipTableShell-DET4Oh7D.mjs";
function MorphManyFieldDisplay({ field, value }) {
  if (typeof value === "number") {
    return /* @__PURE__ */ jsx(MorphManyFieldIndexDisplay, { field, value });
  }
  return /* @__PURE__ */ jsx(MorphManyDetailTable, { field });
}
function MorphManyFieldIndexDisplay({ field, value }) {
  const count = typeof value === "number" ? value : 0;
  const badgeColor = field.badgeColor;
  const badgeIcon = field.badgeIcon;
  return /* @__PURE__ */ jsxs(
    "span",
    {
      className: "martis-badge",
      style: {
        backgroundColor: badgeColor ? `${badgeColor}15` : "var(--martis-surface)",
        color: badgeColor ?? "var(--martis-text)",
        borderColor: badgeColor ? `${badgeColor}40` : "var(--martis-border)"
      },
      children: [
        badgeIcon && /* @__PURE__ */ jsx(ResourceIcon, { iconName: badgeIcon, size: 12 }),
        count
      ]
    }
  );
}
function MorphManyDetailTable({ field }) {
  const meta = field.morphManyMeta;
  const relationship = field.relationship;
  const relatedResource = field.relatedResource;
  const redirectAfterSave = field.redirectAfterSave ?? "parent";
  const { resource: parentResource, id: parentId } = useRelationParent();
  const viaBaseParams = `viaResource=${encodeURIComponent(parentResource)}&viaResourceId=${encodeURIComponent(String(parentId))}&viaRelationship=${encodeURIComponent(relationship)}`;
  const viaParams = `?${viaBaseParams}&redirectMode=${encodeURIComponent(redirectAfterSave)}`;
  return /* @__PURE__ */ jsx(
    RelationshipTableShell,
    {
      title: field.label,
      relatedResource,
      showRelationIcon: field.showRelationIcon !== false,
      showRelationCount: field.showRelationCount !== false,
      collapsable: !!field.collapsable,
      collapsedByDefault: !!field.collapsedByDefault,
      queryKey: ["morph-many", parentResource, parentId, relationship],
      fetchUrl: (params) => withQuery(apiPath`/api/resources/${parentResource}/${parentId}/morph-many/${relationship}`, params.toString()),
      deleteUrl: (relatedId) => apiPath`/api/resources/${parentResource}/${parentId}/morph-many/${relationship}/${relatedId}`,
      createUrl: `${routePath`/resources/${relatedResource}/create`}${viaParams}`,
      editUrl: (id) => `${routePath`/resources/${relatedResource}/${id}/edit`}${viaParams}`,
      viewUrl: (id) => recordHref(relatedResource, id),
      perPage: (meta == null ? void 0 : meta.perPage) ?? 10,
      perPageOptions: (meta == null ? void 0 : meta.perPageOptions) ?? [10, 25, 50],
      searchable: !!(meta == null ? void 0 : meta.searchable),
      canCreate: !!(meta == null ? void 0 : meta.canCreate),
      canUpdate: !!(meta == null ? void 0 : meta.canUpdate),
      canDelete: !!(meta == null ? void 0 : meta.canDelete),
      hideSearch: !!(meta == null ? void 0 : meta.hideSearch),
      hideCreateButton: !!(meta == null ? void 0 : meta.hideCreateButton),
      hidePerPageSelector: !!(meta == null ? void 0 : meta.hidePerPageSelector),
      hideSoftDeleteToggle: !!(meta == null ? void 0 : meta.hideSoftDeleteToggle),
      hideViewAction: !!(meta == null ? void 0 : meta.hideViewAction),
      hideEditAction: !!(meta == null ? void 0 : meta.hideEditAction),
      hideDeleteAction: !!(meta == null ? void 0 : meta.hideDeleteAction),
      hideRestoreAction: !!(meta == null ? void 0 : meta.hideRestoreAction),
      hideForceDeleteAction: !!(meta == null ? void 0 : meta.hideForceDeleteAction),
      rowActions: { viaResource: parentResource, viaResourceId: parentId, viaRelationship: relationship }
    }
  );
}
function MorphManyFieldInput(_props) {
  return null;
}
export {
  MorphManyFieldDisplay,
  MorphManyFieldIndexDisplay,
  MorphManyFieldInput
};
