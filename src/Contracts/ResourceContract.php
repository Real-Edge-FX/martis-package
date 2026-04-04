<?php

namespace Martis\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

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

    /** Return the navigation group this resource belongs to, or null for default. */
    public function group(): ?string;

    /** Return the underlying Eloquent model, if set. */
    public function getModel(): ?Model;

    // -------------------------------------------------------------------------
    // Field resolution for views
    // -------------------------------------------------------------------------

    /**
     * Return only fields visible on the index (list) view.
     *
     * @return list<FieldContract>
     */
    public function fieldsForIndex(Request $request): array;

    /**
     * Return only fields visible on the detail (show) view.
     *
     * @return list<FieldContract>
     */
    public function fieldsForDetail(Request $request): array;

    /**
     * Return only fields visible on create/edit forms.
     *
     * @return list<FieldContract>
     */
    public function fieldsForForms(Request $request): array;

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
    public static function tableSize(): string;

    /** Whether rows highlight on hover. */
    public static function tableRowHover(): bool;

    // -------------------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------------------

    public function authorizedToViewAny(Request $request): bool;

    public function authorizedToView(Request $request): bool;

    public function authorizedToCreate(Request $request): bool;

    public function authorizedToUpdate(Request $request): bool;

    public function authorizedToDelete(Request $request): bool;

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

    public static function createdMessage(): string;

    public static function updatedMessage(): string;

    public static function deletedMessage(): string;

    public static function restoredMessage(): string;

    public static function deleteConfirmMessage(): string;

    public static function archiveConfirmMessage(): string;

    /** Error display mode: "toast", "inline", or "both". */
    public static function errorDisplay(): string;

    public static function validationMessage(): string;

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
