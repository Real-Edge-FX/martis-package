<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Support\Str;
use Martis\Enums\HasManyIndexDisplay;
use Martis\Enums\HasManyRedirectMode;

/**
 * MorphMany relationship field.
 *
 * Displays polymorphically related records as an inline DataTable on the
 * parent resource's detail page. Works like HasMany but for polymorphic
 * one-to-many relationships (morphMany).
 *
 * Nova v5 parity: morphMany() relationships are displayed as inline panels
 * on the detail page with full CRUD support for the related records.
 *
 * Usage:
 *   MorphMany::make('Comments', 'comments', CommentResource::class)
 *   MorphMany::make('Images')->canCreate(false)
 *
 * @phpstan-consistent-constructor
 */
class MorphMany extends Field
{
    /** Eloquent relationship method name on the parent model. */
    protected string $relationship;

    /** URI key of the related resource (e.g. "comments"). */
    protected ?string $relatedResourceKey = null;

    /** Per-page default for the inline listing. */
    protected int $relationPerPage = 10;

    /**
     * @var list<int>
     */
    protected array $relationPerPageOptions = [5, 10, 25, 50];

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

    /** Create a new field instance. */
    protected function __construct(
        string $attribute,
        string $label,
        string $relationship = '',
    ) {
        parent::__construct($attribute, $label);
        $this->relationship = $relationship ?: Str::camel($attribute);

        // MorphMany is detail-only by default (Nova v5 behavior)
        $this->onlyOnDetail();
    }

    /**
     * Create a MorphMany field.
     *
     * @param  string  $name  Display label (e.g. "Comments") — also used to infer the relationship method
     * @param  string|null  $relationship  Explicit Eloquent relationship method name (e.g. "comments")
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
        return 'morph_many';
    }

    /**
     * Set the related resource URI key explicitly.
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

        return $this;
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
     * Validate that the model actually defines a morphMany relationship.
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

        if (! $relation instanceof EloquentMorphMany) {
            throw new \InvalidArgumentException(
                "Relationship '{$this->relationship}' on ".get_class($model).' is not a morphMany relationship.'
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
     * Fill is a no-op — related records are managed via their own endpoints.
     */
    public function fill(Model $model, mixed $value): void
    {
        // No-op: MorphMany records are managed independently
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [
            'relationship' => $this->relationship,
            'relatedResource' => $this->getRelatedResourceKey(),
            'indexDisplay' => $this->indexDisplayMode->value,
            'showRelationIcon' => $this->showIcon,
            'showRelationCount' => $this->showCount,
            'badgeColor' => $this->badgeColorValue,
            'badgeIcon' => $this->badgeIconValue,
            'redirectAfterSave' => $this->redirectMode->value,
            'morphManyMeta' => [
                'perPage' => $this->relationPerPage,
                'perPageOptions' => $this->relationPerPageOptions,
                'searchable' => $this->relationSearchable,
                'canCreate' => $this->canCreateRelated,
                'canUpdate' => $this->canUpdateRelated,
                'canDelete' => $this->canDeleteRelated,
            ],
        ];
    }
}
