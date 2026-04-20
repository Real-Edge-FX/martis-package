<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;
use Illuminate\Support\Str;
use Martis\Fields\Concerns\ControlsRelationshipToolbar;
use Martis\Fields\Concerns\ResolvesRelatableOptions;

/**
 * MorphOne relationship field.
 *
 * Displays and manages a single polymorphically related record via an Eloquent
 * morphOne relationship. Works like HasOne but for polymorphic one-to-one
 * relationships.
 *
 * morphOne() relationships are displayed as inline panels on the detail
 * page with optional Create / Edit / Delete controls.
 *
 * Usage:
 *   MorphOne::make('Image', 'image', ImageResource::class)
 *   MorphOne::make('Avatar')->canCreate(false)
 *
 * @phpstan-consistent-constructor
 */
class MorphOne extends Field
{
    use ControlsRelationshipToolbar;
    use ResolvesRelatableOptions;

    /** Eloquent relationship method name on the parent model. */
    protected string $relationship;

    /** URI key of the related resource (e.g. "images"). */
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

        // MorphOne is detail-only by default
        $this->onlyOnDetail();
    }

    /**
     * Create a MorphOne field.
     *
     * @param  string  $name  Display label (e.g. "Image") — also used to infer the relationship method
     * @param  string|null  $relationship  Explicit Eloquent relationship method name (e.g. "image")
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
     * Promote a `morphMany(...)->latestOfMany()` style relationship into
     * a `MorphOneOfMany` field.
     * Signature: `MorphOne::ofMany($name, $relationship, $resourceClass)`.
     */
    public static function ofMany(string $name, string $relationship, string $relatedResourceClass): MorphOneOfMany
    {
        /** @var MorphOneOfMany $instance */
        $instance = MorphOneOfMany::make($name, $relationship, $relatedResourceClass);

        return $instance;
    }

    /** {@inheritDoc} */
    public function type(): string
    {
        return 'morph_one';
    }

    /**
     * Set the related resource URI key explicitly.
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
     * Validate that the model actually defines a morphOne relationship.
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

        if (! $relation instanceof EloquentMorphOne) {
            throw new \InvalidArgumentException(
                "Relationship '{$this->relationship}' on ".get_class($model).' is not a morphOne relationship.'
            );
        }
    }

    /**
     * Resolve returns null — data fetched via the morph-one API endpoint.
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
        // No-op: MorphOne record is managed independently
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
            'morphOneMeta' => [
                'canCreate' => $this->canCreateRelated && $authorizedToCreate,
                'canUpdate' => $this->canUpdateRelated,
                'canDelete' => $this->canDeleteRelated,
            ] + $this->relationshipToolbarControls(),
        ] + $relatedAuth + $this->relatableOptionsMeta();
    }
}
