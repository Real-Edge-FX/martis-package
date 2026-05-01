<?php

namespace Martis\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Enums\ErrorDisplayMode;
use Martis\Enums\SortDirection;
use Martis\Enums\TableLayout;
use Martis\Enums\TableSize;
use Martis\Menu\MenuItem;

/**
 * Contract for all Martis Resource classes.
 *
 * A Resource is the central abstraction in Martis — it wraps an Eloquent model
 * and declares its Fields, authorization rules, and display metadata.
 *
 * This contract must mirror every public method on the base Resource class 1:1.
 * Any method added to Resource.php MUST be added here as well.
 */
interface ResourceContract
{
    // -------------------------------------------------------------------------
    // Core identity
    // -------------------------------------------------------------------------

    /**
     * Return the fields that belong to this resource.
     *
     * @return list<FieldContract>
     */
    public function fields(Request $request): array;

    /** Return the Eloquent model class name associated with this resource. */
    public static function model(): string;

    /** Return a fresh (unsaved) instance of the associated model. */
    public static function newModel(): Model;

    /** Return the URL key used in route segments (e.g. "posts", "users"). */
    public static function uriKey(): string;

    /** Return the plural human-readable label (e.g. "Blog Posts"). */
    public static function label(): string;

    /** Return the singular human-readable label (e.g. "Blog Post"). */
    public static function singularLabel(): string;

    /** Return an optional subtitle shown below the label on index pages. */
    public static function subtitle(): ?string;

    /** Return the model attribute used as the display title (e.g. "name"). */
    public static function titleAttribute(): string;

    /** Return the display title for a specific model instance. */
    public function title(): string;

    /** Return the Phosphor icon name for this resource (e.g. "newspaper"). */
    public function icon(): string;

    /** Return the icon color (hex or CSS class) for this resource, or null to use the theme primary color. */
    public function iconColor(): ?string;

    /** Return the navigation group this resource belongs to, or null for default. */
    public function group(): ?string;

    /**
     * When true, the navigation builder lifts this resource out of the
     * normal "Resources" section and into the System section alongside
     * the Cache admin link. Use for admin-only infrastructure surfaces
     * (audit log, roles, permissions, users). Default: false.
     */
    public function belongsToSystemSection(): bool;

    /** Build the default menu item for this resource. */
    public function menuItem(Request $request): MenuItem;

    /** Return the underlying Eloquent model, if set. */
    public function getModel(): ?Model;

    // -------------------------------------------------------------------------
    // Context-aware field resolution
    //
    // Each context method is an explicit override point. If not overridden, every
    // context falls back — ultimately — to fields(). See Resource.php for the
    // full resolution chain.
    // -------------------------------------------------------------------------

    /**
     * Return fields for the index (list) context.
     * Override to show different columns on the listing page.
     * Falls back to fields() when not overridden.
     *
     * @return list<FieldContract>
     */
    public function fieldsForIndex(Request $request): array;

    /**
     * Return fields for the detail (show) context.
     * Override to show different fields on the record detail page.
     * Falls back to fields() when not overridden.
     *
     * @return list<FieldContract>
     */
    public function fieldsForDetail(Request $request): array;

    /**
     * Return fields for the create form context.
     * Override to show different fields when creating a record.
     * Falls back to fields() when not overridden.
     *
     * @return list<FieldContract>
     */
    public function fieldsForCreate(Request $request): array;

    /**
     * Return fields for the update form context.
     * Override to show different fields when editing a record.
     * Falls back to fields() when not overridden.
     *
     * @return list<FieldContract>
     */
    public function fieldsForUpdate(Request $request): array;

    /**
     * Return fields for the inline-create context.
     * Falls back to fieldsForCreate(), which itself falls back to fields().
     *
     * @return list<FieldContract>
     */
    public function fieldsForInlineCreate(Request $request): array;

    /**
     * Return fields for the preview context.
     * Override to show a lightweight field set in preview panels.
     * Falls back to fields() when not overridden.
     *
     * @return list<FieldContract>
     */
    public function fieldsForPreview(Request $request): array;

    // -------------------------------------------------------------------------
    // Schema foundation
    // -------------------------------------------------------------------------

    /**
     * Return the filter descriptors exposed by this resource.
     *
     * @return list<FilterContract|array<string, mixed>>
     */
    public function filters(Request $request): array;

    /**
     * Return the lens descriptors exposed by this resource.
     *
     * @return list<LensContract|array<string, mixed>>
     */
    public function lenses(Request $request): array;

    /**
     * Return the card descriptors exposed by this resource.
     *
     * @return list<CardContract|array<string, mixed>>
     */
    public function cards(Request $request): array;

    /**
     * Return the dashboard descriptors exposed by this resource.
     *
     * @return list<DashboardContract|array<string, mixed>>
     */
    public static function dashboards(): array;

    // -------------------------------------------------------------------------
    // Query hooks
    // -------------------------------------------------------------------------

    /**
     * Build a query for the resource index listing.
     *
     * Override to add tenant scoping, ownership filtering, or any structural
     * query constraint applied server-side before pagination.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function indexQuery(Request $request, Builder $query): Builder;

    /**
     * Build a query for relatable resource options.
     *
     * Override to filter which records appear in relationship selectors.
     * For per-relationship customization, define relatable{PluralModelName}() instead.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function relatableQuery(Request $request, Builder $query): Builder;

    // -------------------------------------------------------------------------
    // Index configuration
    // -------------------------------------------------------------------------

    /** Whether the index view supports full-text search. */
    public static function indexSearchable(): bool;

    /**
     * Return the available per-page options for pagination (e.g. [15, 25, 50]).
     *
     * @return list<int>
     */
    public static function perPageOptions(): array;

    /** Return the default number of items per page. */
    public static function perPage(): int;

    /** Return the default sort column for the index listing, or null for no default. */
    public static function defaultSort(): ?string;

    /** Return the default sort direction for the index listing. */
    public static function defaultSortDirection(): SortDirection;

    /** Determine whether this resource should appear in the navigation menu. */
    public static function displayInNavigation(): bool;

    /** Whether the navigation should show a count badge for this resource. */
    public static function showMenuCount(): bool;

    /** Compute the navigation count badge (null hides it). */
    public static function menuCount(Request $request): ?int;

    /** Return a custom search placeholder, or null for the i18n default. */
    public static function searchPlaceholder(): ?string;

    /** Whether the resource uses soft deletes. */
    public static function softDeletes(): bool;

    // -------------------------------------------------------------------------
    // Table display options
    // -------------------------------------------------------------------------

    /** Whether the data table should display striped rows. */
    public static function tableStriped(): bool;

    /** Whether the data table should display gridlines. */
    public static function tableShowGridlines(): bool;

    /** Table density: "small", "normal", or "large". */
    public static function tableSize(): TableSize;

    /** How column widths distribute: auto (default) or fixed. */
    public static function tableLayout(): TableLayout;

    /** Whether rows highlight on hover. */
    public static function tableRowHover(): bool;

    /**
     * Customize the label for the actions menu in the resource index.
     */
    public static function actionsMenuLabel(): ?string;

    /**
     * Customize the label for the bulk actions menu in the resource index.
     */
    public static function bulkActionsMenuLabel(): ?string;

    // -------------------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------------------

    /** Whether authorization checks are enabled for this resource. */
    public static function authorizable(): bool;

    /**
     * Resolve the policy instance for this resource.
     *
     * @return object|null The policy instance, or null if none found
     */
    public static function resolvePolicy(): ?object;

    /** Flush the resolved policy cache (for testing). */
    public static function flushPolicyCache(): void;

    /**
     * Authorized to view any.
     */
    public function authorizedToViewAny(Request $request): bool;

    /**
     * Authorized to view.
     */
    public function authorizedToView(Request $request): bool;

    /**
     * Authorized to create.
     */
    public function authorizedToCreate(Request $request): bool;

    /**
     * Authorized to update.
     */
    public function authorizedToUpdate(Request $request): bool;

    /**
     * Authorized to delete.
     */
    public function authorizedToDelete(Request $request): bool;

    /** Whether the user may restore this soft-deleted resource. */
    public function authorizedToRestore(Request $request): bool;

    /** Whether the user may permanently delete this resource. */
    public function authorizedToForceDelete(Request $request): bool;

    /** Whether the user may replicate (duplicate) this resource. */
    public function authorizedToReplicate(Request $request): bool;

    /** Whether the user may run a normal action on this resource. */
    public function authorizedToRunAction(Request $request): bool;

    /** Whether the user may run a destructive action on this resource. */
    public function authorizedToRunDestructiveAction(Request $request): bool;

    // -------------------------------------------------------------------------
    // Relational authorization
    // -------------------------------------------------------------------------

    /**
     * Determine whether the user may attach ANY record of the given type.
     *
     * @param  class-string<Model>  $relatedModelClass
     */
    public function authorizedToAttachAny(Request $request, string $relatedModelClass): bool;

    /**
     * Determine whether the user may attach a specific related model.
     */
    public function authorizedToAttach(Request $request, Model $relatedModel): bool;

    /**
     * Determine whether the user may detach a specific related model.
     */
    public function authorizedToDetach(Request $request, Model $relatedModel): bool;

    /**
     * Determine whether the user may add (inline create) a related model.
     *
     * @param  class-string<Model>  $relatedModelClass
     */
    public function authorizedToAdd(Request $request, string $relatedModelClass): bool;

    /**
     * Return per-record authorization metadata for the frontend.
     *
     * @return array<string, bool>
     */
    public function authorizationMetadata(Request $request): array;

    /**
     * Return collection-level authorization metadata for schema responses.
     *
     * @return array<string, bool>
     */
    public function collectionAuthorizationMetadata(Request $request): array;

    // -------------------------------------------------------------------------
    // Lifecycle hooks
    // -------------------------------------------------------------------------

    /** Called before a model is saved (create or update). */
    public function beforeSave(Model $model, Request $request, bool $creating): void;

    /** Called after a model is saved (create or update). */
    public function afterSave(Model $model, Request $request, bool $creating): void;

    /** Called before a model is deleted. */
    public function beforeDelete(Model $model, Request $request): void;

    /** Called after a model is deleted. */
    public function afterDelete(Model $model, Request $request): void;

    // -------------------------------------------------------------------------
    // User-facing messages (i18n)
    // -------------------------------------------------------------------------

    /**
     * Created message.
     */
    public static function createdMessage(): string;

    /**
     * Updated message.
     */
    public static function updatedMessage(): string;

    /**
     * Deleted message.
     */
    public static function deletedMessage(): string;

    /**
     * Restored message.
     */
    public static function restoredMessage(): string;

    /**
     * Force deleted message.
     */
    public static function forceDeletedMessage(): string;

    /**
     * Replicated message.
     */
    public static function replicatedMessage(): string;

    /**
     * Delete confirm message.
     */
    public static function deleteConfirmMessage(): string;

    /**
     * Archive confirm message.
     */
    public static function archiveConfirmMessage(): string;

    /**
     * Force delete confirm message.
     */
    public static function forceDeleteConfirmMessage(): string;

    /** Error display mode: "toast", "inline", or "both". */
    public static function errorDisplay(): ErrorDisplayMode;

    /**
     * Validation message.
     */
    public static function validationMessage(): string;

    // -------------------------------------------------------------------------
    // Scout integration
    // -------------------------------------------------------------------------

    /**
     * Determine whether this resource uses Laravel Scout for searching.
     *
     * Returns true when the associated model uses the Searchable trait
     * and Scout has not been explicitly disabled.
     */
    public static function usesScout(): bool;

    /**
     * Customise the Scout builder before executing the search.
     *
     * Only called when the resource is effectively using Scout.
     *
     * @param  mixed  $query  Scout builder instance
     * @return mixed Scout builder instance
     */
    public static function scoutQuery(Request $request, mixed $query): mixed;

    // -------------------------------------------------------------------------
    // Global Search
    // -------------------------------------------------------------------------

    /**
     * Determine whether this resource is included in global search (Cmd+K).
     *
     * - `bool` (legacy): true to opt-in (uses package defaults), false to
     *   exclude entirely.
     * - `array`: `['enabled' => bool, 'limit' => int, 'min_query' => int]`
     *   to override the global defaults from `config('martis.search')`.
     *   `enabled` defaults to true when an array is returned.
     *
     * @return bool|array{enabled?: bool, limit?: int, min_query?: int}
     */
    public static function globallySearchable(): bool|array;

    /**
     * Return a per-record subtitle for global search results.
     *
     * Override to return a meaningful secondary string shown below the record
     * title in the Cmd+K search modal.
     *
     * Example: return $model->email; // for a User resource
     */
    public function searchSubtitle(Model $model): ?string;

    /**
     * Customise result ordering for global search. Called AFTER the
     * search filter, so any `orderBy()` here runs on the filtered set.
     * Default implementation is a no-op.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function searchOrderBy(Builder $query, string $term): Builder;

    // -------------------------------------------------------------------------
    // Page overrides
    // -------------------------------------------------------------------------

    /** Override the create page with a custom React component. */
    public function overrideCreate(): ?OverrideContract;

    /** Override the update page with a custom React component. */
    public function overrideUpdate(): ?OverrideContract;

    /** Override the detail page with a custom React component. */
    public function overrideDetail(): ?OverrideContract;

    /** Override the index page with a custom React component. */
    public function overrideIndex(): ?OverrideContract;

    /**
     * Collect all page overrides for the schema API.
     *
     * @return array{create: array{component: string, params: array<string, mixed>}|null, update: array{component: string, params: array<string, mixed>}|null, detail: array{component: string, params: array<string, mixed>}|null, index: array{component: string, params: array<string, mixed>}|null}
     */
    public function overrides(): array;

    // -------------------------------------------------------------------------
    // Serialization
    // -------------------------------------------------------------------------

    /**
     * Serialize the resource to an array for the JSON API.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
