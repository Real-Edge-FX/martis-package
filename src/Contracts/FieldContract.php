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

    /**
     * Create a new field instance.
     *
     * @param  string  $attribute  The model attribute name (e.g. "title").
     * @param  string|null  $label  Human-readable column label; defaults to a title-cased version of $attribute.
     */
    public static function make(string $attribute, ?string $label = null): static;

    // -------------------------------------------------------------------------
    // Core identity
    // -------------------------------------------------------------------------

    /**
     * Return the model attribute name this field maps to (e.g. "title").
     */
    public function attribute(): string;

    /**
     * Return the human-readable column label (e.g. "Title").
     */
    public function label(): string;

    /**
     * Return the field type identifier consumed by the React renderer.
     * Must be a stable, lowercase snake_case string (e.g. "text", "boolean").
     */
    public function type(): string;

    // -------------------------------------------------------------------------
    // Value resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the field value from a model instance.
     *
     * @param  Model  $model  The Eloquent model instance.
     * @param  string|null  $attribute  Override the attribute to resolve from; defaults to $this->attribute().
     */
    public function resolve(Model $model, ?string $attribute = null): mixed;

    /**
     * Resolve the field value for display purposes (may apply display callbacks).
     *
     * @param  Model  $model  The Eloquent model instance.
     * @param  string|null  $attribute  Override the attribute to resolve from; defaults to $this->attribute().
     */
    public function resolveForDisplay(Model $model, ?string $attribute = null): mixed;

    /**
     * Write the incoming request value into the model.
     *
     * @param  Model  $model  The Eloquent model instance to mutate.
     * @param  mixed  $value  The value submitted by the user.
     */
    public function fill(Model $model, mixed $value): void;

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    /**
     * Mark this field as nullable.
     */
    public function nullable(): static;

    /**
     * Prevent this field from being modified through the UI.
     */
    public function readonly(): static;

    /**
     * Require a non-null value on create/update.
     */
    public function required(): static;

    /**
     * Set a placeholder text for the input.
     *
     * @param  string  $text  The placeholder text to display in the input.
     */
    public function placeholder(string $text): static;

    /**
     * Mark this field as sortable on the index view.
     *
     * @param  bool  $value  Pass false to explicitly disable sorting.
     */
    public function sortable(bool $value = true): static;

    /**
     * Mark this field as searchable.
     *
     * @param  bool  $value  Pass false to explicitly disable searching.
     */
    public function searchable(bool $value = true): static;

    /**
     * Override the frontend component used to render this field.
     *
     * @param  string  $key  The component key registered in the frontend componentRegistry.
     */
    public function component(string $key): static;

    /**
     * Return the custom component key, or null for the default.
     */
    public function getComponentKey(): ?string;

    // -------------------------------------------------------------------------
    // Per-context overrides
    // -------------------------------------------------------------------------

    /**
     * Override the component used to render this field in the create context.
     *
     * @param  OverrideContract  $override  The override definition.
     */
    public function overrideCreate(OverrideContract $override): static;

    /**
     * Override the component used to render this field in the update context.
     *
     * @param  OverrideContract  $override  The override definition.
     */
    public function overrideUpdate(OverrideContract $override): static;

    /**
     * Override the component used to render this field in the index context.
     *
     * @param  OverrideContract  $override  The override definition.
     */
    public function overrideIndex(OverrideContract $override): static;

    /**
     * Override the component used to render this field in the detail context.
     *
     * @param  OverrideContract  $override  The override definition.
     */
    public function overrideDetail(OverrideContract $override): static;

    /**
     * Return the override for the given context, or null.
     *
     * @param  FieldContext  $context  The rendering context to look up.
     */
    public function getOverrideForContext(FieldContext $context): ?OverrideContract;

    /**
     * Merge additional metadata into the field descriptor.
     *
     * @param  array<string, mixed>  $meta  Key-value pairs forwarded to the React component as extra props.
     */
    public function withMeta(array $meta): static;

    // -------------------------------------------------------------------------
    // Grid layout
    // -------------------------------------------------------------------------

    /**
     * Set the column span in a 12-column grid layout (1-12, default: 12).
     *
     * Use 6 for half-width, 4 for one-third, etc.
     *
     * @param  int  $cols  Number of columns to span (1-12).
     */
    public function colSpan(int $cols): static;

    /**
     * Set the column span from the md breakpoint (>= 768px).
     *
     * Null = inherit from colSpan.
     *
     * @param  int  $cols  Number of columns to span from the md breakpoint.
     */
    public function colSpanMd(int $cols): static;

    /**
     * Set the column span from the lg breakpoint (>= 1024px).
     *
     * Null = inherit from colSpanMd, then colSpan.
     *
     * @param  int  $cols  Number of columns to span from the lg breakpoint.
     */
    public function colSpanLg(int $cols): static;

    // -------------------------------------------------------------------------
    // Visibility
    // -------------------------------------------------------------------------

    /**
     * Show this field on the index (list) view.
     */
    public function showOnIndex(): static;

    /**
     * Hide this field from the index view.
     */
    public function hideFromIndex(): static;

    /**
     * Show this field on the detail (show) view.
     */
    public function showOnDetail(): static;

    /**
     * Hide this field from the detail view.
     */
    public function hideFromDetail(): static;

    /**
     * Show this field on create and edit forms.
     */
    public function showOnForms(): static;

    /**
     * Hide this field from create and edit forms.
     */
    public function hideFromForms(): static;

    /**
     * Return whether this field is visible on the index view.
     */
    public function isShownOnIndex(): bool;

    /**
     * Return whether this field is visible on the detail view.
     */
    public function isShownOnDetail(): bool;

    /**
     * Return whether this field is visible on forms.
     */
    public function isShownOnForms(): bool;

    // Nova v5 parity — granular visibility

    /**
     * Hide this field when creating a new record.
     */
    public function hideWhenCreating(): static;

    /**
     * Hide this field when updating an existing record.
     */
    public function hideWhenUpdating(): static;

    /**
     * Show this field on create forms.
     */
    public function showOnCreating(): static;

    /**
     * Show this field on update forms.
     */
    public function showOnUpdating(): static;

    /**
     * Show this field only on the index view.
     */
    public function onlyOnIndex(): static;

    /**
     * Show this field only on the detail view.
     */
    public function onlyOnDetail(): static;

    /**
     * Show this field only on create and update forms.
     */
    public function onlyOnForms(): static;

    /**
     * Show this field everywhere except on forms.
     */
    public function exceptOnForms(): static;

    /**
     * Determine if this field should be visible in the given context.
     *
     * @param  FieldContext  $context  The rendering context to check.
     */
    public function isVisibleForContext(FieldContext $context): bool;

    /**
     * Return whether this field is sortable.
     */
    public function isSortable(): bool;

    /**
     * Return whether this field is searchable.
     */
    public function isSearchable(): bool;

    // -------------------------------------------------------------------------
    // Authorization — field-level visibility (Nova v5 parity)
    // -------------------------------------------------------------------------

    /**
     * Set a callback that determines whether this field is visible.
     *
     * @param  callable(Request): bool  $callback  Receives the current request; return true to show the field.
     */
    public function canSee(callable $callback): static;

    /**
     * Shorthand: check a policy ability to determine field visibility.
     *
     * @param  string  $ability  The policy ability name (e.g. "viewSensitiveData").
     * @param  mixed  ...$arguments  Arguments forwarded to the policy gate.
     */
    public function canSeeWhen(string $ability, mixed ...$arguments): static;

    /**
     * Determine whether this field is authorized to be seen by the current user.
     *
     * @param  Request  $request  The incoming HTTP request.
     */
    public function isAuthorizedToSee(Request $request): bool;

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * Set validation rules for this field.
     *
     * @param  list<string|Rule>  $rules  Laravel validation rules (strings or Rule objects).
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

    /**
     * Override how the value is resolved from the model.
     *
     * @param  callable  $callback  Receives (Model $model, string $attribute); must return the resolved value.
     */
    public function resolveUsing(callable $callback): static;

    /**
     * Override how the value is filled into the model.
     *
     * @param  callable  $callback  Receives (Model $model, mixed $value, string $attribute).
     */
    public function fillUsing(callable $callback): static;

    /**
     * Override how the resolved value is transformed for display.
     *
     * @param  callable  $callback  Receives (mixed $value, Model $model, string $attribute); must return the display value.
     */
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
