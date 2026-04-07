<?php

namespace Martis\Contracts;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\FieldContext;

/**
 * Contract for all Martis Field classes.
 *
 * A Field maps a model attribute to a UI component. It knows how to:
 *   - resolve its value from a model instance,
 *   - fill a model instance with a value from user input,
 *   - serialize itself to an array for the JSON API / React frontend.
 *
 * This contract must mirror every public method on the base Field class 1:1.
 * Any method added to Field.php MUST be added here as well.
 */
interface FieldContract
{
    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /** Create a new field instance. */
    public static function make(string $attribute, ?string $label = null): static;

    // -------------------------------------------------------------------------
    // Core identity
    // -------------------------------------------------------------------------

    /** Return the model attribute name this field maps to (e.g. "title"). */
    public function attribute(): string;

    /** Return the human-readable column label (e.g. "Title"). */
    public function label(): string;

    /**
     * Return the field type identifier consumed by the React renderer.
     * Must be a stable, lowercase snake_case string (e.g. "text", "boolean").
     */
    public function type(): string;

    // -------------------------------------------------------------------------
    // Value resolution
    // -------------------------------------------------------------------------

    /** Resolve the field value from a model instance. */
    public function resolve(Model $model, ?string $attribute = null): mixed;

    /** Resolve the field value for display purposes (may apply display callbacks). */
    public function resolveForDisplay(Model $model, ?string $attribute = null): mixed;

    /** Write the incoming request value into the model. */
    public function fill(Model $model, mixed $value): void;

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    /** Mark this field as nullable. */
    public function nullable(): static;

    /** Prevent this field from being modified through the UI. */
    public function readonly(): static;

    /** Require a non-null value on create/update. */
    public function required(): static;

    /** Set a placeholder text for the input. */
    public function placeholder(string $text): static;

    /** Mark this field as sortable on the index view. */
    public function sortable(bool $value = true): static;

    /** Mark this field as searchable. */
    public function searchable(bool $value = true): static;

    /** Override the frontend component used to render this field. */
    public function component(string $key): static;

    /** Return the custom component key, or null for the default. */
    public function getComponentKey(): ?string;

    // -------------------------------------------------------------------------
    // Per-context overrides
    // -------------------------------------------------------------------------

    /** Override the component used to render this field in the create context. */
    public function overrideCreate(OverrideContract $override): static;

    /** Override the component used to render this field in the update context. */
    public function overrideUpdate(OverrideContract $override): static;

    /** Override the component used to render this field in the index context. */
    public function overrideIndex(OverrideContract $override): static;

    /** Override the component used to render this field in the detail context. */
    public function overrideDetail(OverrideContract $override): static;

    /** Return the override for the given context, or null. */
    public function getOverrideForContext(FieldContext $context): ?OverrideContract;

    /**
     * Merge additional metadata into the field descriptor.
     *
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): static;

    // -------------------------------------------------------------------------
    // Grid layout
    // -------------------------------------------------------------------------

    /**
     * Set the column span in a 12-column grid layout (1-12, default: 12).
     *
     * Use 6 for half-width, 4 for one-third, etc.
     */
    public function colSpan(int $cols): static;

    /**
     * Set the column span from the md breakpoint (>= 768px).
     *
     * Null = inherit from colSpan.
     */
    public function colSpanMd(int $cols): static;

    /**
     * Set the column span from the lg breakpoint (>= 1024px).
     *
     * Null = inherit from colSpanMd, then colSpan.
     */
    public function colSpanLg(int $cols): static;

    // -------------------------------------------------------------------------
    // Visibility
    // -------------------------------------------------------------------------

    /** Show this field on the index (list) view. */
    public function showOnIndex(): static;

    /** Hide this field from the index view. */
    public function hideFromIndex(): static;

    /** Show this field on the detail (show) view. */
    public function showOnDetail(): static;

    /** Hide this field from the detail view. */
    public function hideFromDetail(): static;

    /** Show this field on create and edit forms. */
    public function showOnForms(): static;

    /** Hide this field from create and edit forms. */
    public function hideFromForms(): static;

    /** Return whether this field is visible on the index view. */
    public function isShownOnIndex(): bool;

    /** Return whether this field is visible on the detail view. */
    public function isShownOnDetail(): bool;

    /** Return whether this field is visible on forms. */
    public function isShownOnForms(): bool;

    // Nova v5 parity — granular visibility

    /** Hide this field when creating a new record. */
    public function hideWhenCreating(): static;

    /** Hide this field when updating an existing record. */
    public function hideWhenUpdating(): static;

    /** Show this field on create forms. */
    public function showOnCreating(): static;

    /** Show this field on update forms. */
    public function showOnUpdating(): static;

    /** Show this field only on the index view. */
    public function onlyOnIndex(): static;

    /** Show this field only on the detail view. */
    public function onlyOnDetail(): static;

    /** Show this field only on create and update forms. */
    public function onlyOnForms(): static;

    /** Show this field everywhere except on forms. */
    public function exceptOnForms(): static;

    /**
     * Determine if this field should be visible in the given context.
     */
    public function isVisibleForContext(FieldContext $context): bool;

    /** Return whether this field is sortable. */
    public function isSortable(): bool;

    /** Return whether this field is searchable. */
    public function isSearchable(): bool;

    // -------------------------------------------------------------------------
    // Authorization — field-level visibility (Nova v5 parity, REA-1115)
    // -------------------------------------------------------------------------

    /**
     * Set a callback that determines whether this field is visible.
     *
     * @param  callable(Request): bool  $callback
     */
    public function canSee(callable $callback): static;

    /**
     * Shorthand: check a policy ability to determine field visibility.
     */
    public function canSeeWhen(string $ability, mixed ...$arguments): static;

    /**
     * Determine whether this field is authorized to be seen by the current user.
     */
    public function isAuthorizedToSee(Request $request): bool;

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * Set validation rules for this field.
     *
     * @param  list<string|Rule>  $rules
     */
    public function rules(array $rules): static;

    /**
     * Build the final validation rules array (merges required/nullable flags).
     *
     * @return list<string|Rule>
     */
    public function buildRules(): array;

    // -------------------------------------------------------------------------
    // Callbacks
    // -------------------------------------------------------------------------

    /** Override how the value is resolved from the model. */
    public function resolveUsing(callable $callback): static;

    /** Override how the value is filled into the model. */
    public function fillUsing(callable $callback): static;

    /** Override how the resolved value is transformed for display. */
    public function displayUsing(callable $callback): static;

    // -------------------------------------------------------------------------
    // Serialization
    // -------------------------------------------------------------------------

    /**
     * Serialize the field descriptor for the JSON API response.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
