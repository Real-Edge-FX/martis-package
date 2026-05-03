<?php

namespace Martis\Contracts;

use Closure;
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
     * Mark this field as nullable. Accepts a static `bool` or a closure
     * that resolves at request time.
     *
     * @param  bool|Closure(Request|null): bool  $value
     */
    public function nullable(bool|Closure $value = true): static;

    /**
     * Prevent this field from being modified through the UI. Accepts a
     * static `bool` or a closure that resolves at request time.
     *
     * @param  bool|Closure(Request|null): bool  $value
     */
    public function readonly(bool|Closure $value = true): static;

    /**
     * Require a non-null value on create/update. Accepts a static
     * `bool` or a closure that resolves at request time.
     *
     * @param  bool|Closure(Request|null): bool  $value
     */
    public function required(bool|Closure $value = true): static;

    /**
     * Set a placeholder text for the input. Accepts a static string or
     * a closure that resolves at render time.
     *
     * @param  string|Closure(Request|null): string  $text
     */
    public function placeholder(string|Closure $text): static;

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
     * Set the relative ranking weight for matches in this field. Higher
     * values rank above lower ones in Global Search LIKE results.
     *
     * @param  int  $priority  Default 1; typical values 1 (long-form), 2 (title), 3 (id-like).
     */
    public function searchPriority(int $priority): static;

    /**
     * Return the search priority assigned via `searchPriority()`. Defaults to 1.
     */
    public function getSearchPriority(): int;

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

    // Granular visibility

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
    // Authorization — field-level visibility
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
     * Accepts either a static list of Laravel rules (strings or Rule
     * objects) or a closure that resolves at request time.
     *
     * @param  list<string|Rule>|Closure(Request|null): list<string|Rule>  $rules
     */
    public function rules(array|Closure $rules): static;

    // -------------------------------------------------------------------------
    // Reactive fields — dependsOn()
    // -------------------------------------------------------------------------

    /**
     * Declare a reactive dependency on one or more sibling fields. The
     * frontend re-syncs this field by posting the live form payload
     * whenever any of the listed fields changes.
     *
     * @param  list<string>  $fields  Sibling attributes to watch.
     * @param  Closure(array<string, mixed>, Request, static): void|null  $callback
     */
    public function dependsOn(array $fields, ?Closure $callback = null): static;

    /**
     * Return the list of attributes this field reacts to.
     *
     * @return list<string>
     */
    public function dependentFields(): array;

    /**
     * Whether `dependsOn()` was configured for this field.
     */
    public function isDependent(): bool;

    /**
     * Run the reactivity callback against the supplied form payload.
     *
     * @param  array<string, mixed>  $formData
     */
    public function syncDependent(array $formData, Request $request): static;

    /**
     * Build the final validation rules array (merges required/nullable
     * flags + context-specific rules from `creationRules()` /
     * `updateRules()` when `$context` is `'create'` / `'update'`).
     *
     * Closure rules are accepted alongside string and Rule entries —
     * Laravel invokes closures with `(string $attribute, mixed $value,
     * Closure $fail)` and a closure that calls `$fail(...)` produces
     * a validation error. Field subclasses use closures for
     * context-aware checks that do not warrant a dedicated Rule class.
     *
     * @return list<string|Rule|Closure>
     */
    public function buildRules(?string $context = null): array;

    // -------------------------------------------------------------------------
    // Callbacks
    // -------------------------------------------------------------------------

    /**
     * Override how the value is resolved from the model.
     *
     * Callback signature: `fn(mixed $value, Model $model, string $attribute, ?Request $request): mixed`.
     * The 4th argument is opt-in; closures declared with three parameters keep working.
     */
    public function resolveUsing(callable $callback): static;

    /**
     * Override how the value is filled into the model.
     *
     * Callback signature: `fn(Model $model, mixed $value, string $attribute, ?Request $request): void`.
     * The 4th argument is opt-in; closures declared with three parameters keep working.
     */
    public function fillUsing(callable $callback): static;

    /**
     * Override how the resolved value is transformed for display.
     *
     * Single callable signature: `fn(mixed $value, Model $model, string $attribute, ?Request $request): mixed`.
     * When passed an array, each entry receives the output of the previous one (chainable pipeline).
     *
     * @param  callable|list<callable>  $callback
     */
    public function displayUsing(callable|array $callback): static;

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
