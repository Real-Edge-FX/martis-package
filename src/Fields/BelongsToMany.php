<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Enums\ModalSize;

/**
 * BelongsToMany relationship field — full Nova v5 parity.
 *
 * Renders as a full panel on the detail page showing a DataTable of attached
 * records with support for attach, detach, pivot fields, search, sort, and
 * pagination. On the index page, shows a compact count badge.
 *
 * Usage:
 *   BelongsToMany::make('Tags')
 *   BelongsToMany::make('Tags', 'tags', TagResource::class)
 *   BelongsToMany::make('Tags')->fields(fn() => [Text::make('notes', 'Notes')])
 *   BelongsToMany::make('Tags')->searchable()->collapsable()->allowDuplicateRelations()
 *   BelongsToMany::make('Tags')->showCreateRelationButton()->modalSize(ModalSize::Large)
 *   BelongsToMany::make('Tags')->relatableQueryUsing(fn($request, $q) => $q->where('active', 1))
 *
 * @phpstan-consistent-constructor
 */
class BelongsToMany extends Field
{
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
    protected bool $relationSearchable = false;

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

    /** Closure to filter the relatable query (Nova v5: relatableQueryUsing). */
    protected ?\Closure $relatableQueryClosure = null;

    /** Whether to keep the original order of attachables (disable auto-sort). */
    protected bool $dontReorderAttachables = false;

    /** Whether to show subtitles in the attach modal search results. */
    protected bool $withSubtitles = false;

    /** Per-page default for the inline listing. */
    protected int $relationPerPage = 10;

    /**
     * @var list<int>
     */
    protected array $relationPerPageOptions = [5, 10, 25, 50];

    /** Whether to show attach button. */
    protected bool $canAttach = true;

    /** Whether to show detach button. */
    protected bool $canDetach = true;

    protected function __construct(
        string $attribute,
        string $label,
        string $relationship = '',
    ) {
        parent::__construct($attribute, $label);
        $this->relationship = $relationship ?: Str::camel($attribute);

        // BelongsToMany is detail-only by default (Nova v5 behavior)
        $this->hideFromIndex();
        $this->hideFromForms();
    }

    /**
     * Create a BelongsToMany field.
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
        return 'belongs_to_many';
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
     * Define pivot fields for the relationship.
     * These are fields stored on the pivot table.
     *
     * Nova v5 parity: ->fields(fn() => [Text::make('notes', 'Notes')])
     *
     * @param  \Closure(): list<Field>  $closure
     */
    public function fields(\Closure $closure): static
    {
        $this->pivotFieldsClosure = $closure;

        return $this;
    }

    /**
     * Define pivot actions for attached records.
     *
     * Nova v5 parity: ->actions(fn() => [MyAction::make()])
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
     * Make the panel collapsable.
     *
     * Nova v5 parity: ->collapsable()
     */
    public function collapsable(bool $value = true): static
    {
        $this->collapsable = $value;

        return $this;
    }

    /**
     * Start the panel collapsed by default.
     *
     * Nova v5 parity: ->collapsedByDefault()
     */
    public function collapsedByDefault(bool $value = true): static
    {
        $this->collapsable = true;
        $this->collapsedByDefault = $value;

        return $this;
    }

    /**
     * Allow attaching the same related record multiple times.
     *
     * Nova v5 parity: ->allowDuplicateRelations()
     */
    public function allowDuplicateRelations(bool $value = true): static
    {
        $this->allowDuplicates = $value;

        return $this;
    }

    /**
     * Show an inline create button in the attach modal.
     *
     * Nova v5 parity: ->showCreateRelationButton()
     */
    public function showCreateRelationButton(bool|\Closure $callback = true): static
    {
        $this->showCreateRelationButton = $callback;

        return $this;
    }

    /**
     * Explicitly hide the inline create button.
     *
     * Nova v5 parity: ->hideCreateRelationButton()
     */
    public function hideCreateRelationButton(): static
    {
        $this->showCreateRelationButton = false;

        return $this;
    }

    /**
     * Set the modal size for the attach dialog.
     *
     * Nova v5 parity: ->modalSize('lg')
     */
    public function modalSize(ModalSize|string $size): static
    {
        if (is_string($size)) {
            $size = ModalSize::from($size);
        }
        $this->modalSize = $size;

        return $this;
    }

    /**
     * Customize the query used to fetch attachable records.
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
     * Disable auto-sorting of attachables (keep DB order).
     *
     * Nova v5 parity: ->dontReorderAttachables()
     */
    public function dontReorderAttachables(bool $value = true): static
    {
        $this->dontReorderAttachables = $value;

        return $this;
    }

    /**
     * Show subtitles in the attach modal search results.
     *
     * Nova v5 parity: ->withSubtitles()
     */
    public function withSubtitles(bool $value = true): static
    {
        $this->withSubtitles = $value;

        return $this;
    }

    /** Set the default per-page for the inline listing. */
    public function perPage(int $perPage): static
    {
        $this->relationPerPage = $perPage;

        return $this;
    }

    /**
     * Configure whether the "Attach" button is shown.
     */
    public function canAttach(bool $value = true): static
    {
        $this->canAttach = $value;

        return $this;
    }

    /**
     * Configure whether the "Detach" button is shown.
     */
    public function canDetach(bool $value = true): static
    {
        $this->canDetach = $value;

        return $this;
    }

    /** Return the Eloquent relationship method name. */
    public function getRelationship(): string
    {
        return $this->relationship;
    }

    /** Return the related resource URI key. */
    public function getRelatedResourceKey(): ?string
    {
        return $this->relatedResourceKey ?? Str::snake(Str::plural($this->relationship), '-');
    }

    /** Return the pivot fields schema (resolved from closure). */
    /** @return list<mixed> */
    public function getPivotFields(): array
    {
        if ($this->pivotFieldsClosure === null) {
            return [];
        }

        return ($this->pivotFieldsClosure)();
    }

    /** Whether the create relation button should be shown. */
    public function isShowCreateRelationButton(): bool
    {
        if ($this->showCreateRelationButton instanceof \Closure) {
            return (bool) ($this->showCreateRelationButton)(request());
        }

        return $this->showCreateRelationButton;
    }

    /** Get the configured modal size. */
    public function getModalSize(): ModalSize
    {
        return $this->modalSize;
    }

    /** Get the relatableQueryUsing closure. */
    public function getRelatableQueryClosure(): ?\Closure
    {
        return $this->relatableQueryClosure;
    }

    /** Whether duplicate relations are allowed. */
    public function isAllowDuplicates(): bool
    {
        return $this->allowDuplicates;
    }

    /** Whether attachables should not be reordered. */
    public function isDontReorderAttachables(): bool
    {
        return $this->dontReorderAttachables;
    }

    /**
     * Resolve returns null on detail (data fetched via endpoints).
     * On index, returns the count of related records.
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        if ($this->showOnIndex) {
            $method = $this->relationship;
            if (method_exists($model, $method)) {
                return $model->{$method}()->count();
            }
        }

        return null;
    }

    /**
     * Fill is a no-op — attach/detach via dedicated controller endpoints.
     */
    public function fill(Model $model, mixed $value): void
    {
        // No-op: BelongsToMany is managed via dedicated attach/detach endpoints.
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        $pivotFields = [];
        foreach ($this->getPivotFields() as $field) {
            if ($field instanceof Field) {
                $pivotFields[] = $field->toArray();
            }
        }

        return [
            'relationship' => $this->relationship,
            'relatedResource' => $this->getRelatedResourceKey(),
            'titleAttribute' => $this->titleAttribute,
            'searchable' => $this->relationSearchable,
            'collapsable' => $this->collapsable,
            'collapsedByDefault' => $this->collapsedByDefault,
            'allowDuplicateRelations' => $this->allowDuplicates,
            'showCreateRelationButton' => $this->isShowCreateRelationButton(),
            'modalSize' => $this->getModalSize()->value,
            'withSubtitles' => $this->withSubtitles,
            'dontReorderAttachables' => $this->dontReorderAttachables,
            'pivotFields' => $pivotFields,
            'belongsToManyMeta' => [
                'perPage' => $this->relationPerPage,
                'perPageOptions' => $this->relationPerPageOptions,
                'canAttach' => $this->canAttach,
                'canDetach' => $this->canDetach,
            ],
        ];
    }
}
