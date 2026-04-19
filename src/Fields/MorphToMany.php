<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Fields\Concerns\ControlsRelationshipToolbar;
use Martis\Enums\ModalSize;

/**
 * MorphToMany polymorphic relationship field — Nova v5 parity.
 *
 * Renders as a full panel on the detail page showing a DataTable of attached
 * records with support for attach, detach, pivot fields, search, sort, and
 * pagination. Works like BelongsToMany but for polymorphic many-to-many
 * relationships (morphToMany/morphedByMany).
 *
 * The polymorphic pivot table has 3 columns: {morphName}_type, {morphName}_id,
 * and the foreign key of the related model.
 *
 * Usage:
 *   MorphToMany::make('Tags')
 *   MorphToMany::make('Tags', 'tags', TagResource::class)
 *   MorphToMany::make('Tags')->fields(fn() => [Text::make('notes', 'Notes')])
 *   MorphToMany::make('Tags')->searchable()->allowDuplicateRelations()
 *   MorphToMany::make('Tags')->relatableQueryUsing(fn($request, $q) => $q->where('active', 1))
 *   MorphToMany::make('Tags')->showCreateRelationButton()->modalSize(ModalSize::Large)
 *
 * @phpstan-consistent-constructor
 */
class MorphToMany extends Field
{
    use ControlsRelationshipToolbar;

    /** Eloquent relationship method name on the parent model. */
    protected string $relationship;

    /** URI key of the related resource (e.g. "tags"). */
    protected ?string $relatedResourceKey = null;

    /** Attribute on the related model used as the display title. */
    protected string $titleAttribute = 'name';

    /** Closure that returns extra pivot field definitions. */
    protected ?\Closure $pivotFieldsClosure = null;

    /** Closure that returns pivot action definitions. */
    protected ?\Closure $pivotActionsClosure = null;

    /** Whether the inline list supports search on attachable records. */
    protected bool $relationSearchable = true;

    /** Whether the panel is collapsable. */
    protected bool $collapsable = false;

    /** Whether the panel starts collapsed. */
    protected bool $collapsedByDefault = false;

    /** Whether duplicate relations are allowed (same related ID attached twice). */
    protected bool $allowDuplicates = false;

    /** Whether to show an inline create button in the attach modal. */
    protected bool|\Closure $showCreateRelationButton = false;

    /** Modal size for the attach modal. */
    protected ModalSize $modalSize = ModalSize::TwoExtraLarge;

    /** Optional modal height (CSS value like '70vh' or '500px'). */
    protected ?string $modalHeight = null;

    /** Closure to filter the relatable query (Nova v5: relatableQueryUsing). */
    protected ?\Closure $relatableQueryClosure = null;

    /** Whether to keep the original order of attachables (disable auto-sort). */
    protected bool $dontReorderAttachables = false;

    /** Whether to show subtitles in the attach modal search results. */
    protected bool $withSubtitles = false;

    /** The attribute on the related model to show as subtitle. */
    protected string $subtitleAttribute = 'subtitle';

    /** Per-page default for the inline listing. */
    protected int $relationPerPage = 10;

    /**
     * @var list<int>
     */
    protected array $relationPerPageOptions = [5, 10, 25, 50];

    /** Tracks whether the developer explicitly called `->perPageOptions([...])`.
     *  When false, the panel falls back to the related resource's own
     *  `perPageOptions()` — Nova-style "resource is the single source of truth". */
    protected bool $perPageOptionsSet = false;

    /** Whether to show attach button. */
    protected bool $canAttachRelated = true;

    /** Whether to show detach button. */
    protected bool $canDetachRelated = true;

    /** Create a new field instance. */
    protected function __construct(
        string $attribute,
        string $label,
        string $relationship = '',
    ) {
        parent::__construct($attribute, $label);
        $this->relationship = $relationship ?: Str::camel($attribute);

        // MorphToMany is hidden from index by default (shown on detail page)
        $this->hideFromIndex();
    }

    /**
     * Create a MorphToMany field.
     *
     * @param  string  $name  Display label (e.g. "Tags")
     * @param  string|null  $relationship  Explicit Eloquent relationship method name
     * @param  string|null  $relatedResourceClass  Optional: explicit related resource class
     */
    public static function make(string $name, ?string $relationship = null, ?string $relatedResourceClass = null): static
    {
        $label = $name;
        $rel = $relationship ?? Str::camel(Str::lower($name));

        $instance = new static($rel, $label, $rel);

        if ($relatedResourceClass !== null && method_exists($relatedResourceClass, 'uriKey')) {
            $instance->relatedResourceKey = $relatedResourceClass::uriKey();
        }

        return $instance;
    }

    /** {@inheritDoc} */
    public function type(): string
    {
        return 'morph_to_many';
    }

    /**
     * Set the related resource URI key explicitly.
     * If not set, inferred from the relationship name (pluralized, snake_case).
     */
    public function relatedResource(string $uriKey): static
    {
        $this->relatedResourceKey = $uriKey;

        return $this;
    }

    /**
     * Set the title attribute on the related model.
     * Defaults to "name".
     */
    public function titleAttribute(string $attribute): static
    {
        $this->titleAttribute = $attribute;

        return $this;
    }

    /**
     * Define extra pivot fields shown in the attach modal and as columns in the panel.
     *
     * @param  \Closure(): list<Field>  $closure
     */
    public function fields(\Closure $closure): static
    {
        $this->pivotFieldsClosure = $closure;

        return $this;
    }

    /**
     * Define pivot actions for selected rows in the panel.
     *
     * @param  \Closure(): list<mixed>  $closure
     */
    public function actions(\Closure $closure): static
    {
        $this->pivotActionsClosure = $closure;

        return $this;
    }

    /**
     * Enable search in the attach modal.
     *
     * Nova v5 parity: ->searchable()
     */
    public function searchable(bool $value = true): static
    {
        $this->relationSearchable = $value;

        return $this;
    }

    /**
     * Make the relationship panel collapsable.
     */
    public function collapsable(bool $value = true): static
    {
        $this->collapsable = $value;

        return $this;
    }

    /**
     * Make the relationship panel start collapsed.
     */
    public function collapsedByDefault(bool $value = true): static
    {
        $this->collapsedByDefault = $value;

        return $this;
    }

    /**
     * Allow the same related record to be attached more than once.
     *
     * Nova v5 parity: ->allowDuplicateRelations()
     */
    public function allowDuplicateRelations(bool $value = true): static
    {
        $this->allowDuplicates = $value;

        return $this;
    }

    /**
     * Enable the inline create button in the attach modal.
     *
     * Nova v5 parity: showCreateRelationButton() / showCreateRelationButton(fn($request) => ...)
     */
    public function showCreateRelationButton(bool|\Closure $callback = true): static
    {
        $this->showCreateRelationButton = $callback;

        return $this;
    }

    /** Explicitly hide the inline create button. */
    public function hideCreateRelationButton(): static
    {
        $this->showCreateRelationButton = false;

        return $this;
    }

    /**
     * Set the modal size and optional height for the attach modal.
     */
    public function modalSize(ModalSize $size, ?string $height = null): static
    {
        $this->modalSize = $size;
        $this->modalHeight = $height;

        return $this;
    }

    /**
     * Filter the list of attachable records.
     *
     * Nova v5 parity: ->relatableQueryUsing(fn($request, $query) => $query->where(...))
     *
     * @param  \Closure(Request, Builder<Model>): void  $closure
     */
    public function relatableQueryUsing(\Closure $closure): static
    {
        $this->relatableQueryClosure = $closure;

        return $this;
    }

    /**
     * Preserve original ordering of attachable records (skip alpha sort).
     */
    public function dontReorderAttachables(bool $value = true): static
    {
        $this->dontReorderAttachables = $value;

        return $this;
    }

    /**
     * Show subtitles in the attach modal search results.
     */
    public function withSubtitles(bool $value = true): static
    {
        $this->withSubtitles = $value;

        return $this;
    }

    /**
     * Set which attribute to use as subtitle. Implicitly enables withSubtitles.
     *
     * Nova v5 parity: ->subtitleAttribute('description')
     */
    public function subtitleAttribute(string $attribute): static
    {
        $this->subtitleAttribute = $attribute;
        $this->withSubtitles = true;

        return $this;
    }

    /** Set the default per-page for the inline listing. */
    public function perPage(int $perPage): static
    {
        $this->relationPerPage = $perPage;

        return $this;
    }

    /** Whether attach button is shown. */
    public function canAttach(bool $value = true): static
    {
        $this->canAttachRelated = $value;

        return $this;
    }

    /** Whether detach button is shown. */
    public function canDetach(bool $value = true): static
    {
        $this->canDetachRelated = $value;

        return $this;
    }

    /** Return the Eloquent relationship method name. */
    public function getRelationship(): string
    {
        return $this->relationship;
    }

    /** Return the related resource URI key (inferred from relationship name if not set). */
    public function getRelatedResourceKey(): ?string
    {
        return $this->relatedResourceKey ?? Str::snake(Str::plural($this->relationship), '-');
    }

    /**
     * Return the pivot fields.
     *
     * @return list<Field>
     */
    public function getPivotFields(): array
    {
        if ($this->pivotFieldsClosure === null) {
            return [];
        }

        return ($this->pivotFieldsClosure)();
    }

    /** Whether the inline create button should be shown. */
    public function isShowCreateRelationButton(): bool
    {
        if ($this->showCreateRelationButton instanceof \Closure) {
            return (bool) ($this->showCreateRelationButton)(request());
        }

        return $this->showCreateRelationButton;
    }

    /** Return the modal size enum. */
    public function getModalSize(): ModalSize
    {
        return $this->modalSize;
    }

    /** Return the relatableQueryUsing closure. */
    public function getRelatableQueryClosure(): ?\Closure
    {
        return $this->relatableQueryClosure;
    }

    /** Whether duplicate relations are allowed. */
    public function isAllowDuplicates(): bool
    {
        return $this->allowDuplicates;
    }

    /** Whether auto-sorting of attachables is disabled. */
    public function isDontReorderAttachables(): bool
    {
        return $this->dontReorderAttachables;
    }

    /**
     * Validate that the model defines a morphToMany relationship.
     *
     * @throws \InvalidArgumentException
     */
    public function validateRelationship(Model $model): void
    {
        if (! method_exists($model, $this->relationship)) {
            throw new \InvalidArgumentException(
                'Model '.get_class($model)." does not define relationship method '{$this->relationship}'."
            );
        }

        $relation = $model->{$this->relationship}();

        if (! $relation instanceof EloquentMorphToMany) {
            throw new \InvalidArgumentException(
                "Relationship '{$this->relationship}' on ".get_class($model).' is not a morphToMany relationship.'
            );
        }
    }

    /**
     * Resolve returns null on detail (data fetched via endpoints).
     * On index, returns the count of related records.
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        if ($this->showOnIndex) {
            return $model->{$this->relationship}()->count();
        }

        return null;
    }

    /**
     * Fill is a no-op — MorphToMany records are managed via attach/detach endpoints.
     */
    public function fill(Model $model, mixed $value): void
    {
        // No-op: MorphToMany records are managed independently via endpoints
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        $pivotFields = [];
        foreach ($this->getPivotFields() as $pf) {
            $pivotFields[] = $pf->toArray();
        }

        $relatedAuth = $this->relatedResourceAuthorizations($this->getRelatedResourceKey());
        $authorizedToCreate = $relatedAuth['authorizedToCreate'] ?? true;

        return [
            'relationship' => $this->relationship,
            'relatedResource' => $this->getRelatedResourceKey(),
            'titleAttribute' => $this->titleAttribute,
            'searchable' => $this->relationSearchable,
            'collapsable' => $this->collapsable,
            'collapsedByDefault' => $this->collapsedByDefault,
            'allowDuplicateRelations' => $this->allowDuplicates,
            'showCreateRelationButton' => $this->isShowCreateRelationButton() && $authorizedToCreate,
            'modalSize' => $this->modalSize->value,
            'modalHeight' => $this->modalHeight,
            'withSubtitles' => $this->withSubtitles,
            'subtitleAttribute' => $this->withSubtitles ? $this->subtitleAttribute : null,
            'pivotFields' => $pivotFields,
            'morphToManyMeta' => [
                'perPage' => $this->resolvePerPage(),
                'perPageOptions' => $this->resolvePerPageOptions(),
                'canAttach' => $this->canAttachRelated,
                'canDetach' => $this->canDetachRelated,
            ] + $this->relationshipToolbarControls(),
        ] + $relatedAuth;
    }

    /**
     * Per-page options for the inline listing.
     *
     * Nova v5 parity: ->perPageOptions([10, 25, 50])
     *
     * @param  list<int>  $options
     */
    public function perPageOptions(array $options): static
    {
        $this->relationPerPageOptions = $options;
        $this->perPageOptionsSet = true;

        return $this;
    }

    /**
     * Resolve the per-page options for the inline listing. Priority:
     *   1. Developer-supplied via `->perPageOptions([...])` on the field.
     *   2. The related resource's own `perPageOptions()` method.
     *   3. The field's hardcoded default `[5, 10, 25, 50]`.
     *
     * @return list<int>
     */
    protected function resolvePerPageOptions(): array
    {
        if ($this->perPageOptionsSet) {
            return $this->relationPerPageOptions;
        }

        $uriKey = $this->getRelatedResourceKey();
        if ($uriKey !== null) {
            try {
                /** @var \Martis\ResourceRegistry $registry */
                $registry = app(\Martis\ResourceRegistry::class);
                if ($registry->has($uriKey)) {
                    /** @var class-string<\Martis\Resource> $class */
                    $class = $registry->get($uriKey);

                    return $class::perPageOptions();
                }
            } catch (\Throwable) {
                // Registry unavailable (rare) — fall through to field default.
            }
        }

        return $this->relationPerPageOptions;
    }

    /**
     * Resolve the effective per-page for the inline listing. Clamps to
     * the resolved `perPageOptions` when the configured `perPage` is
     * missing from the option list (Option A).
     */
    protected function resolvePerPage(): int
    {
        $options = $this->resolvePerPageOptions();
        if ($options === [] || in_array($this->relationPerPage, $options, true)) {
            return $this->relationPerPage;
        }

        return $options[0];
    }
}
