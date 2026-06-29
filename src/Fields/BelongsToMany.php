<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Enums\ModalSize;
use Martis\Fields\Concerns\ControlsRelationshipToolbar;
use Martis\Resource;
use Martis\ResourceRegistry;

/**
 * BelongsToMany relationship field.
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

    /** Closure to filter the relatable query (exposed via relatableQueryUsing). */
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
     *  `perPageOptions()` — the resource is the single source of truth. */
    protected bool $perPageOptionsSet = false;

    /** Whether to show attach button. */
    protected bool $canAttach = true;

    /** Whether to show detach button. */
    protected bool $canDetach = true;

    /** Create a new field instance. */
    protected function __construct(
        string $attribute,
        string $label,
        string $relationship = '',
    ) {
        parent::__construct($attribute, $label);
        $this->relationship = $relationship ?: Str::camel($attribute);

        // BelongsToMany is hidden from index by default (shown on detail + forms)
        $this->hideFromIndex();

        // v1.8.4 — Auto-hide on the create form. Pivot rows need both
        // `(parent_id, related_id)` and the parent doesn't exist yet
        // when the form is rendered. Showing the picker on create is
        // visually misleading: clicks would attach to nothing or, with
        // the v1.8.2 form-draft mechanism, would still need the parent
        // saved before sync. Use `->showOnCreating()` to override when
        // you have a custom afterSave hook that drains the picker.
        $this->showOnCreate = false;
    }

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
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
     * @param  \Closure(): list<mixed>  $closure
     */
    public function actions(\Closure $closure): static
    {
        $this->pivotActionsClosure = $closure;

        return $this;
    }

    /** {@inheritdoc} */
    public function searchable(bool $value = true): static
    {
        $this->relationSearchable = $value;

        return $this;
    }

    /**
     * Make the panel collapsable.
     */
    public function collapsable(bool $value = true): static
    {
        $this->collapsable = $value;

        return $this;
    }

    /**
     * Start the panel collapsed by default.
     */
    public function collapsedByDefault(bool $value = true): static
    {
        $this->collapsable = true;
        $this->collapsedByDefault = $value;

        return $this;
    }

    /**
     * Allow attaching the same related record multiple times.
     */
    public function allowDuplicateRelations(bool $value = true): static
    {
        $this->allowDuplicates = $value;

        return $this;
    }

    /**
     * Show an inline create button in the attach modal.
     */
    public function showCreateRelationButton(bool|\Closure $callback = true): static
    {
        $this->showCreateRelationButton = $callback;

        return $this;
    }

    /**
     * Explicitly hide the inline create button.
     */
    public function hideCreateRelationButton(): static
    {
        $this->showCreateRelationButton = false;

        return $this;
    }

    /**
     * Set the modal size for the attach dialog.
     */
    public function modalSize(ModalSize $size, ?string $height = null): static
    {
        $this->modalSize = $size;
        $this->modalHeight = $height;

        return $this;
    }

    /**
     * Customize the query used to fetch attachable records.
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
            return (bool) ($this->showCreateRelationButton)($this->safeRequest());
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

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
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
            'modalSize' => $this->getModalSize()->value,
            'modalHeight' => $this->modalHeight,
            'withSubtitles' => $this->withSubtitles,
            'subtitleAttribute' => $this->withSubtitles ? $this->subtitleAttribute : null,
            'dontReorderAttachables' => $this->dontReorderAttachables,
            'pivotFields' => $pivotFields,
            'belongsToManyMeta' => [
                'perPage' => $this->resolvePerPage(),
                'perPageOptions' => $this->resolvePerPageOptions(),
                'canAttach' => $this->canAttach,
                'canDetach' => $this->canDetach,
            ] + $this->relationshipToolbarControls(),
        ] + $relatedAuth;
    }

    /**
     * Per-page options for the inline listing.
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
                /** @var ResourceRegistry $registry */
                $registry = app(ResourceRegistry::class);
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
