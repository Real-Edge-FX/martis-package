<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Support\Str;

/**
 * HasMany relationship field.
 *
 * Displays related records as an inline DataTable on the parent resource's
 * detail page. Supports listing, pagination, search, sort, and CRUD of
 * related records within the parent context.
 *
 * Nova v5 parity: the field is shown ONLY on the detail page. It does not
 * appear on index, create or update forms — related records are managed via
 * their own dedicated endpoints.
 *
 * Usage:
 *   HasMany::make('Posts')
 *   HasMany::make('Posts', 'posts')
 *   HasMany::make('Comments', 'comments', CommentResource::class)
 *
 * @phpstan-consistent-constructor
 */
class HasMany extends Field
{
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

    /** Whether to show a "Create" button for related records. */
    protected bool $canCreate = true;

    /** Whether to show edit actions for related records. */
    protected bool $canUpdate = true;

    /** Whether to show delete actions for related records. */
    protected bool $canDelete = true;

    /** Whether the inline listing supports search. */
    protected bool $relationSearchable = true;

    protected function __construct(
        string $attribute,
        string $label,
        string $relationship = '',
    ) {
        parent::__construct($attribute, $label);
        $this->relationship = $relationship ?: Str::camel($attribute);

        // HasMany is detail-only by default (Nova v5 behavior)
        $this->hideFromIndex();
        $this->hideFromForms();
    }

    /**
     * Create a HasMany field.
     *
     * @param  string  $name  Display label (e.g. "Posts") — also used to infer the relationship method
     * @param  string|null  $relationship  Explicit Eloquent relationship method name (e.g. "posts")
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

        return $this;
    }

    /** Configure whether the "Create" button is shown. */
    public function canCreate(bool $value = true): static
    {
        $this->canCreate = $value;

        return $this;
    }

    /** Configure whether edit actions are shown. */
    public function canUpdate(bool $value = true): static
    {
        $this->canUpdate = $value;

        return $this;
    }

    /** Configure whether delete actions are shown. */
    public function canDelete(bool $value = true): static
    {
        $this->canDelete = $value;

        return $this;
    }

    /** Configure whether the inline listing supports search. */
    public function relationSearchable(bool $value = true): static
    {
        $this->relationSearchable = $value;

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
                "Model " . get_class($model) . " does not define relationship method '{$this->relationship}'."
            );
        }

        $relation = $model->{$this->relationship}();

        if (! $relation instanceof EloquentHasMany) {
            throw new \InvalidArgumentException(
                "Relationship '{$this->relationship}' on " . get_class($model) . " is not a hasMany relationship."
            );
        }
    }

    /**
     * Resolve returns null — HasMany data is fetched via dedicated endpoints,
     * not embedded in the parent resource response.
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        return null;
    }

    /**
     * Fill is a no-op — related records are managed via their own endpoints.
     */
    public function fill(Model $model, mixed $value): void
    {
        // No-op: HasMany records are managed independently
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [
            'relationship' => $this->relationship,
            'relatedResource' => $this->getRelatedResourceKey(),
            'hasManyMeta' => [
                'perPage' => $this->relationPerPage,
                'perPageOptions' => $this->relationPerPageOptions,
                'searchable' => $this->relationSearchable,
                'canCreate' => $this->canCreate,
                'canUpdate' => $this->canUpdate,
                'canDelete' => $this->canDelete,
            ],
        ];
    }
}
