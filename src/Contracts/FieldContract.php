<?php

namespace Martis\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract for all Martis Field classes.
 *
 * A Field maps a model attribute to a UI component. It knows how to:
 *   - resolve its value from a model instance,
 *   - fill a model instance with a value from user input,
 *   - serialize itself to an array for the JSON API / React frontend.
 *
 * Visibility helpers follow the fluent builder pattern so that chains like
 * `Text::make('Title')->hideFromIndex()->required()` remain readable.
 *
 * TypeScript counterpart: the React frontend uses a discriminated union of
 * field descriptors keyed by `type`. Each `type` value produced by
 * `FieldContract::type()` MUST have a corresponding TypeScript type in
 * `resources/js/types/fields.d.ts`.
 */
interface FieldContract
{
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

    /**
     * Resolve the field value from a model instance.
     */
    public function resolve(Model $model, ?string $attribute = null): mixed;

    /**
     * Write the incoming request value into the model.
     */
    public function fill(Model $model, mixed $value): void;

    /**
     * Serialize the field descriptor for the JSON API response.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    // -------------------------------------------------------------------------
    // Fluent visibility / validation modifiers
    // -------------------------------------------------------------------------

    /** Mark this field as nullable. */
    public function nullable(): static;

    /** Prevent this field from being modified through the UI. */
    public function readonly(): static;

    /** Require a non-null value on create/update. */
    public function required(): static;

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
}
