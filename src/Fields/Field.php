<?php

namespace Martis\Fields;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Contracts\FieldContract;
use Martis\Contracts\LayoutContract;
use Martis\Contracts\OverrideContract;
use Martis\FieldContext;
use Martis\Resource;
use Martis\ResourceRegistry;

/**
 * Abstract base class for all Martis fields.
 *
 * Implements the full FieldContract with sensible defaults so that concrete
 * field classes only need to declare their `type()` identifier and any
 * type-specific extras.
 *
 * Hook points for relationship fields (HasMany, MorphTo, etc.):
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

    /**
     * Lazy resolver — set by `nullable(bool|Closure)`. Closures
     * receive the active Request and decide at render time. `null`
     * means the resolver was never set; falls back to the legacy
     * `$nullable` boolean.
     *
     * @var bool|\Closure(Request|null): bool|null
     */
    protected mixed $nullableResolver = null;

    protected bool $readonly = false;

    /**
     * Lazy resolver — set by `readonly(bool|Closure)`. Closures
     * receive the active Request and decide at render time. `null`
     * means the resolver was never set; falls back to the legacy
     * `$readonly` boolean.
     *
     * @var bool|\Closure(Request|null): bool|null
     */
    protected mixed $readonlyResolver = null;

    protected bool $required = false;

    /**
     * Lazy resolver — set by `required(bool|Closure)`. Closures
     * receive the active Request and decide at render time. `null`
     * means the resolver was never set; falls back to the legacy
     * `$required` boolean.
     *
     * @var bool|\Closure(Request|null): bool|null
     */
    protected mixed $requiredResolver = null;

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
     * Rules that apply ONLY on `POST /resources/{r}` (create context).
     * Merged on top of base + extraRules at validation time when the
     * controller calls `buildRules('create')`. Common pattern: a
     * password field that's required on create but optional on update.
     *
     * @var list<string|Rule>
     */
    protected array $creationRules = [];

    /**
     * Rules that apply ONLY on `PUT /resources/{r}/{id}` (update
     * context). Merged the same way as `creationRules` but on the
     * update path.
     *
     * @var list<string|Rule>
     */
    protected array $updateRules = [];

    /**
     * Marks the field as immutable: writable on create, readonly on
     * every subsequent update. Set by `immutable()` — the controller
     * + schema honour it via the existing readonly path.
     */
    protected bool $immutable = false;

    /**
     * List of OTHER field attributes whose values this field reacts to.
     * Set by `dependsOn(array $fields, Closure $cb)`. The schema
     * surfaces this list so the frontend knows which inputs to watch
     * before posting back to `POST /resources/{r}/sync-field`.
     *
     * @var list<string>
     */
    protected array $dependentFields = [];

    /**
     * Reactivity callback. Runs at field-sync time with the current
     * form payload, the active Request, and a mutable reference to
     * `$this`. The callback should call any of the regular fluent
     * methods (`->required(...)`, `->readonly(...)`, `->placeholder(...)`,
     * `->options(...)`, `->withMeta(...)`, etc.) so the resolved
     * descriptor reflects the live form state.
     */
    protected ?\Closure $dependentCallback = null;

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

    protected ?\Closure $placeholderResolver = null;

    protected ?string $helpText = null;

    protected ?\Closure $helpResolver = null;

    /**
     * Inline tooltip content shown when the user hovers the (?) icon next to
     * the field label. Supports raw HTML (line breaks, bold, lists) so the
     * caller can build richer hints than a single-line help string.
     */
    protected ?string $tooltip = null;

    protected ?\Closure $tooltipResolver = null;

    protected ?\Closure $labelResolver = null;

    protected ?\Closure $rulesResolver = null;

    /** Whether the field spans the full width of the form. */
    protected bool $fullWidth = false;

    /** Whether the field label is stacked above (true) or inline (false). */
    protected bool $stacked = true;

    protected mixed $defaultValue = null;

    protected bool $hasDefault = false;

    /** Create a new field instance. */
    protected function __construct(
        protected readonly string $attribute,
        protected string $label,
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
        if ($this->labelResolver !== null) {
            $resolved = ($this->labelResolver)($this->safeRequest());

            return is_string($resolved) ? $resolved : $this->label;
        }

        return $this->label;
    }

    /**
     * Override the field label after construction.
     *
     * Accepts either a static string (replaces the constructor label) or a
     * closure that resolves at render time. Use the closure form when the
     * label depends on the locale, the authenticated user, or any other
     * request-scoped state.
     *
     *     Text::make('name')->withLabel('Full Name')
     *     Text::make('status')->withLabel(fn () => __('fields.status'))
     *
     * Named `withLabel()` rather than overloading `label()` because
     * `label()` is already the getter contract method.
     *
     * @param  string|\Closure(Request|null): string  $value
     */
    public function withLabel(string|\Closure $value): static
    {
        if ($value instanceof \Closure) {
            $this->labelResolver = $value;
        } else {
            $this->labelResolver = null;
            $this->label = $value;
        }

        return $this;
    }

    // -------------------------------------------------------------------------
    // FieldContract — value resolution and filling
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $attr = $attribute ?? $this->attribute;

        if ($this->resolveCallback !== null) {
            // 4th argument (Request|null) is optional — closures with 3
            // params still work because PHP accepts more args than declared.
            return ($this->resolveCallback)($model->getAttribute($attr), $model, $attr, $this->safeRequest());
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
            // 4th argument (Request|null) is optional — closures with 3
            // params still work because PHP accepts more args than declared.
            ($this->fillCallback)($model, $value, $this->attribute, $this->safeRequest());

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
            'attribute' => $this->attribute(),
            'label' => $this->label(),
            'type' => $this->type(),
            'nullable' => $this->isNullable(),
            'readonly' => $this->isReadonly(),
            'required' => $this->isRequired(),
            'sortable' => $this->sortable,
            'searchable' => $this->searchable,
            'showOnIndex' => $this->showOnIndex,
            'showOnDetail' => $this->showOnDetail,
            'showOnForms' => $this->showOnForms,
            'showOnCreate' => $this->showOnCreate,
            'showOnUpdate' => $this->showOnUpdate,
            'rules' => $this->buildRules(),
            'creationRules' => $this->creationRules !== [] ? $this->creationRules : null,
            'updateRules' => $this->updateRules !== [] ? $this->updateRules : null,
            'immutable' => $this->immutable,
            'dependsOn' => $this->isDependent() ? ['fields' => $this->dependentFields] : null,
            'component' => $this->componentKey,
            'placeholder' => $this->getPlaceholder(),
            'helpText' => $this->getHelp(),
            'tooltip' => $this->getTooltip(),
            'fullWidth' => $this->fullWidth,
            'stacked' => $this->stacked,
            'colSpan' => $this->colSpan,
            'colSpanMd' => $this->colSpanMd,
            'colSpanLg' => $this->colSpanLg,
            'defaultValue' => $this->getDefaultValue(),
            'overrides' => array_filter([
                'create' => $this->overrideForCreate?->toArray(),
                'update' => $this->overrideForUpdate?->toArray(),
                'index' => $this->overrideForIndex?->toArray(),
                'detail' => $this->overrideForDetail?->toArray(),
            ], fn (mixed $v): bool => $v !== null) ?: null,
            'column' => $this->resolveColumnWidth(),
        ], $this->extraAttributes(), $this->meta);
    }

    // -------------------------------------------------------------------------
    // FieldContract — visibility
    // -------------------------------------------------------------------------

    /**
     * Mark the field as nullable. Accepts a static `bool` (default `true`)
     * or a closure that decides at request time. When a closure is passed
     * it receives the active `Request` and must return a boolean.
     *
     *     Text::make('subtitle')->nullable()
     *     Text::make('comment')->nullable(fn ($r) => $r->user()->cannot('require-comment'))
     *
     * @param  bool|\Closure(Request|null): bool  $value
     */
    public function nullable(bool|\Closure $value = true): static
    {
        $this->nullableResolver = $value;
        if (is_bool($value)) {
            $this->nullable = $value;
        }

        return $this;
    }

    /**
     * Resolve the current nullable state — closures evaluate lazily so
     * the request, locale, authenticated user, and any other
     * request-scoped state is fresh.
     */
    public function isNullable(): bool
    {
        if ($this->nullableResolver instanceof \Closure) {
            $request = $this->safeRequest();

            return (bool) ($this->nullableResolver)($request);
        }

        return $this->nullable;
    }

    /**
     * Mark the field as readonly. Accepts a static `bool` (default
     * `true`) or a closure that decides at request time. When a
     * closure is passed, it receives the active `Request` and must
     * return a boolean.
     *
     *     Text::make('email')->readonly()
     *     Text::make('slug')->readonly(fn ($request) => $request->user()?->cannot('rename'))
     *
     * @param  bool|\Closure(Request): bool  $value
     */
    public function readonly(bool|\Closure $value = true): static
    {
        $this->readonlyResolver = $value;
        // Mirror the static value into the legacy boolean so existing
        // code paths that read `$this->readonly` directly still work.
        // Closure values defer until `isReadonly()` is called.
        if (is_bool($value)) {
            $this->readonly = $value;
        }

        return $this;
    }

    /**
     * Resolve the current readonly state — closures evaluate lazily
     * so the request, locale, authenticated user, and any other
     * request-scoped state is fresh.
     */
    public function isReadonly(): bool
    {
        if ($this->readonlyResolver instanceof \Closure) {
            $request = $this->safeRequest();

            return (bool) ($this->readonlyResolver)($request);
        }

        return $this->readonly;
    }

    /**
     * Resolve the active Request safely — returns null when the
     * container is not bootstrapped yet (raw PHPUnit tests, queue
     * workers without HTTP context, etc.).
     */
    protected function safeRequest(): ?Request
    {
        try {
            $resolved = function_exists('app') ? app('request') : null;

            return $resolved instanceof Request ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Mark the field as immutable: writable on create, readonly on
     * update. Equivalent to chaining `readonly()` only when an `id`
     * already exists, but expressed as a single intent so the schema
     * serializer can surface the right contract per context.
     */
    public function immutable(bool $value = true): static
    {
        $this->immutable = $value;

        return $this;
    }

    public function isImmutable(): bool
    {
        return $this->immutable;
    }

    // -------------------------------------------------------------------------
    // Reactive fields — dependsOn()
    // -------------------------------------------------------------------------

    /**
     * Declare a reactive dependency on one or more sibling fields.
     *
     * The frontend watches the listed `$fields` while the user edits the
     * form and, every time any of them changes, posts the live form
     * payload to `POST /resources/{r}/sync-field`. The controller
     * re-instantiates this field, runs `$callback` against the live
     * payload, and returns the updated descriptor. The frontend then
     * applies the new state (visibility, readonly, required,
     * placeholder, options, help, default, meta…) to the live form.
     *
     * The callback receives:
     *   - `array<string, mixed> $formData` — the current form values,
     *     keyed by field attribute (only the dependent fields are
     *     guaranteed to be present, but any sibling that has been
     *     touched is forwarded too)
     *   - `\Illuminate\Http\Request $request` — the active request
     *   - `static $field` — `$this`, mutable. The closure should call
     *     the regular fluent methods on it (e.g. `$field->required()`,
     *     `$field->readonly(true)`, `$field->placeholder('…')`,
     *     `$field->withMeta([...])`).
     *
     * Examples:
     *
     *     // Make `price` required only when `is_paid` is true
     *     Number::make('price')->dependsOn(['is_paid'],
     *         function (array $form, \Illuminate\Http\Request $r, $field) {
     *             $field->required((bool) ($form['is_paid'] ?? false));
     *         });
     *
     *     // Reload Select options whenever `category_id` changes
     *     Select::make('subcategory_id')->dependsOn(['category_id'],
     *         function (array $form, \Illuminate\Http\Request $r, $field) {
     *             $field->options(
     *                 \App\Models\Subcategory::query()
     *                     ->where('category_id', $form['category_id'] ?? null)
     *                     ->pluck('name', 'id')->all()
     *             );
     *         });
     *
     * @param  list<string>  $fields  Sibling attributes to watch.
     * @param  \Closure(array<string, mixed>, Request, static): void|null  $callback
     */
    public function dependsOn(array $fields, ?\Closure $callback = null): static
    {
        $this->dependentFields = array_values(array_unique(array_filter(
            array_map('strval', $fields),
            static fn (string $f): bool => $f !== '',
        )));
        $this->dependentCallback = $callback;

        return $this;
    }

    /**
     * Return the list of attributes this field reacts to.
     *
     * @return list<string>
     */
    public function dependentFields(): array
    {
        return $this->dependentFields;
    }

    /**
     * Whether `dependsOn()` was configured for this field.
     */
    public function isDependent(): bool
    {
        return $this->dependentCallback !== null && $this->dependentFields !== [];
    }

    /**
     * Run the reactivity callback against the supplied form payload.
     * Mutates `$this` in place. Returns `$this` for chaining.
     *
     * @param  array<string, mixed>  $formData
     */
    public function syncDependent(array $formData, Request $request): static
    {
        if ($this->dependentCallback !== null) {
            ($this->dependentCallback)($formData, $request, $this);
        }

        return $this;
    }

    /**
     * Mark the field as required. Accepts a static `bool` (default `true`)
     * or a closure that decides at request time. When a closure is passed
     * it receives the active `Request` and must return a boolean.
     *
     *     Text::make('email')->required()
     *     Text::make('reason')->required(fn ($r) => $r->user()->cannot('skip-reason'))
     *
     * @param  bool|\Closure(Request|null): bool  $value
     */
    public function required(bool|\Closure $value = true): static
    {
        $this->requiredResolver = $value;
        if (is_bool($value)) {
            $this->required = $value;
        }

        return $this;
    }

    /**
     * Resolve the current required state — closures evaluate lazily so
     * the request, locale, authenticated user, and any other
     * request-scoped state is fresh.
     */
    public function isRequired(): bool
    {
        if ($this->requiredResolver instanceof \Closure) {
            $request = $this->safeRequest();

            return (bool) ($this->requiredResolver)($request);
        }

        return $this->required;
    }

    /**
     * Set the placeholder text shown when the field is empty.
     *
     * Accepts either a static string or a closure that resolves at
     * render time. Use the closure form when the placeholder depends on
     * the locale, the authenticated user, or any other request-scoped
     * state.
     *
     *     Text::make('email')->placeholder('you@company.com')
     *     Text::make('greeting')->placeholder(fn () => __('fields.greeting.placeholder'))
     *
     * @param  string|\Closure(Request|null): string  $text
     */
    public function placeholder(string|\Closure $text): static
    {
        if ($text instanceof \Closure) {
            $this->placeholderResolver = $text;
        } else {
            $this->placeholderResolver = null;
            $this->placeholder = $text;
        }

        return $this;
    }

    /**
     * Resolve the current placeholder text — closures evaluate lazily.
     */
    public function getPlaceholder(): ?string
    {
        if ($this->placeholderResolver !== null) {
            $resolved = ($this->placeholderResolver)($this->safeRequest());

            return is_string($resolved) ? $resolved : null;
        }

        return $this->placeholder;
    }

    /**
     * Set help text displayed below the field input.
     *
     * Supports inline HTML for rich help text (links, bold, code).
     * Accepts either a static string or a closure that resolves at
     * render time.
     *
     *     Text::make('username')->help('Letters and numbers only.')
     *     Text::make('quota')->help(fn ($r) => "Quota left: {$r->user()->quota()}")
     *
     * @param  string|\Closure(Request|null): string  $text
     */
    public function help(string|\Closure $text): static
    {
        if ($text instanceof \Closure) {
            $this->helpResolver = $text;
        } else {
            $this->helpResolver = null;
            $this->helpText = $text;
        }

        return $this;
    }

    /**
     * Resolve the current help text — closures evaluate lazily.
     */
    public function getHelp(): ?string
    {
        if ($this->helpResolver !== null) {
            $resolved = ($this->helpResolver)($this->safeRequest());

            return is_string($resolved) ? $resolved : null;
        }

        return $this->helpText;
    }

    /**
     * Set the tooltip shown next to the field label via a (?) icon.
     *
     * Unlike `help()`, which renders inline below the input, the tooltip
     * only appears on hover — use it for context that is valuable but
     * would clutter the form if always visible.
     *
     * Raw HTML is allowed (line breaks, bold, lists) so callers can build
     * multi-line, formatted hints. The frontend opts in via the
     * `data-pr-tooltip-html` attribute on the trigger.
     *
     * Accepts either a static string or a closure that resolves at
     * render time.
     *
     * @param  string|\Closure(Request|null): ?string|null  $text
     */
    public function tooltip(string|\Closure|null $text): static
    {
        if ($text instanceof \Closure) {
            $this->tooltipResolver = $text;
        } else {
            $this->tooltipResolver = null;
            $this->tooltip = $text;
        }

        return $this;
    }

    public function getTooltip(): ?string
    {
        if ($this->tooltipResolver !== null) {
            $resolved = ($this->tooltipResolver)($this->safeRequest());

            return is_string($resolved) ? $resolved : null;
        }

        return $this->tooltip;
    }

    /**
     * Make the field span the full width of the form container.
     *
     * Equivalent to ->span(12) in a 12-column section, but works outside
     * of sections too.
     */
    public function fullWidth(bool $fullWidth = true): static
    {
        $this->fullWidth = $fullWidth;

        return $this;
    }

    /**
     * Control whether the field label is stacked above the input (true)
     * or displayed inline beside it (false).
     *
     * Default is stacked (true).
     */
    public function stacked(bool $stacked = true): static
    {
        $this->stacked = $stacked;

        return $this;
    }

    /**
     * Set a default value for the field on create forms.
     *
     * Accepts either a static value or a closure that receives the
     * active `Request` and returns the default. The closure form is
     * evaluated lazily on `getDefaultValue()` so the result honours
     * the current user / locale / environment.
     *
     *     Text::make('status')->default('active')
     *     BelongsTo::make('owner')->default(fn ($req) => $req->user()->id)
     */
    public function default(mixed $value): static
    {
        $this->defaultValue = $value;
        $this->hasDefault = true;

        return $this;
    }

    /**
     * Get the default value for this field. Resolves closures at the
     * moment of access — the request, locale, authenticated user, and
     * any other request-scoped state is fresh.
     */
    public function getDefaultValue(): mixed
    {
        if ($this->defaultValue instanceof \Closure) {
            return ($this->defaultValue)($this->safeRequest());
        }

        return $this->defaultValue;
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
    // Granular visibility flags
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
     * Layout containers (Panel, TabGroup) are flattened — use filterLayoutForContext()
     * when the full layout structure must be preserved (e.g. schema serialization).
     *
     * @param  list<FieldContract|LayoutContract>  $fields
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

        // If the input contains layout containers (Panel, TabGroup), flatten them
        // respecting context visibility before applying the field-level filter.
        $hasLayout = false;
        foreach ($fields as $item) {
            if ($item instanceof LayoutContract) {
                $hasLayout = true;
                break;
            }
        }
        if ($hasLayout) {
            $filtered = self::filterLayoutForContext($fields, $context, $request);

            return self::flattenLayoutFields($filtered);
        }

        /** @var list<FieldContract> $fields */
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

    /**
     * Filter a mixed array of fields and layout containers for a given context,
     * preserving the Panel and TabGroup structure.
     *
     * Unlike filterForContext(), this method keeps layout containers in the result and
     * drops containers that become empty after context filtering.
     * Use this when the full layout structure is needed (e.g., schema serialization).
     * Use flattenLayoutFields() on the result when validation or model filling is needed.
     *
     * @param  list<FieldContract|LayoutContract>  $items
     * @return list<FieldContract|LayoutContract>
     */
    public static function filterLayoutForContext(array $items, FieldContext $context, ?Request $request = null): array
    {
        if ($request === null) {
            try {
                $request = request();
            } catch (\Throwable) {
                // No request available (e.g. unit tests without HTTP context)
            }
        }

        $result = [];

        foreach ($items as $item) {
            if ($item instanceof LayoutContract) {
                $filtered = $item->filterForContext($context);
                if ($filtered !== null) {
                    $result[] = $filtered;
                }

                continue;
            }

            /** @var FieldContract $item */
            if (! $item->isVisibleForContext($context)) {
                continue;
            }

            if ($request !== null && $item instanceof self && ! $item->isAuthorizedToSee($request)) {
                continue;
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * Flatten a mixed array of fields and layout containers into a flat list of FieldContract items.
     *
     * Used for validation and model filling, where layout structure is irrelevant.
     *
     * @param  list<FieldContract|LayoutContract>  $items
     * @return list<FieldContract>
     */
    public static function flattenLayoutFields(array $items): array
    {
        $fields = [];

        foreach ($items as $item) {
            if ($item instanceof LayoutContract) {
                foreach ($item->flattenFields() as $f) {
                    $fields[] = $f;
                }
            } else {
                $fields[] = $item;
            }
        }

        return $fields;
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
    // Authorization — field-level visibility
    // -------------------------------------------------------------------------

    /**
     * Set a callback that determines whether this field is visible.
     *
     * The callback receives the current Request and should return a boolean.
     * When the callback returns false, the field is excluded from the response.
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
     * Set validation rules for this field.
     *
     * Accepts either a static `list<string|Rule>` or a closure that
     * receives the active `Request` and returns one. The closure form
     * is evaluated lazily at `buildRules()` time, so the rule set can
     * vary per user, role, or any other request-scoped state.
     *
     *     Text::make('email')->rules(['required', 'email'])
     *     Text::make('cap')->rules(fn ($r) => $r->user()->isAdmin()
     *         ? ['nullable']
     *         : ['required', 'integer', 'max:100']);
     *
     * Static and closure variants do NOT compose. The last call wins —
     * a later `rules(fn ...)` replaces a prior `rules([...])`, and a
     * later `rules([...])` clears any prior closure.
     *
     * @param  list<string|Rule>|\Closure(Request|null): list<string|Rule>  $rules
     */
    public function rules(array|\Closure $rules): static
    {
        if ($rules instanceof \Closure) {
            $this->rulesResolver = $rules;

            return $this;
        }

        $this->rulesResolver = null;
        $this->extraRules = array_merge($this->extraRules, $rules);

        return $this;
    }

    /**
     * Validation rules that apply ONLY on the create context (POST
     * /resources/{r}). Merged on top of `rules()` at validation time
     * when the controller calls `buildRules('create')`. Common pattern:
     * a `password` field that's `required` on create but `sometimes`
     * on update.
     *
     *     Password::make('password')
     *         ->rules(['nullable', 'min:8'])
     *         ->creationRules(['required'])
     *         ->updateRules(['sometimes']);
     *
     * @param  list<string|Rule>  $rules
     */
    public function creationRules(array $rules): static
    {
        $this->creationRules = array_merge($this->creationRules, $rules);

        return $this;
    }

    /**
     * Validation rules that apply ONLY on the update context
     * (PUT /resources/{r}/{id}). See `creationRules()` for usage.
     *
     * @param  list<string|Rule>  $rules
     */
    public function updateRules(array $rules): static
    {
        $this->updateRules = array_merge($this->updateRules, $rules);

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
     * Build the full validation rule list for the field.
     *
     * Pass `'create'` or `'update'` as the context to layer the
     * matching `creationRules()` / `updateRules()` on top. Without
     * a context (default), only the base + extraRules are returned —
     * matches the schema endpoint's pre-Task-09 behaviour, so frontend
     * consumers that introspect the schema keep working unchanged.
     *
     * @return list<string|Rule>
     */
    public function buildRules(?string $context = null): array
    {
        $rules = [];

        if ($this->isRequired()) {
            $rules[] = 'required';
        } elseif ($this->isNullable()) {
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

        // Resolve closure-based rules() lazily so the active request is
        // available. Static rules() and the closure form are mutually
        // exclusive — only one path is in effect at any time.
        $extraRules = $this->extraRules;
        if ($this->rulesResolver !== null) {
            $resolved = ($this->rulesResolver)($this->safeRequest());
            if (is_array($resolved)) {
                $extraRules = $resolved;
            }
        }

        $contextRules = match ($context) {
            'create' => $this->creationRules,
            'update' => $this->updateRules,
            default => [],
        };

        $merged = array_merge($rules, $extraRules, $contextRules);

        // `sometimes` short-circuits validation when the key is
        // missing — including `required`. When the context-specific
        // rules promote the field to required, strip `sometimes` from
        // the base so the missing-key case actually fails validation.
        if (in_array('required', $contextRules, true)) {
            $merged = array_values(array_filter($merged, static fn ($r) => $r !== 'sometimes'));
        }

        return $merged;
    }

    // -------------------------------------------------------------------------
    // Customization hooks (override in subclasses or at runtime)
    // -------------------------------------------------------------------------

    /**
     * Customize how the field value is resolved from the model.
     *
     * The callback receives `(mixed $value, Model $model, string $attribute, ?Request $request)`.
     * The `$request` parameter is optional — closures declared with three
     * parameters keep working unchanged.
     *
     * ⭐ Martis differential: the active `Request` is forwarded to the
     * callback so per-user / per-locale / per-tenant resolution does not
     * need to call the `request()` helper manually.
     *
     * @param  callable(mixed $value, Model $model, string $attribute, ?Request $request=): mixed  $callback
     */
    public function resolveUsing(callable $callback): static
    {
        $this->resolveCallback = $callback;

        return $this;
    }

    /**
     * Customize how incoming values are written to the model.
     *
     * The callback receives `(Model $model, mixed $value, string $attribute, ?Request $request)`.
     * The `$request` parameter is optional — closures declared with three
     * parameters keep working unchanged.
     *
     * ⭐ Martis differential: the active `Request` is forwarded to the
     * callback so per-user / per-locale / per-tenant write logic does not
     * need to call the `request()` helper manually.
     *
     * @param  callable(Model $model, mixed $value, string $attribute, ?Request $request=): void  $callback
     */
    public function fillUsing(callable $callback): static
    {
        $this->fillCallback = $callback;

        return $this;
    }

    /**
     * Customize how the field value is formatted for display (index + detail).
     *
     * Applied AFTER `resolveUsing()`. Does NOT affect form values.
     *
     * The callback receives `(mixed $value, Model $model, string $attribute, ?Request $request)`.
     * Closures declared with three parameters keep working unchanged.
     *
     * ⭐ Martis differential — chainable pipeline. Pass an array of
     * callbacks to compose multiple transformations: each callback
     * receives the output of the previous one. Equivalent in spirit to
     * `array_reduce`. The static / single-callable form is unchanged.
     *
     *     Text::make('amount')
     *         ->displayUsing([
     *             fn ($v) => (float) $v,
     *             fn ($v) => number_format($v, 2),
     *             fn ($v) => "$ {$v}",
     *         ]);
     *
     * @param  callable|list<callable>  $callback
     */
    public function displayUsing(callable|array $callback): static
    {
        if (is_array($callback)) {
            // Compose the array into a single closure that pipes the
            // value through each callback in order. Validates that
            // every entry is callable so we fail loud at definition
            // time, not deep inside `resolveForDisplay()`.
            foreach ($callback as $i => $cb) {
                if (! is_callable($cb)) {
                    throw new \InvalidArgumentException(
                        "displayUsing(): entry {$i} is not callable.",
                    );
                }
            }

            $pipeline = $callback;
            $this->displayCallback = function (mixed $value, Model $model, string $attribute, ?Request $request = null) use ($pipeline): mixed {
                foreach ($pipeline as $cb) {
                    $value = $cb($value, $model, $attribute, $request);
                }

                return $value;
            };

            return $this;
        }

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
            // 4th argument (Request|null) is optional — closures with 3
            // params still work because PHP accepts more args than declared.
            return ($this->displayCallback)($value, $model, $attribute ?? $this->attribute, $this->safeRequest());
        }

        return $value;
    }

    // -------------------------------------------------------------------------
    // Component override
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
    // Extension point — relationship field hooks
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
    // Table column widths (index view)
    // -------------------------------------------------------------------------

    /** Fixed column width in the index table (e.g. "80px", "10rem"). Null = auto. */
    protected ?string $columnWidth = null;

    /** Minimum column width in the index table. */
    protected ?string $columnMinWidth = null;

    /** Maximum column width in the index table. */
    protected ?string $columnMaxWidth = null;

    /**
     * Whether to truncate overflowing content with an ellipsis. Null =
     * not set (inherit type default); bool = explicit user override.
     */
    protected ?bool $columnTruncate = null;

    /**
     * Set a fixed column width for the index table (e.g. "80px", "10rem").
     *
     * Most tables should let columns size themselves. Reach for this when
     * a column holds a known-size token (an ID, a status pill) and you
     * want to stop it from eating room that a longer column could use.
     */
    public function width(string $value): static
    {
        $this->columnWidth = $value;

        return $this;
    }

    /** Set a minimum column width (e.g. "220px") for the index table. */
    public function minWidth(string $value): static
    {
        $this->columnMinWidth = $value;

        return $this;
    }

    /**
     * Set a maximum column width (e.g. "280px") and clip overflow.
     *
     * Pairs naturally with `truncate()` on long text columns (URLs,
     * emails) so one runaway row can't blow out the whole table.
     */
    public function maxWidth(string $value): static
    {
        $this->columnMaxWidth = $value;

        return $this;
    }

    /**
     * Truncate overflowing cell content with an ellipsis. Useful together
     * with `maxWidth()` on long text columns.
     */
    public function truncate(bool $value = true): static
    {
        $this->columnTruncate = $value;

        return $this;
    }

    /**
     * Resolve the effective column width metadata, blending explicit
     * `->width()` / `->minWidth()` / ... calls with per-type defaults
     * defined in `defaultColumnWidth()`. Explicit user calls always win.
     *
     * When `config('martis.index.column_defaults')` is false, the per-type
     * heuristics are skipped entirely — only explicit fluent calls apply.
     * Apps that want the pre-v0.7.0 fully auto-sizing behaviour set the
     * flag to false.
     *
     * @return array{width: ?string, minWidth: ?string, maxWidth: ?string, truncate: bool}
     */
    public function resolveColumnWidth(): array
    {
        // Resolve the config flag defensively so unit tests (which may not
        // boot the Laravel container) can still serialise a field without
        // tripping a BindingResolutionException. When no container is
        // available, fall back to the documented default of `true` and
        // apply the per-type heuristics.
        $useDefaults = true;
        if (function_exists('app')) {
            try {
                $container = app();
                if ($container->bound('config')) {
                    $useDefaults = (bool) $container['config']->get('martis.index.column_defaults', true);
                }
            } catch (\Throwable) {
                // Container not bootstrapped — keep $useDefaults = true.
            }
        }

        $defaults = $useDefaults ? $this->defaultColumnWidth() : [];

        return [
            'width' => $this->columnWidth ?? ($defaults['width'] ?? null),
            'minWidth' => $this->columnMinWidth ?? ($defaults['minWidth'] ?? null),
            'maxWidth' => $this->columnMaxWidth ?? ($defaults['maxWidth'] ?? null),
            'truncate' => $this->columnTruncate ?? ($defaults['truncate'] ?? false),
        ];
    }

    /**
     * Per-field-type defaults for the index column. Subclasses override
     * to declare "URL columns truncate at 280px" etc. without forcing
     * every resource to repeat the chainable calls.
     *
     * @return array{width?: string, minWidth?: string, maxWidth?: string, truncate?: bool}
     */
    protected function defaultColumnWidth(): array
    {
        return [];
    }

    /**
     * Shorthand for colSpan() — sets how many columns this field occupies in a Section grid.
     *
     * Designed for use with Section::columns(): define the grid on the Section,
     * and use span() on each field to control its width.
     *
     * Example:
     *   Section::make('Timeline', [
     *       Date::make('start_date')->span(6),
     *       Date::make('end_date')->span(6),
     *   ])->columns(12)
     */
    public function span(int $cols): static
    {
        return $this->colSpan($cols);
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

    /**
     * Resolve authorization flags exposed by a related resource.
     *
     * Relation fields (BelongsTo, HasMany, BelongsToMany, MorphTo…) call this
     * in `extraAttributes()` to tell the frontend whether the current user is
     * allowed to create/view the target resource. Returns an empty array when
     * the related resource is not registered, letting the frontend fall back
     * to its existing defaults.
     *
     * @return array<string, bool>
     */
    protected function relatedResourceAuthorizations(?string $relatedUriKey): array
    {
        if ($relatedUriKey === null || $relatedUriKey === '') {
            return [];
        }

        /** @var ResourceRegistry $registry */
        $registry = app(ResourceRegistry::class);

        if (! $registry->has($relatedUriKey)) {
            return [];
        }

        /** @var class-string<\Martis\Resource> $resourceClass */
        $resourceClass = $registry->get($relatedUriKey);
        $instance = new $resourceClass(null);
        $request = request();

        return [
            'authorizedToViewAny' => $instance->authorizedToViewAny($request),
            'authorizedToCreate' => $instance->authorizedToCreate($request),
        ];
    }
}
