<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Support\Str;
use Martis\Enums\HasManyIndexDisplay;
use Martis\Enums\HasManyRedirectMode;
use Martis\Fields\Concerns\ControlsRelationshipToolbar;
use Martis\Fields\Concerns\ResolvesRelatableOptions;
use Martis\Resource;
use Martis\ResourceRegistry;

/**
 * HasMany relationship field.
 *
 * Displays related records as an inline DataTable on the parent resource's
 * detail page. Supports listing, pagination, search, sort, and CRUD of
 * related records within the parent context.
 *
 * The field is shown ONLY on the detail page by default.
 * It can optionally be shown on the index page as a count badge.
 *
 * Usage:
 *   HasMany::make('Posts')
 *   HasMany::make('Posts', 'posts')
 *   HasMany::make('Comments', 'comments', CommentResource::class)
 *   HasMany::make('Posts')->showOnIndex()->indexDisplay(HasManyIndexDisplay::Count)
 *   HasMany::make('Posts')->showRelationIcon(false)->showRelationCount(false)
 *   HasMany::make('Posts')->badgeColor('#3b82f6')->badgeIcon('newspaper')
 *   HasMany::make('Posts')->redirectAfterSave(HasManyRedirectMode::Detail)
 *
 * @phpstan-consistent-constructor
 */
class HasMany extends Field
{
    use ControlsRelationshipToolbar;
    use ResolvesRelatableOptions;

    /** Eloquent relationship method name on the parent model. */
    protected string $relationship;

    /** URI key of the related resource (e.g. "posts"). */
    protected ?string $relatedResourceKey = null;

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

    /** Whether to show a "Create" button for related records. */
    protected bool $canCreateRelated = true;

    /** Whether to show edit actions for related records. */
    protected bool $canUpdateRelated = true;

    /** Whether to show delete actions for related records. */
    protected bool $canDeleteRelated = true;

    /** Whether the inline listing supports search. */
    protected bool $relationSearchable = true;

    /** How to display the field on the index page. */
    protected HasManyIndexDisplay $indexDisplayMode = HasManyIndexDisplay::Count;

    /** Whether to show the related resource icon on the section header. */
    protected bool $showIcon = true;

    /** Whether to show the count of related records on the section header. */
    protected bool $showCount = true;

    /** Custom badge color for index display (CSS color value). */
    protected ?string $badgeColorValue = null;

    /** Custom badge icon name (from icon set). */
    protected ?string $badgeIconValue = null;

    /** Where to redirect after saving a related record. */
    protected HasManyRedirectMode $redirectMode = HasManyRedirectMode::Parent;

    /** Whether the panel section header is collapsable. */
    protected bool $collapsable = false;

    /** Whether the panel starts collapsed. */
    protected bool $collapsedByDefault = false;

    /** Create a new field instance. */
    protected function __construct(
        string $attribute,
        string $label,
        string $relationship = '',
    ) {
        parent::__construct($attribute, $label);
        $this->relationship = $relationship ?: Str::camel($attribute);

        // HasMany is detail-only by default
        $this->hideFromIndex();
        $this->hideFromForms();
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
        return 'has_many';
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

    /** Set the default per-page for the inline listing. */
    public function perPage(int $perPage): static
    {
        $this->relationPerPage = $perPage;

        return $this;
    }

    /**
     * Set the per-page options for the inline listing.
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
     * missing from the option list — keeps the dropdown and the actual
     * filter in sync (Option A).
     */
    protected function resolvePerPage(): int
    {
        $options = $this->resolvePerPageOptions();
        if ($options === [] || in_array($this->relationPerPage, $options, true)) {
            return $this->relationPerPage;
        }

        return $options[0];
    }

    /** Configure whether the "Create" button is shown. */
    public function canCreate(bool $value = true): static
    {
        $this->canCreateRelated = $value;

        return $this;
    }

    /** Configure whether edit actions are shown. */
    public function canUpdate(bool $value = true): static
    {
        $this->canUpdateRelated = $value;

        return $this;
    }

    /** Configure whether delete actions are shown. */
    public function canDelete(bool $value = true): static
    {
        $this->canDeleteRelated = $value;

        return $this;
    }

    /** Configure whether the inline listing supports search. */
    public function relationSearchable(bool $value = true): static
    {
        $this->relationSearchable = $value;

        return $this;
    }

    /**
     * Configure how the field displays on the index page.
     * Use with ->showOnIndex() to make the field visible on index.
     */
    public function indexDisplay(HasManyIndexDisplay $mode): static
    {
        $this->indexDisplayMode = $mode;

        return $this;
    }

    /** Configure whether to show the related resource icon on the section header. */
    public function showRelationIcon(bool $value = true): static
    {
        $this->showIcon = $value;

        return $this;
    }

    /** Configure whether to show the count of related records on the section header. */
    public function showRelationCount(bool $value = true): static
    {
        $this->showCount = $value;

        return $this;
    }

    /** Set a custom badge color for index display. */
    public function badgeColor(string $color): static
    {
        $this->badgeColorValue = $color;

        return $this;
    }

    /** Set a custom badge icon name for index display. */
    public function badgeIcon(string $icon): static
    {
        $this->badgeIconValue = $icon;

        return $this;
    }

    /** Configure where to redirect after saving a related record. */
    public function redirectAfterSave(HasManyRedirectMode $mode): static
    {
        $this->redirectMode = $mode;

        return $this;
    }

    /**
     * Make the HasMany panel collapsable.
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

    /**
     * Validate that the model actually defines a hasMany relationship.
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

        if (! $relation instanceof EloquentHasMany) {
            throw new \InvalidArgumentException(
                "Relationship '{$this->relationship}' on ".get_class($model).' is not a hasMany relationship.'
            );
        }
    }

    /** {@inheritdoc} */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        if ($this->showOnIndex) {
            return $model->{$this->relationship}()->count();
        }

        return null;
    }

    /** {@inheritdoc} */
    public function fill(Model $model, mixed $value): void
    {
        // No-op: HasMany records are managed independently
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        $relatedAuth = $this->relatedResourceAuthorizations($this->getRelatedResourceKey());
        $authorizedToCreate = $relatedAuth['authorizedToCreate'] ?? true;

        return [
            'relationship' => $this->relationship,
            'relatedResource' => $this->getRelatedResourceKey(),
            'indexDisplay' => $this->indexDisplayMode->value,
            'showRelationIcon' => $this->showIcon,
            'showRelationCount' => $this->showCount,
            'badgeColor' => $this->badgeColorValue,
            'badgeIcon' => $this->badgeIconValue,
            'redirectAfterSave' => $this->redirectMode->value,
            'collapsable' => $this->collapsable ?: null,
            'collapsedByDefault' => $this->collapsedByDefault ?: null,
            'hasManyMeta' => [
                'perPage' => $this->resolvePerPage(),
                'perPageOptions' => $this->resolvePerPageOptions(),
                'searchable' => $this->relationSearchable,
                'canCreate' => $this->canCreateRelated && $authorizedToCreate,
                'canUpdate' => $this->canUpdateRelated,
                'canDelete' => $this->canDeleteRelated,
            ] + $this->relationshipToolbarControls(),
        ] + $relatedAuth + $this->relatableOptionsMeta();
    }
}
