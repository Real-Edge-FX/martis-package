<?php

namespace Martis\Fields;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Contracts\FieldContract;
use Martis\Contracts\FiltersFields;
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
     * Search priority — higher values rank matches in this field above
     * matches in lower-priority fields. The Global Search resolver
     * uses this when ranking LIKE results: a `priority(2)` field beats
     * a `priority(1)` field on the same query. Default 1.
     */
    protected int $searchPriority = 1;

    /**
     * Callback that determines whether this field is visible to the current user.
     */
    protected ?\Closure $canSeeCallback = null;

    /**
     * Per-model visibility callback. v1.8.8. Accepts `(Request, Model)`.
     * When set and the closure returns false for a record, the field is
     * left out of that record's values on every read, and every write of
     * that record neither validates nor writes it (see `filterForModel()`).
     * Differs from `canSeeCallback` (which is per-request, model-agnostic):
     * this one supports field-level authorization that depends on the record.
     */
    protected ?\Closure $canSeeForModelCallback = null;

    /** @var callable|null */
    protected mixed $resolveCallback = null;

    /** @var callable|null */
    protected mixed $fillCallback = null;

    /** @var callable|null */
    protected mixed $displayCallback = null;

    /**
     * Whether the field is computed: its value never comes from
     * `$model->getAttribute()`. See `computed()`.
     */
    protected bool $computed = false;

    /**
     * Value source of a computed field. Receives `(Model, string $attribute,
     * ?Request)`. Null when `computed()` was called without a callback.
     *
     * @var callable|null
     */
    protected mixed $computedCallback = null;

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

    /** {@inheritdoc} */
    public static function make(string $attribute, ?string $label = null): static
    {
        return new static($attribute, $label ?? Str::title(str_replace('_', ' ', $attribute)));
    }

    // -------------------------------------------------------------------------
    // FieldContract — identity
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function attribute(): string
    {
        return $this->attribute;
    }

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $attr = $attribute ?? $this->attribute;
        $value = $this->resolveAttribute($model, $attr);

        if ($this->resolveCallback !== null) {
            // 4th argument (Request|null) is optional — closures with 3
            // params still work because PHP accepts more args than declared.
            return ($this->resolveCallback)($value, $model, $attr, $this->safeRequest());
        }

        return $value;
    }

    /**
     * Read the raw value that feeds `resolve()`: the computed callback (or
     * null) when the field is computed, `$model->getAttribute()` otherwise.
     *
     * Subclasses that re-implement `resolve()` must read the model through
     * this seam. A direct `getAttribute()` call would make `computed()`
     * silently ineffective for that field type: Eloquent treats an attribute
     * name that matches a model method as a relationship lookup and throws
     * when the method returns anything else.
     */
    protected function resolveAttribute(Model $model, string $attribute): mixed
    {
        if ($this->computed) {
            return $this->computedCallback !== null
                ? ($this->computedCallback)($model, $attribute, $this->safeRequest())
                : null;
        }

        return $model->getAttribute($attribute);
    }

    /** {@inheritdoc} */
    public function fill(Model $model, mixed $value): void
    {
        if ($this->isReadonly()) {
            return;
        }

        if ($this->fillCallback !== null) {
            // 4th argument (Request|null) is optional — closures with 3
            // params still work because PHP accepts more args than declared.
            ($this->fillCallback)($model, $value, $this->attribute, $this->safeRequest());

            return;
        }

        // A computed field has no backing attribute to write. Only an
        // explicit fillUsing() (handled above) may translate it into writes.
        if ($this->computed) {
            return;
        }

        $model->setAttribute($this->attribute, $value);
    }

    /**
     * Whether the form submits this field's value as a structure (a list or
     * a map) rather than a scalar.
     *
     * A form that uploads a file is sent as `multipart/form-data`, and
     * FormData carries strings only, so the SPA JSON-encodes every list or
     * map value on that path (`buildFormData()` in `lib/api.ts`). Fields
     * that return `true` here get that string decoded back by the resource
     * controllers before validation and fill (see
     * `DecodesStructuredValues`), so rules such as `array` and the field's
     * `fill()` see the same shape the JSON request path sends.
     */
    public function hasStructuredValue(): bool
    {
        return false;
    }

    /**
     * Whether a value that is not a list or a map fails validation instead
     * of reaching `fill()`: a string that is not JSON for one, such as the
     * `"[object Object]"` / `"12,15"` a pre-1.37.3 bundle sent on the
     * multipart path (see `DecodesStructuredValues`).
     *
     * True for a field with a structured value that the package fills
     * itself, because the built-in fills would empty or overwrite what is
     * stored. False when nothing would be written from the value (a
     * readonly or computed field) and when a `fillUsing()` callback owns the
     * write, since the callback decides which shapes it accepts. Override
     * it on a custom field whose `fill()` ignores such a value.
     */
    public function rejectsUnstructuredValue(): bool
    {
        return $this->hasStructuredValue()
            && $this->fillCallback === null
            && ! $this->computed
            && ! $this->isReadonly();
    }

    /**
     * Encode a structured value for storage unless the model's cast will.
     *
     * Fields that persist a list or a map (`MultiSelect`, `KeyValue`, the
     * multiple-file modes of `File` / `Image`) used to `json_encode()` the
     * value themselves before `setAttribute()`. When the attribute carries a
     * JSON-family cast (`array`, `json`, `object`, `collection`, their
     * `encrypted:` variants) or a class cast (`AsArrayObject`, `AsCollection`,
     * `AsEnumCollection`, any `Castable`), Eloquent's `set()` step encodes the
     * value again and the column ends up holding a JSON string *of* a JSON
     * string, unreadable through the cast from the next read on. Such a cast
     * receives the PHP array here and serialises it once; an uncast column
     * still gets the single-encoded JSON string it always did.
     *
     * `null` is passed through untouched on both paths.
     *
     * @param  array<mixed>|null  $value
     * @return string|array<mixed>|null
     */
    protected function storableStructuredValue(Model $model, string $attribute, ?array $value): string|array|null
    {
        if ($value === null || $this->modelSerialisesStructuredValue($model, $attribute)) {
            return $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * Whether Eloquent serialises a structured value for this attribute on
     * write. Mirrors the model's own `isJsonCastable()` / `isClassCastable()`
     * checks, which are protected: the native JSON-family cast list is the
     * one Eloquent keeps, and a class cast is any cast whose caster segment
     * (the part before an optional `:arguments` suffix, e.g.
     * `AsCollection::using(...)`) names a class.
     */
    protected function modelSerialisesStructuredValue(Model $model, string $attribute): bool
    {
        if (! $model->hasCast($attribute)) {
            return false;
        }

        if ($model->hasCast($attribute, [
            'array', 'json', 'object', 'collection',
            'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object',
        ])) {
            return true;
        }

        $cast = (string) ($model->getCasts()[$attribute] ?? '');
        $caster = str_contains($cast, ':') ? explode(':', $cast, 2)[0] : $cast;

        return $caster !== '' && class_exists($caster);
    }

    // -------------------------------------------------------------------------
    // FieldContract — serialization
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
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
            // Surface the watched field list whenever the developer
            // declared one — even if no callback was attached. The
            // sync-field path still keys on isDependent() (callback +
            // fields), but pickers like BelongsToMany use the schema
            // entry alone to forward `?form[*]` query params for
            // `relatableQueryUsing`. v1.8.3.
            'dependsOn' => $this->dependentFields !== [] ? ['fields' => $this->dependentFields] : null,
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

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
    public function dependentFields(): array
    {
        return $this->dependentFields;
    }

    /** {@inheritdoc} */
    public function isDependent(): bool
    {
        return $this->dependentCallback !== null && $this->dependentFields !== [];
    }

    /** {@inheritdoc} */
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

        if ($this->required) {
            return true;
        }

        // v1.8.3 — Auto-detect when `->rules([...])` declares the
        // `required` validator (or any of its conditional siblings).
        // The visual asterisk now follows the validation contract
        // automatically, so consumers no longer have to repeat
        // `->required()` next to `->rules(['required', ...])`.
        return $this->rulesHaveRequired();
    }

    /**
     * Cheap scan over the configured base + creation + update rules to
     * detect any of Laravel's "required" validators. Treats both string
     * shorthand (`required`, `required_if`, `required_with`, etc) and
     * the `Rule` instances that ship in `Illuminate\Validation\Rules`.
     */
    protected function rulesHaveRequired(): bool
    {
        // Only the base `extraRules` bag is consulted. `creationRules`
        // and `updateRules` are scoped to one context — letting the
        // visual `required` flag flip on the index/detail pages just
        // because the field is required during create would mislead
        // the operator. Consumers who want both the explicit asterisk
        // AND a context-scoped validation rule call `->required()`
        // separately. v1.8.3.
        foreach ($this->extraRules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'required')) {
                return true;
            }
            if (is_object($rule) && method_exists($rule, '__toString')) {
                $repr = (string) $rule;
                if (str_contains($repr, 'required')) {
                    return true;
                }
            }
        }

        return false;
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

    /** {@inheritdoc} */
    public function showOnIndex(): static
    {
        $this->showOnIndex = true;

        return $this;
    }

    /** {@inheritdoc} */
    public function hideFromIndex(): static
    {
        $this->showOnIndex = false;

        return $this;
    }

    /** {@inheritdoc} */
    public function showOnDetail(): static
    {
        $this->showOnDetail = true;

        return $this;
    }

    /** {@inheritdoc} */
    public function hideFromDetail(): static
    {
        $this->showOnDetail = false;

        return $this;
    }

    /** {@inheritdoc} */
    public function showOnForms(): static
    {
        $this->showOnForms = true;

        return $this;
    }

    /** {@inheritdoc} */
    public function hideFromForms(): static
    {
        $this->showOnForms = false;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Granular visibility flags
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function hideWhenCreating(): static
    {
        $this->showOnCreate = false;

        return $this;
    }

    /** {@inheritdoc} */
    public function hideWhenUpdating(): static
    {
        $this->showOnUpdate = false;

        return $this;
    }

    /** {@inheritdoc} */
    public function showOnCreating(): static
    {
        $this->showOnCreate = true;

        return $this;
    }

    /** {@inheritdoc} */
    public function showOnUpdating(): static
    {
        $this->showOnUpdate = true;

        return $this;
    }

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
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
     * {@inheritdoc}
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
     * `$items` without the fields `$keep` rejects, the layout structure
     * kept: a container (Panel, Section, TabGroup and its Tabs) holds only
     * the fields it keeps, at every depth, and is left out when it keeps
     * none. A custom container that cannot rebuild itself (it does not
     * implement `FiltersFields`) gives way to the fields it keeps.
     *
     * No context rule applies, unlike filterLayoutForContext(): the Tool
     * fields endpoint drops the fields the user cannot see with it, and the
     * schema drops from the create form the fields hidden for the new model.
     *
     * @param  list<FieldContract|LayoutContract>  $items
     * @param  \Closure(FieldContract): bool  $keep
     * @return list<FieldContract|LayoutContract>
     */
    public static function filterLayoutFields(array $items, \Closure $keep): array
    {
        $result = [];

        foreach ($items as $item) {
            if ($item instanceof FiltersFields) {
                $filtered = $item->filterFields($keep);
                if ($filtered !== null) {
                    $result[] = $filtered;
                }

                continue;
            }

            if ($item instanceof LayoutContract) {
                foreach ($item->flattenFields() as $field) {
                    if ($keep($field)) {
                        $result[] = $field;
                    }
                }

                continue;
            }

            if ($keep($item)) {
                $result[] = $item;
            }
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

    /** {@inheritdoc} */
    public function isShownOnIndex(): bool
    {
        return $this->showOnIndex;
    }

    /** {@inheritdoc} */
    public function isShownOnDetail(): bool
    {
        return $this->showOnDetail;
    }

    /** {@inheritdoc} */
    public function isShownOnForms(): bool
    {
        return $this->showOnForms;
    }

    // -------------------------------------------------------------------------
    // Authorization — field-level visibility
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function canSee(callable $callback): static
    {
        $this->canSeeCallback = $callback(...);

        return $this;
    }

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
    public function isAuthorizedToSee(Request $request): bool
    {
        if ($this->canSeeCallback === null) {
            return true;
        }

        return (bool) ($this->canSeeCallback)($request);
    }

    /**
     * Per-model visibility callback (v1.8.8).
     *
     * Use it when whether the field should appear depends on the record,
     * not just on the user. Common case: hiding a user's `email` from
     * everyone but admins and that user.
     *
     * The callback receives `(Request $request, Model $model)` and
     * returns `bool`. When false, the field is hidden for that record, as
     * `canSee()` hides it for the request: every read of the record leaves
     * its value out, and every write of the record neither validates it nor
     * takes its value from the request (see `filterForModel()`). A create
     * decides on the new, unsaved model before any value is written to it;
     * a pivot field decides on the pivot row.
     */
    public function canSeeForModel(callable $callback): static
    {
        $this->canSeeForModelCallback = $callback(...);

        return $this;
    }

    /**
     * Sugar over `canSeeForModel()` that delegates to a Laravel Gate
     * ability evaluated against the current model and request user.
     *
     *     Email::make('email')->canSeeUsingPolicy('viewEmail');
     *
     * Equivalent to:
     *
     *     ->canSeeForModel(fn (Request $r, Model $m) => $r->user()?->can('viewEmail', $m) ?? false)
     */
    public function canSeeUsingPolicy(string $ability): static
    {
        return $this->canSeeForModel(function (Request $request, Model $model) use ($ability): bool {
            $user = $request->user();
            if ($user === null) {
                return false;
            }

            return (bool) $user->can($ability, $model);
        });
    }

    /**
     * Resolve per-model visibility. Returns true when no per-model callback
     * is set (most fields). When set, runs the closure with the active
     * request + model.
     */
    public function isAuthorizedForModel(Request $request, Model $model): bool
    {
        if ($this->canSeeForModelCallback === null) {
            return true;
        }

        return (bool) ($this->canSeeForModelCallback)($request, $model);
    }

    /**
     * The fields of `$fields` the user may see on `$model`: all of them but
     * the ones a `canSeeForModel()` / `canSeeUsingPolicy()` callback hides
     * for that record (see `isAuthorizedForModel()`). A field without such a
     * callback is kept.
     *
     * Every read of a record gives the values of these fields only, and
     * every write of a record validates and fills these fields only, so a
     * field hidden for the record is written like one the user cannot see
     * (`canSee()`): an update leaves its column alone, a create does not
     * write it. `$model` is the record read or updated, or on a create the
     * new, unsaved model the create fills, before any value is written to it
     * (Nova resolves the fields of a create on a fresh model too).
     *
     * @template TField of FieldContract
     *
     * @param  list<TField>  $fields
     * @return list<TField>
     */
    public static function filterForModel(array $fields, Request $request, Model $model): array
    {
        return array_values(array_filter(
            $fields,
            static fn (FieldContract $field): bool => ! method_exists($field, 'isAuthorizedForModel')
                || $field->isAuthorizedForModel($request, $model),
        ));
    }

    // -------------------------------------------------------------------------
    // Sortable / Searchable
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function sortable(bool $value = true): static
    {
        $this->sortable = $value;

        return $this;
    }

    /** {@inheritdoc} */
    public function searchable(bool $value = true): static
    {
        $this->searchable = $value;

        return $this;
    }

    /**
     * Mark this field searchable AND assign a relative ranking weight.
     *
     * Sugar for `->searchable(true)` plus a `searchPriority`. Higher
     * weights rank matches in this field above matches in lower-weight
     * fields when the Global Search resolver runs a LIKE pipeline.
     * Typical values: `1` (default — body/long fields), `2` (titles,
     * names), `3` (canonical identifiers like email or sku).
     *
     * Example:
     *
     *     Text::make('name')->searchable()->searchPriority(2);
     *     Text::make('email')->searchable()->searchPriority(3);
     *     Textarea::make('notes')->searchable();   // priority 1
     */
    public function searchPriority(int $priority): static
    {
        $this->searchPriority = max(1, $priority);

        return $this;
    }

    /** {@inheritdoc} */
    public function getSearchPriority(): int
    {
        return $this->searchPriority;
    }

    /** {@inheritdoc} */
    public function isSortable(): bool
    {
        return $this->sortable;
    }

    /**
     * The attributes a request may sort a list by among `$items` (layout
     * containers opened): those of the `sortable()` fields the user may see
     * (`canSee()`). A field the user cannot see does not order a list for
     * them, since the order of the rows would tell the order of its values:
     * a `?sort=` naming it is ignored like one naming an unknown attribute.
     *
     * `canSeeForModel()` is not asked: a list spans many records, and a
     * field it hides on some of them still orders the list.
     *
     * @param  list<FieldContract|LayoutContract>  $items
     * @return list<string>
     */
    public static function sortableAttributes(array $items, Request $request): array
    {
        $attributes = [];

        foreach (self::flattenLayoutFields($items) as $field) {
            if ($field->isSortable() && $field->isAuthorizedToSee($request)) {
                $attributes[] = $field->attribute();
            }
        }

        return array_values(array_unique($attributes));
    }

    /** {@inheritdoc} */
    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    /**
     * The fields of `$items` (layout containers opened) a search matches
     * its term on: the `searchable()` fields the user may see (`canSee()`).
     * A field the user cannot see is not searched for them, since the rows
     * a term returns would tell which records hold it in that field: a
     * `field:value` token naming it is dropped like one naming an unknown
     * field.
     *
     * `canSeeForModel()` is not asked: a search spans many records, and a
     * field it hides on some of them is still searched.
     *
     * @param  list<FieldContract|LayoutContract>  $items
     * @return list<FieldContract>
     */
    public static function searchableFields(array $items, Request $request): array
    {
        return array_values(array_filter(
            self::flattenLayoutFields($items),
            static fn (FieldContract $field): bool => $field->isSearchable() && $field->isAuthorizedToSee($request),
        ));
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

    /** {@inheritdoc} */
    public function buildRules(?string $context = null): array
    {
        $rules = [];

        // Resolve extra rules early so the duplicate-required guard below can
        // inspect the full resolved set (handles both static and closure forms).
        $extraRules = $this->extraRules;
        if ($this->rulesResolver !== null) {
            $resolved = ($this->rulesResolver)($this->safeRequest());
            if (is_array($resolved)) {
                $extraRules = $resolved;
            }
        }

        // Only prepend 'required' when it is not already present in the
        // resolved extra-rules bag — prevents duplicate ['required', 'required']
        // when a developer writes ->rules(['required', 'email']).
        $extraRulesHaveRequired = in_array('required', $extraRules, true);

        if ($extraRulesHaveRequired) {
            // 'required' is already in the explicit rules — do not add a
            // duplicate or a conflicting 'nullable'/'sometimes' modifier.
        } elseif ($this->isRequired()) {
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

        $contextRules = match ($context) {
            'create' => $this->creationRules,
            'update' => $this->updateRules,
            default => [],
        };

        /** @var list<string|Rule|\Closure> $merged */
        $merged = array_values(array_merge($rules, $extraRules, $contextRules));

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
     * Mark the field as computed: its value never comes from
     * `$model->getAttribute()`, so the attribute name may have no backing
     * column, or shadow a model method (Eloquent would otherwise treat
     * `getAttribute('mode')` as a relationship lookup and throw when
     * `mode()` returns a non-relation).
     *
     * With a callback, the callback is the value source and receives
     * `(Model $model, string $attribute, ?Request $request)`; trailing
     * arguments are optional, as with every other hook. Without one, the
     * raw value is `null`: pair it with `resolveUsing()`, which then
     * receives `null` as its `$value`. Either way the computed value flows
     * through the usual `resolveUsing()` → `displayUsing()` pipeline.
     *
     * A computed field has nothing to write back, so it is hidden from the
     * create and update forms (only the general flag: `showOnForms()`,
     * `showOnCreating()` or `showOnUpdating()` called afterwards re-enable
     * it) and `fill()` is a no-op unless a `fillUsing()` callback is set.
     *
     * A computed field has no column: do not mark it `sortable()` or
     * `searchable()`, and do not point a filter at its attribute.
     *
     *     Badge::make('mode', 'Mode')
     *         ->computed(fn (Channel $model): string => $model->mode()->value)
     *         ->map(['push' => 'info', 'feed' => 'success']);
     *
     * @param  (callable(Model, string, ?Request): mixed)|null  $callback
     */
    public function computed(?callable $callback = null): static
    {
        $this->computed = true;
        $this->computedCallback = $callback;
        $this->hideFromForms();

        return $this;
    }

    /** Whether the field is computed (see `computed()`). */
    public function isComputed(): bool
    {
        return $this->computed;
    }

    /** {@inheritdoc} */
    public function resolveUsing(callable $callback): static
    {
        $this->resolveCallback = $callback;

        return $this;
    }

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
    public function component(string $key): static
    {
        $this->componentKey = $key;

        return $this;
    }

    /** {@inheritdoc} */
    public function getComponentKey(): ?string
    {
        return $this->componentKey;
    }

    // -------------------------------------------------------------------------
    // Per-context field overrides
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function overrideCreate(OverrideContract $override): static
    {
        $this->overrideForCreate = $override;

        return $this;
    }

    /** {@inheritdoc} */
    public function overrideUpdate(OverrideContract $override): static
    {
        $this->overrideForUpdate = $override;

        return $this;
    }

    /** {@inheritdoc} */
    public function overrideIndex(OverrideContract $override): static
    {
        $this->overrideForIndex = $override;

        return $this;
    }

    /** {@inheritdoc} */
    public function overrideDetail(OverrideContract $override): static
    {
        $this->overrideForDetail = $override;

        return $this;
    }

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
    public function colSpan(int $cols): static
    {
        $this->colSpan = max(1, min(12, $cols));

        return $this;
    }

    /** {@inheritdoc} */
    public function colSpanMd(int $cols): static
    {
        $this->colSpanMd = max(1, min(12, $cols));

        return $this;
    }

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
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
