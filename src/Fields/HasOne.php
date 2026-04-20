<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne as EloquentHasOne;
use Illuminate\Support\Str;
use Martis\Fields\Concerns\ControlsRelationshipToolbar;
use Martis\Fields\Concerns\ResolvesRelatableOptions;

/**
 * HasOne relationship field.
 *
 * Displays and manages a single related record via an Eloquent hasOne
 * relationship. The related record is shown as a read-only panel on the
 * detail page, with optional Create / Edit / Delete controls.
 *
 * This field is detail-only by default.
 *
 * Usage:
 *   HasOne::make('Profile')
 *   HasOne::make('Profile', 'profile')
 *   HasOne::make('Profile', 'profile', ProfileResource::class)
 *   HasOne::make('Profile')->canCreate(false)
 *   HasOne::make('Profile')->canUpdate(false)->canDelete(false)
 *
 * @phpstan-consistent-constructor
 */
class HasOne extends Field
{
    use ControlsRelationshipToolbar;
    use ResolvesRelatableOptions;

    /** Eloquent relationship method name on the parent model. */
    protected string $relationship;

    /** URI key of the related resource (e.g. "profiles"). */
    protected ?string $relatedResourceKey = null;

    /** Whether to show a "Create" button when no related record exists. */
    protected bool $canCreateRelated = true;

    /** Whether to show an "Edit" button for the existing related record. */
    protected bool $canUpdateRelated = true;

    /** Whether to show a "Delete" button for the existing related record. */
    protected bool $canDeleteRelated = true;

    /** Create a new field instance. */
    protected function __construct(
        string $attribute,
        string $label,
        string $relationship = '',
    ) {
        parent::__construct($attribute, $label);
        $this->relationship = $relationship ?: Str::camel($attribute);

        // HasOne is detail-only by default
        $this->hideFromIndex();
        $this->hideFromForms();
    }

    /**
     * Create a HasOne field.
     *
     * @param  string  $name  Display label (e.g. "Profile") — also used to infer the relationship method
     * @param  string|null  $relationship  Explicit Eloquent relationship method name (e.g. "profile")
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

    /**
     * Promote a `hasMany(...)->latestOfMany()` style relationship into a
     * `HasOneOfMany` field. Signature: `HasOne::ofMany($name, $relationship, $resourceClass)`.
     *
     * Usage:
     *   HasOne::ofMany('Latest Post', 'latestPost', PostResource::class)
     *
     * The third argument is a Martis Resource class (not an Eloquent model).
     */
    public static function ofMany(string $name, string $relationship, string $relatedResourceClass): HasOneOfMany
    {
        /** @var HasOneOfMany $instance */
        $instance = HasOneOfMany::make($name, $relationship, $relatedResourceClass);

        return $instance;
    }

    /** {@inheritDoc} */
    public function type(): string
    {
        return 'has_one';
    }

    /**
     * Set the related resource URI key explicitly.
     * If not set, inferred from the relationship name (snake_case, pluralised).
     */
    public function relatedResource(string $uriKey): static
    {
        $this->relatedResourceKey = $uriKey;

        return $this;
    }

    /** Configure whether the "Create" button is shown when no related record exists. */
    public function canCreate(bool $value = true): static
    {
        $this->canCreateRelated = $value;

        return $this;
    }

    /** Configure whether the "Edit" button is shown for the related record. */
    public function canUpdate(bool $value = true): static
    {
        $this->canUpdateRelated = $value;

        return $this;
    }

    /** Configure whether the "Delete" button is shown for the related record. */
    public function canDelete(bool $value = true): static
    {
        $this->canDeleteRelated = $value;

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
     * Validate that the model actually defines a hasOne relationship.
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

        if (! $relation instanceof EloquentHasOne) {
            throw new \InvalidArgumentException(
                "Relationship '{$this->relationship}' on ".get_class($model).' is not a hasOne relationship.'
            );
        }
    }

    /**
     * Resolve returns null — data fetched via the has-one API endpoint.
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        return null;
    }

    /**
     * Fill is a no-op — the related record is managed via its own endpoints.
     */
    public function fill(Model $model, mixed $value): void
    {
        // No-op: HasOne record is managed independently
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
            'hasOneMeta' => [
                'canCreate' => $this->canCreateRelated && $authorizedToCreate,
                'canUpdate' => $this->canUpdateRelated,
                'canDelete' => $this->canDeleteRelated,
            ] + $this->relationshipToolbarControls(),
        ] + $relatedAuth + $this->relatableOptionsMeta();
    }
}
