<?php

namespace Martis\Fields\Concerns;

/**
 * Per-instance visibility overrides for relationship-field controls.
 *
 * Nova 5 gates relationship controls exclusively through policies and
 * resource authorization. Martis keeps that exact semantics — policies
 * remain the source of truth — and adds this trait so a programmer can
 * **hide** individual controls on a specific field instance *even when*
 * the authorization would otherwise allow them. There is no inverse
 * operation: you cannot force a control to appear if policy denies it.
 *
 * Each toggle defaults to `false` (= honour policy as Nova does). Setting
 * a toggle to `true` removes the control from the rendered UI. For action
 * buttons (View/Edit/Delete/Restore/ForceDelete), unauthorized actions
 * continue to render as *disabled* (greyed-out icons); this trait
 * removes them entirely from the DOM.
 *
 * ## Scope of applicability per field
 *
 * The trait exposes the full set of 9 toggles so every relationship
 * field has the same developer-facing API. Each field serialises only
 * the subset that is meaningful for its rendering, via
 * `relationshipToolbarControls()`.
 *
 * | Field            | Toolbar                                | Actions                                    |
 * |------------------|----------------------------------------|--------------------------------------------|
 * | HasMany / Through| search, create, per-page, trashed      | view, edit, delete, restore, forceDelete   |
 * | MorphMany        | search, create, per-page, trashed      | view, edit, delete, restore, forceDelete   |
 * | BelongsToMany    | search, create (→ attach), per-page    | view, edit (pivot), delete (→ detach)      |
 * | MorphToMany      | search, create (→ attach), per-page    | view, edit (pivot), delete (→ detach)      |
 * | HasOne / variants| —                                      | view, edit, delete                         |
 * | MorphOne / variants| —                                    | view, edit, delete                         |
 * | BelongsTo        | create (inline)                        | view (peek/link)                           |
 * | MorphTo          | create (inline)                        | view (peek/link)                           |
 */
trait ControlsRelationshipToolbar
{
    // ── Toolbar ──────────────────────────────────────────────────────────

    protected bool $hideSearch = false;

    protected bool $hideCreateButton = false;

    protected bool $hidePerPageSelector = false;

    protected bool $hideSoftDeleteToggle = false;

    // ── Actions ──────────────────────────────────────────────────────────

    protected bool $hideViewAction = false;

    protected bool $hideEditAction = false;

    protected bool $hideDeleteAction = false;

    protected bool $hideRestoreAction = false;

    protected bool $hideForceDeleteAction = false;

    /**
     * Hide the search input in the toolbar even when the related resource
     * has searchable fields.
     */
    public function hideSearch(bool $hide = true): static
    {
        $this->hideSearch = $hide;

        return $this;
    }

    /**
     * Hide the "Create" button (or "Attach" on many-to-many) even when the
     * current user's policy authorises creation.
     */
    public function hideCreateButton(bool $hide = true): static
    {
        $this->hideCreateButton = $hide;

        return $this;
    }

    /**
     * Hide the per-page selector in the toolbar even when per-page options
     * are configured. The effective page size remains whatever the field
     * is configured with; users just cannot change it.
     */
    public function hidePerPageSelector(bool $hide = true): static
    {
        $this->hidePerPageSelector = $hide;

        return $this;
    }

    /**
     * Hide the "trashed filter" dropdown in the toolbar even when the
     * related resource supports soft-deletes. Does not change which
     * records are listed — that is controlled by the filter state / the
     * global `config/martis.php` `default_trashed_filter` key.
     */
    public function hideSoftDeleteToggle(bool $hide = true): static
    {
        $this->hideSoftDeleteToggle = $hide;

        return $this;
    }

    /**
     * Hide the row-level View action (eye icon) even when the user has
     * view authorization for that row.
     */
    public function hideViewAction(bool $hide = true): static
    {
        $this->hideViewAction = $hide;

        return $this;
    }

    /**
     * Hide the row-level Edit action (pencil icon) even when the user has
     * update authorization for that row.
     */
    public function hideEditAction(bool $hide = true): static
    {
        $this->hideEditAction = $hide;

        return $this;
    }

    /**
     * Hide the row-level Delete action (trash icon) even when the user has
     * delete authorization for that row. For many-to-many fields this also
     * hides the Detach variant of the same button.
     */
    public function hideDeleteAction(bool $hide = true): static
    {
        $this->hideDeleteAction = $hide;

        return $this;
    }

    /**
     * Hide the row-level Restore action (shown on soft-deleted rows) even
     * when the user has restore authorization for that row.
     */
    public function hideRestoreAction(bool $hide = true): static
    {
        $this->hideRestoreAction = $hide;

        return $this;
    }

    /**
     * Hide the row-level Force-Delete action (permanent delete, shown on
     * soft-deleted rows) even when the user has force-delete authorization
     * for that row.
     */
    public function hideForceDeleteAction(bool $hide = true): static
    {
        $this->hideForceDeleteAction = $hide;

        return $this;
    }

    /**
     * Return all 9 hide flags as an associative array ready to merge into
     * the serialized meta payload of any relationship field. Callers may
     * take a subset if a given field only renders part of the surface.
     *
     * @return array<string, bool>
     */
    public function relationshipToolbarControls(): array
    {
        return [
            'hideSearch' => $this->hideSearch,
            'hideCreateButton' => $this->hideCreateButton,
            'hidePerPageSelector' => $this->hidePerPageSelector,
            'hideSoftDeleteToggle' => $this->hideSoftDeleteToggle,
            'hideViewAction' => $this->hideViewAction,
            'hideEditAction' => $this->hideEditAction,
            'hideDeleteAction' => $this->hideDeleteAction,
            'hideRestoreAction' => $this->hideRestoreAction,
            'hideForceDeleteAction' => $this->hideForceDeleteAction,
        ];
    }
}
