<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Martis\Contracts\FieldContract;

/**
 * Abstract base class for all Martis fields.
 *
 * Implements the full FieldContract with sensible defaults so that concrete
 * field classes only need to declare their `type()` identifier and any
 * type-specific extras.
 *
 * Hook points for Track C (HasMany, MorphTo, etc.):
 *   - Override `resolveUsing(callable $callback)` to customize value resolution
 *   - Override `fillUsing(callable $callback)` to customize model filling
 *   - Override `extraAttributes()` to append type-specific data to toArray()
 *   - Override `resolveForDisplay(Model $model)` for index-specific formatting
 *   - Override `fillBeforeValidation(Model $model, mixed $value)` hook
 *
 * @phpstan-consistent-constructor
 */
abstract class Field implements FieldContract
{
    protected bool $nullable = false;

    protected bool $readonly = false;

    protected bool $required = false;

    protected bool $showOnIndex = true;

    protected bool $showOnDetail = true;

    protected bool $showOnForms = true;

    protected bool $sortable = false;

    protected bool $searchable = false;

    /** @var callable|null */
    protected mixed $resolveCallback = null;

    /** @var callable|null */
    protected mixed $fillCallback = null;

    /** @var list<string> */
    protected array $extraRules = [];

    protected function __construct(
        protected readonly string $attribute,
        protected readonly string $label,
    ) {}

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Create a new field instance.
     *
     * @param  string  $attribute  Model attribute name (e.g. "title")
     * @param  string|null  $label  Human-readable label; defaults to title-cased attribute
     */
    public static function make(string $attribute, ?string $label = null): static
    {
        return new static($attribute, $label ?? Str::title(str_replace('_', ' ', $attribute)));
    }

    // -------------------------------------------------------------------------
    // FieldContract — identity
    // -------------------------------------------------------------------------

    public function attribute(): string
    {
        return $this->attribute;
    }

    public function label(): string
    {
        return $this->label;
    }

    // -------------------------------------------------------------------------
    // FieldContract — value resolution and filling
    // -------------------------------------------------------------------------

    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $attr = $attribute ?? $this->attribute;

        if ($this->resolveCallback !== null) {
            return ($this->resolveCallback)($model->getAttribute($attr), $model, $attr);
        }

        return $model->getAttribute($attr);
    }

    public function fill(Model $model, mixed $value): void
    {
        if ($this->readonly) {
            return;
        }

        if ($this->fillCallback !== null) {
            ($this->fillCallback)($model, $value, $this->attribute);

            return;
        }

        $model->setAttribute($this->attribute, $value);
    }

    // -------------------------------------------------------------------------
    // FieldContract — serialization
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'attribute' => $this->attribute,
            'label' => $this->label,
            'type' => $this->type(),
            'nullable' => $this->nullable,
            'readonly' => $this->readonly,
            'required' => $this->required,
            'sortable' => $this->sortable,
            'searchable' => $this->searchable,
            'showOnIndex' => $this->showOnIndex,
            'showOnDetail' => $this->showOnDetail,
            'showOnForms' => $this->showOnForms,
            'rules' => $this->buildRules(),
        ], $this->extraAttributes());
    }

    // -------------------------------------------------------------------------
    // FieldContract — visibility
    // -------------------------------------------------------------------------

    public function nullable(): static
    {
        $this->nullable = true;

        return $this;
    }

    public function readonly(): static
    {
        $this->readonly = true;

        return $this;
    }

    public function required(): static
    {
        $this->required = true;

        return $this;
    }

    public function showOnIndex(): static
    {
        $this->showOnIndex = true;

        return $this;
    }

    public function hideFromIndex(): static
    {
        $this->showOnIndex = false;

        return $this;
    }

    public function showOnDetail(): static
    {
        $this->showOnDetail = true;

        return $this;
    }

    public function hideFromDetail(): static
    {
        $this->showOnDetail = false;

        return $this;
    }

    public function showOnForms(): static
    {
        $this->showOnForms = true;

        return $this;
    }

    public function hideFromForms(): static
    {
        $this->showOnForms = false;

        return $this;
    }

    public function isShownOnIndex(): bool
    {
        return $this->showOnIndex;
    }

    public function isShownOnDetail(): bool
    {
        return $this->showOnDetail;
    }

    public function isShownOnForms(): bool
    {
        return $this->showOnForms;
    }

    // -------------------------------------------------------------------------
    // Sortable / Searchable
    // -------------------------------------------------------------------------

    /**
     * Allow this field to be used as a sort column in the index view.
     */
    public function sortable(bool $value = true): static
    {
        $this->sortable = $value;

        return $this;
    }

    /**
     * Include this field when performing full-text search on the resource.
     */
    public function searchable(bool $value = true): static
    {
        $this->searchable = $value;

        return $this;
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * Append extra Laravel validation rules.
     *
     * @param  list<string>  $rules
     */
    public function rules(array $rules): static
    {
        $this->extraRules = array_merge($this->extraRules, $rules);

        return $this;
    }

    /**
     * Build the full list of validation rules for this field.
     *
     * @return list<string>
     */
    public function buildRules(): array
    {
        $rules = [];

        if ($this->required) {
            $rules[] = 'required';
        } elseif ($this->nullable) {
            $rules[] = 'nullable';
        } else {
            $rules[] = 'sometimes';
        }

        return array_merge($rules, $this->extraRules);
    }

    // -------------------------------------------------------------------------
    // Customization hooks (override in subclasses or at runtime)
    // -------------------------------------------------------------------------

    /**
     * Customize how the field value is resolved from the model.
     *
     * @param  callable(mixed $value, Model $model, string $attribute): mixed  $callback
     */
    public function resolveUsing(callable $callback): static
    {
        $this->resolveCallback = $callback;

        return $this;
    }

    /**
     * Customize how incoming values are written to the model.
     *
     * @param  callable(Model $model, mixed $value, string $attribute): void  $callback
     */
    public function fillUsing(callable $callback): static
    {
        $this->fillCallback = $callback;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Extension point — Track C hook
    // -------------------------------------------------------------------------

    /**
     * Return extra attributes merged into toArray().
     *
     * Concrete fields (Select, BelongsTo, etc.) override this to include
     * type-specific data (options, related model info, etc.).
     *
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [];
    }
}
