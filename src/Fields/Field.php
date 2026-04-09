<?php

namespace Martis\Fields;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Contracts\FieldContract;
use Martis\Contracts\OverrideContract;
use Martis\FieldContext;

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

    protected ?bool $showOnCreate = null;

    protected ?bool $showOnUpdate = null;

    protected ?bool $showOnPreview = null;

    protected bool $sortable = false;

    protected bool $searchable = false;

    /**
     * Callback that determines whether this field is visible to the current user.
     * Nova v5 parity: canSee(callable).
     */
    protected ?\Closure $canSeeCallback = null;

    /** @var callable|null */
    protected mixed $resolveCallback = null;

    /** @var callable|null */
    protected mixed $fillCallback = null;

    /** @var callable|null */
    protected mixed $displayCallback = null;

    /** @var list<string|Rule> */
    protected array $extraRules = [];

    /**
     * Unique validation config: [table] or [table, column].
     *
     * @var array{0: string, 1?: string}|null
     */
    protected ?array $uniqueConfig = null;

    /** Custom error message for unique validation. */
    protected ?string $uniqueMessage = null;

    /** ID to ignore for unique validation on updates. */
    protected int|string|null $uniqueIgnoreId = null;

    /**
     * Custom component key for the React renderer.
     * When set, the frontend resolves this exact key from the component registry
     * instead of the default "field:display:{type}" / "field:input:{type}" keys.
     */
    protected ?string $componentKey = null;

    /** Per-context component overrides (mirrors Resource-level overrides). */
    protected ?OverrideContract $overrideForCreate = null;

    protected ?OverrideContract $overrideForUpdate = null;

    protected ?OverrideContract $overrideForIndex = null;

    protected ?OverrideContract $overrideForDetail = null;

    protected ?string $placeholder = null;

    protected ?string $helpText = null;

    protected mixed $defaultValue = null;

    protected bool $hasDefault = false;

    protected function __construct(
        protected readonly string $attribute,
        protected readonly string $label,
    ) {}

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public static function make(string $attribute, ?string $label = null): static
    {
        return new static($attribute, $label ?? Str::title(str_replace('_', ' ', $attribute)));
    }

    // -------------------------------------------------------------------------
    // FieldContract — identity
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function attribute(): string
    {
        return $this->attribute;
    }

    /** {@inheritDoc} */
    public function label(): string
    {
        return $this->label;
    }

    // -------------------------------------------------------------------------
    // FieldContract — value resolution and filling
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $attr = $attribute ?? $this->attribute;

        if ($this->resolveCallback !== null) {
            return ($this->resolveCallback)($model->getAttribute($attr), $model, $attr);
        }

        return $model->getAttribute($attr);
    }

    /** {@inheritDoc} */
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

    /** {@inheritDoc} */
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
            'component' => $this->componentKey,
            'placeholder' => $this->placeholder,
            'colSpan' => $this->colSpan,
            'colSpanMd' => $this->colSpanMd,
            'colSpanLg' => $this->colSpanLg,
            'overrides' => array_filter([
                'create' => $this->overrideForCreate?->toArray(),
                'update' => $this->overrideForUpdate?->toArray(),
                'index' => $this->overrideForIndex?->toArray(),
                'detail' => $this->overrideForDetail?->toArray(),
            ], fn (mixed $v): bool => $v !== null) ?: null,
        ], $this->extraAttributes(), $this->meta);
    }

    // -------------------------------------------------------------------------
    // FieldContract — visibility
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function nullable(): static
    {
        $this->nullable = true;

        return $this;
    }

    /** {@inheritDoc} */
    public function readonly(): static
    {
        $this->readonly = true;

        return $this;
    }

    /** {@inheritDoc} */
    public function required(): static
    {
        $this->required = true;

        return $this;
    }

    /** {@inheritDoc} */
    public function placeholder(string $text): static
    {
        $this->placeholder = $text;

        return $this;
    }

    /**
     * Set help text displayed below the field input.
     */
    public function help(string $text): static
    {
        $this->helpText = $text;

        return $this;
    }

    /**
     * Set a default value for the field on create forms.
     */
    public function default(mixed $value): static
    {
        $this->defaultValue = $value;
        $this->hasDefault = true;

        return $this;
    }

    /** {@inheritDoc} */
    public function showOnIndex(): static
    {
        $this->showOnIndex = true;

        return $this;
    }

    /** {@inheritDoc} */
    public function hideFromIndex(): static
    {
        $this->showOnIndex = false;

        return $this;
    }

    /** {@inheritDoc} */
    public function showOnDetail(): static
    {
        $this->showOnDetail = true;

        return $this;
    }

    /** {@inheritDoc} */
    public function hideFromDetail(): static
    {
        $this->showOnDetail = false;

        return $this;
    }

    /** {@inheritDoc} */
    public function showOnForms(): static
    {
        $this->showOnForms = true;

        return $this;
    }

    /** {@inheritDoc} */
    public function hideFromForms(): static
    {
        $this->showOnForms = false;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Nova v5 parity — granular visibility flags
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function hideWhenCreating(): static
    {
        $this->showOnCreate = false;

        return $this;
    }

    /** {@inheritDoc} */
    public function hideWhenUpdating(): static
    {
        $this->showOnUpdate = false;

        return $this;
    }

    /** {@inheritDoc} */
    public function showOnCreating(): static
    {
        $this->showOnCreate = true;

        return $this;
    }

    /** {@inheritDoc} */
    public function showOnUpdating(): static
    {
        $this->showOnUpdate = true;

        return $this;
    }

    /** {@inheritDoc} */
    public function onlyOnIndex(): static
    {
        $this->showOnIndex = true;
        $this->showOnDetail = false;
        $this->showOnForms = false;
        $this->showOnCreate = false;
        $this->showOnUpdate = false;
        $this->showOnPreview = false;

        return $this;
    }

    /** {@inheritDoc} */
    public function onlyOnDetail(): static
    {
        $this->showOnIndex = false;
        $this->showOnDetail = true;
        $this->showOnForms = false;
        $this->showOnCreate = false;
        $this->showOnUpdate = false;
        $this->showOnPreview = false;

        return $this;
    }

    /** {@inheritDoc} */
    public function onlyOnForms(): static
    {
        $this->showOnIndex = false;
        $this->showOnDetail = false;
        $this->showOnForms = true;
        $this->showOnCreate = null;
        $this->showOnUpdate = null;
        $this->showOnPreview = false;

        return $this;
    }

    /** {@inheritDoc} */
    public function exceptOnForms(): static
    {
        $this->showOnForms = false;
        $this->showOnCreate = false;
        $this->showOnUpdate = false;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Context-aware visibility resolution
    // -------------------------------------------------------------------------

    /**
     * Determine if this field should be visible in the given context.
     *
     * Resolution rules per context:
     *   index         → showOnIndex
     *   detail        → showOnDetail
     *   create        → showOnCreate ?? showOnForms
     *   update        → showOnUpdate ?? showOnForms
     *   inline-create → showOnCreate ?? showOnForms  (same as create)
     *   preview       → showOnPreview ?? showOnDetail
     *
     * Conflict resolution: hide wins. If a field has conflicting flags
     * (e.g. onlyOnIndex() + hideFromIndex()), the explicit hide takes
     * precedence because restrictive behavior is safer.
     *
     * {@inheritDoc}
     */
    public function isVisibleForContext(FieldContext $context): bool
    {
        return match ($context) {
            FieldContext::INDEX => $this->showOnIndex,
            FieldContext::DETAIL => $this->showOnDetail,
            FieldContext::CREATE,
            FieldContext::INLINE_CREATE => $this->showOnCreate ?? $this->showOnForms,
            FieldContext::UPDATE => $this->showOnUpdate ?? $this->showOnForms,
            FieldContext::PREVIEW => $this->showOnPreview ?? $this->showOnDetail,
        };
    }

    /**
     * Filter an array of fields to only those visible in the given context.
     *
     * This is the central filtering entry point. All controller methods
     * and the schema endpoint call this after resolving the raw field set.
     *
     * @param  list<FieldContract>  $fields
     * @return list<FieldContract>
     */
    public static function filterForContext(array $fields, FieldContext $context, ?Request $request = null): array
    {
        if ($request === null) {
            try {
                $request = request();
            } catch (\Throwable) {
                // No request available (e.g. unit tests without HTTP context)
            }
        }

        return array_values(array_filter(
            $fields,
            function (FieldContract $f) use ($context, $request): bool {
                if (! $f->isVisibleForContext($context)) {
                    return false;
                }

                // Check field-level authorization (canSee) — skip when no request available
                if ($request !== null && $f instanceof self && ! $f->isAuthorizedToSee($request)) {
                    return false;
                }

                return true;
            },
        ));
    }

    /** {@inheritDoc} */
    public function isShownOnIndex(): bool
    {
        return $this->showOnIndex;
    }

    /** {@inheritDoc} */
    public function isShownOnDetail(): bool
    {
        return $this->showOnDetail;
    }

    /** {@inheritDoc} */
    public function isShownOnForms(): bool
    {
        return $this->showOnForms;
    }

    // -------------------------------------------------------------------------
    // Authorization — field-level visibility (Nova v5 parity, REA-1115)
    // -------------------------------------------------------------------------

    /**
     * Set a callback that determines whether this field is visible.
     *
     * The callback receives the current Request and should return a boolean.
     * When the callback returns false, the field is excluded from the response.
     *
     * Nova v5 parity: canSee(callable).
     *
     * @param  callable(Request): bool  $callback
     */
    public function canSee(callable $callback): static
    {
        $this->canSeeCallback = $callback(...);

        return $this;
    }

    /**
     * Shorthand for canSee() that checks a policy ability.
     *
     * Equivalent to: canSee(fn($request) => $request->user()?->can($ability, $arguments))
     *
     * Nova v5 parity: canSeeWhen(string $ability, ...$arguments).
     *
     * @param  string  $ability  The policy ability to check
     * @param  mixed  ...$arguments  Arguments passed to the Gate check
     */
    public function canSeeWhen(string $ability, mixed ...$arguments): static
    {
        $this->canSeeCallback = function (Request $request) use ($ability, $arguments): bool {
            $user = $request->user();
            if ($user === null) {
                return false;
            }

            return $user->can($ability, $arguments ?: []);
        };

        return $this;
    }

    /**
     * Determine whether this field is authorized to be seen by the current user.
     *
     * Returns true if no canSee callback is set (default: visible to all).
     */
    public function isAuthorizedToSee(Request $request): bool
    {
        if ($this->canSeeCallback === null) {
            return true;
        }

        return (bool) ($this->canSeeCallback)($request);
    }

    // -------------------------------------------------------------------------
    // Sortable / Searchable
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function sortable(bool $value = true): static
    {
        $this->sortable = $value;

        return $this;
    }

    /** {@inheritDoc} */
    public function searchable(bool $value = true): static
    {
        $this->searchable = $value;

        return $this;
    }

    /** {@inheritDoc} */
    public function isSortable(): bool
    {
        return $this->sortable;
    }

    /** {@inheritDoc} */
    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * {@inheritDoc}
     *
     * @param  list<string|Rule>  $rules
     */
    public function rules(array $rules): static
    {
        $this->extraRules = array_merge($this->extraRules, $rules);

        return $this;
    }

    /**
     * Mark this field as unique in the database.
     *
     * @param  array{0: string, 1?: string}  $config  [table] or [table, column]
     * @param  string|null  $message  Custom error message for unique violation
     */
    public function unique(array $config, ?string $message = null): static
    {
        $this->uniqueConfig = $config;
        $this->uniqueMessage = $message;

        return $this;
    }

    /**
     * Get custom validation messages for this field.
     *
     * @return array<string, string>
     */
    public function validationMessages(): array
    {
        $messages = [];
        if ($this->uniqueMessage !== null && $this->uniqueConfig !== null) {
            $messages[$this->attribute.'.unique'] = $this->uniqueMessage;
        }

        return $messages;
    }

    /**
     * Set the ID to ignore for unique validation (used on updates).
     */
    public function setUniqueIgnoreId(int|string|null $id): void
    {
        $this->uniqueIgnoreId = $id;
    }

    /**
     * {@inheritDoc}
     *
     * @return list<string|Rule>
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

        // Auto-add unique rule if unique() was called
        if ($this->uniqueConfig !== null) {
            $table = $this->uniqueConfig[0];
            $column = $this->uniqueConfig[1] ?? $this->attribute;
            $rule = "unique:{$table},{$column}";
            if ($this->uniqueIgnoreId !== null) {
                $rule .= ",{$this->uniqueIgnoreId}";
            }
            $rules[] = $rule;
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

    /**
     * Customize how the field value is formatted for display (index + detail).
     *
     * Applied AFTER resolveUsing(). Does NOT affect form values.
     * Equivalent to Laravel Nova's displayUsing().
     *
     * @param  callable(mixed $value, Model $model, string $attribute): mixed  $callback
     */
    public function displayUsing(callable $callback): static
    {
        $this->displayCallback = $callback;

        return $this;
    }

    /**
     * Resolve the field value for display contexts (index / detail).
     *
     * Applies resolveUsing() first, then displayUsing() on top.
     * Use this in serialization instead of resolve() when building
     * index or detail responses.
     */
    public function resolveForDisplay(Model $model, ?string $attribute = null): mixed
    {
        $value = $this->resolve($model, $attribute);

        if ($this->displayCallback !== null) {
            return ($this->displayCallback)($value, $model, $attribute ?? $this->attribute);
        }

        return $value;
    }

    // -------------------------------------------------------------------------
    // Component override — Bloco 9
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function component(string $key): static
    {
        $this->componentKey = $key;

        return $this;
    }

    /** {@inheritDoc} */
    public function getComponentKey(): ?string
    {
        return $this->componentKey;
    }

    // -------------------------------------------------------------------------
    // Per-context field overrides
    // -------------------------------------------------------------------------

    /**
     * Override the component used to render this field in the create context.
     */
    public function overrideCreate(OverrideContract $override): static
    {
        $this->overrideForCreate = $override;

        return $this;
    }

    /**
     * Override the component used to render this field in the update context.
     */
    public function overrideUpdate(OverrideContract $override): static
    {
        $this->overrideForUpdate = $override;

        return $this;
    }

    /**
     * Override the component used to render this field in the index context.
     */
    public function overrideIndex(OverrideContract $override): static
    {
        $this->overrideForIndex = $override;

        return $this;
    }

    /**
     * Override the component used to render this field in the detail context.
     */
    public function overrideDetail(OverrideContract $override): static
    {
        $this->overrideForDetail = $override;

        return $this;
    }

    /**
     * Return the override for the given context, or null.
     */
    public function getOverrideForContext(FieldContext $context): ?OverrideContract
    {
        return match ($context) {
            FieldContext::CREATE, FieldContext::INLINE_CREATE => $this->overrideForCreate,
            FieldContext::UPDATE => $this->overrideForUpdate,
            FieldContext::INDEX => $this->overrideForIndex,
            FieldContext::DETAIL, FieldContext::PREVIEW => $this->overrideForDetail,
        };
    }

    // -------------------------------------------------------------------------
    // Extension point — Track C hook
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Grid layout — colSpan
    // -------------------------------------------------------------------------

    /** Column span in a 12-column grid (1-12, default: 12 = full width). */
    protected int $colSpan = 12;

    /** Column span from the md breakpoint (>= 768px). Null = inherit colSpan. */
    protected ?int $colSpanMd = null;

    /** Column span from the lg breakpoint (>= 1024px). Null = inherit colSpanMd or colSpan. */
    protected ?int $colSpanLg = null;

    /** {@inheritDoc} */
    public function colSpan(int $cols): static
    {
        $this->colSpan = max(1, min(12, $cols));

        return $this;
    }

    /** {@inheritDoc} */
    public function colSpanMd(int $cols): static
    {
        $this->colSpanMd = max(1, min(12, $cols));

        return $this;
    }

    /** {@inheritDoc} */
    public function colSpanLg(int $cols): static
    {
        $this->colSpanLg = max(1, min(12, $cols));

        return $this;
    }

    // -------------------------------------------------------------------------
    // Arbitrary metadata — withMeta()
    // -------------------------------------------------------------------------

    /** @var array<string, mixed> */
    protected array $meta = [];

    /** {@inheritDoc} */
    public function withMeta(array $meta): static
    {
        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

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
