<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Martis\Fields\Concerns\ControlsRelationshipToolbar;
use Martis\Enums\ModalSize;
use Martis\Resource;

/**
 * MorphTo polymorphic relationship field.
 *
 * Resolves a polymorphic belongsTo relationship where a model can belong to
 * multiple different model types via a single relationship. The frontend
 * renders a two-step selection: first pick the resource type, then pick
 * the specific record from that type.
 *
 * Nova v5 parity:
 *   - types() specifies which resource classes can be associated
 *   - showCreateRelationButton() enables inline create
 *   - modalSize() configures the inline create modal
 *   - nullable() makes the relationship optional
 *   - Only one level of inline create depth is supported
 *
 * Usage:
 *   MorphTo::make('Commentable')
 *       ->types([PostResource::class, VideoResource::class])
 *       ->showCreateRelationButton()
 *       ->modalSize(ModalSize::Large)
 *
 * @phpstan-consistent-constructor
 */
class MorphTo extends Field
{
    use ControlsRelationshipToolbar;

    /** Eloquent relationship method name. */
    protected string $relationship;

    /** The morph type column (e.g. commentable_type). */
    protected string $morphTypeColumn;

    /** The morph id column (e.g. commentable_id). */
    protected string $morphIdColumn;

    /**
     * Allowed resource classes for this polymorphic relationship.
     *
     * @var list<class-string<resource>>
     */
    protected array $morphTypes = [];

    /** Title attribute used for displaying selected record. */
    protected string $titleAttribute = 'name';

    /** Whether to show the inline create button. */
    protected bool|\Closure $showCreateRelationButton = false;

    /** Modal size for inline creation. */
    protected ModalSize $modalSize = ModalSize::TwoExtraLarge;

    /** Whether the dropdown should support text search. */
    protected bool $relationSearchable = true;

    /**
     * Whether to show subtitle text under each dropdown option.
     * Nova v5 parity: ->withSubtitles()
     */
    protected bool $withSubtitles = false;

    /**
     * The attribute on the related model used as the subtitle text.
     * Defaults to "subtitle".
     */
    protected string $subtitleAttribute = 'subtitle';

    /**
     * Whether the peek/preview button is shown for the related record.
     * Defaults to true (Nova v5 parity).
     */
    protected bool $peekable = true;

    /** Create a new field instance. */
    protected function __construct(
        string $attribute,
        string $label,
        string $relationship = '',
    ) {
        parent::__construct($attribute, $label);
        $this->relationship = $relationship ?: $attribute;
        $this->morphTypeColumn = "{$this->relationship}_type";
        $this->morphIdColumn = "{$this->relationship}_id";
    }

    /**
     * Create a MorphTo field.
     *
     * @param  string  $relationship  Eloquent relationship method name
     * @param  string|null  $label  Human-readable label
     */
    public static function make(string $relationship, ?string $label = null): static
    {
        $label = $label ?? Str::title(str_replace('_', ' ', $relationship));

        return new static($relationship, $label, $relationship);
    }

    /**
     * Type.
     */
    public function type(): string
    {
        return 'morph_to';
    }

    /**
     * Set the allowed resource types for this polymorphic relationship.
     *
     * @param  list<class-string<resource>>  $types
     */
    public function types(array $types): static
    {
        $this->morphTypes = $types;

        return $this;
    }

    /**
     * Customize the title attribute used for display.
     */
    public function titleAttribute(string $attribute): static
    {
        $this->titleAttribute = $attribute;

        return $this;
    }

    /**
     * Configure whether the dropdown supports text search.
     */
    public function relationSearchable(bool $value = true): static
    {
        $this->relationSearchable = $value;

        return $this;
    }

    /**
     * Enable the inline create button for this relationship field.
     *
     * Nova v5 parity: showCreateRelationButton() / showCreateRelationButton(fn ($request) => ...)
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
     * Set the modal size for inline creation.
     */
    public function modalSize(ModalSize $size): static
    {
        $this->modalSize = $size;

        return $this;
    }

    /**
     * Show subtitle text under each dropdown option.
     *
     * The subtitle is read from the related model's $subtitleAttribute (default: "subtitle").
     * Nova v5 parity: ->withSubtitles()
     */
    public function withSubtitles(bool $value = true): static
    {
        $this->withSubtitles = $value;

        return $this;
    }

    /**
     * Set the subtitle attribute on the related model.
     * Implicitly enables withSubtitles.
     *
     * Usage: ->subtitleAttribute('description')
     */
    public function subtitleAttribute(string $attribute): static
    {
        $this->subtitleAttribute = $attribute;
        $this->withSubtitles = true;

        return $this;
    }

    /**
     * Enable or disable the peek/preview button on the display component.
     * Defaults to true — pass false or call noPeeking() to disable.
     *
     * Nova v5 parity: ->peekable()
     */
    public function peekable(bool $value = true): static
    {
        $this->peekable = $value;

        return $this;
    }

    /**
     * Disable the peek/preview button for this relationship.
     *
     * Nova v5 parity: ->noPeeking()
     */
    public function noPeeking(): static
    {
        $this->peekable = false;

        return $this;
    }

    /**
     * Whether the create relation button should be shown.
     */
    public function isShowCreateRelationButton(): bool
    {
        if ($this->showCreateRelationButton instanceof \Closure) {
            return (bool) ($this->showCreateRelationButton)(request());
        }

        return $this->showCreateRelationButton;
    }

    /**
     * Resolve the morph type and morph id columns.
     *
     * Returns: ['type' => 'App\Models\Post', 'id' => 42, 'title' => 'Post Title', 'resourceType' => 'posts']
     * or null if the relationship is empty.
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        if ($this->resolveCallback !== null) {
            return ($this->resolveCallback)($model, $this->morphTypeColumn, $this->morphIdColumn);
        }

        $morphType = $model->getAttribute($this->morphTypeColumn);
        $morphId = $model->getAttribute($this->morphIdColumn);

        if ($morphType === null || $morphId === null) {
            return null;
        }

        // Load the related model. When the related model uses SoftDeletes,
        // include trashed rows so the title keeps resolving even when the
        // parent was soft-deleted — otherwise the UI would fall back to the
        // numeric ID.
        $camelRelationship = Str::camel($this->relationship);
        $related = null;
        $relationshipName = null;
        if (method_exists($model, $this->relationship)) {
            $relationshipName = $this->relationship;
        } elseif ($camelRelationship !== $this->relationship && method_exists($model, $camelRelationship)) {
            $relationshipName = $camelRelationship;
        }
        if ($relationshipName !== null) {
            $relation = $model->{$relationshipName}();
            $relatedClass = get_class($relation->getRelated());
            $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($relatedClass), true);
            if ($usesSoftDeletes) {
                // withTrashed() is provided at runtime by SoftDeletingScope
                // via __call — method_exists() cannot see it but the call
                // still works.
                $related = $relation->withTrashed()->first();
            } else {
                $related = $model->{$relationshipName};
            }
        }

        // Resolve the resource URI key for the morph type
        $resourceType = $this->resolveResourceUriKey($morphType);

        return [
            'type' => $morphType,
            'id' => $morphId,
            'title' => $related?->getAttribute($this->resolvedTitleAttribute($morphType)),
            'resourceType' => $resourceType,
        ];
    }

    /**
     * Fill the morph type and morph id columns on the model.
     */
    public function fill(Model $model, mixed $value): void
    {
        if ($this->readonly) {
            return;
        }

        if ($this->fillCallback !== null) {
            ($this->fillCallback)($model, $value, $this->morphTypeColumn, $this->morphIdColumn);

            return;
        }

        if ($value === null || $value === '') {
            if ($this->nullable) {
                $model->setAttribute($this->morphTypeColumn, null);
                $model->setAttribute($this->morphIdColumn, null);
            }

            return;
        }

        // Value should be {type: 'App\Models\Post', id: 42} or {resourceType: 'posts', id: 42}
        if (is_array($value)) {
            $morphType = $value['type'] ?? null;
            $morphId = $value['id'] ?? null;

            // If resourceType provided instead of full class, resolve it
            if ($morphType === null && isset($value['resourceType'])) {
                $morphType = $this->resolveModelClass($value['resourceType']);
            }

            if ($morphType !== null && $morphId !== null) {
                $model->setAttribute($this->morphTypeColumn, $morphType);
                $model->setAttribute($this->morphIdColumn, $morphId);
            }
        }
    }

    /**
     * Find the resource class that corresponds to a morph type (model class name).
     *
     * @param  string  $morphType  Full model class name e.g. App\Models\Post
     * @return class-string<\Martis\Resource>|null
     */
    protected function findResourceClassForMorphType(string $morphType): ?string
    {
        foreach ($this->morphTypes as $resourceClass) {
            try {
                $model = $resourceClass::newModel();
                $modelClass = get_class($model);
            } catch (\Throwable) {
                continue;
            }

            if (ltrim($modelClass, '\\') === ltrim($morphType, '\\')) {
                return $resourceClass;
            }
        }

        return null;
    }

    /**
     * Resolve the title attribute for a given morphType.
     *
     * Prefers the resource class's titleAttribute() over the field's default.
     */
    protected function resolvedTitleAttribute(string $morphType): string
    {
        $resourceClass = $this->findResourceClassForMorphType($morphType);

        return $resourceClass ? $resourceClass::titleAttribute() : $this->titleAttribute;
    }

    /**
     * Resolve a morph type string to a resource URI key.
     */
    protected function resolveResourceUriKey(string $morphType): ?string
    {
        foreach ($this->morphTypes as $resourceClass) {
            /** @var class-string<resource> $resourceClass */
            $modelClass = $resourceClass::$model ?? null;
            if ($modelClass === null) {
                // Try to infer from the resource
                try {
                    $model = $resourceClass::newModel();
                    $modelClass = get_class($model);
                } catch (\Throwable) {
                    continue;
                }
            }

            if ($modelClass === $morphType || ltrim($modelClass, '\\') === ltrim($morphType, '\\')) {
                return $resourceClass::uriKey();
            }
        }

        // Fallback: try morph map
        $morphMap = Relation::morphMap();
        foreach ($morphMap as $alias => $class) {
            if ($class === $morphType || ltrim($class, '\\') === ltrim($morphType, '\\')) {
                // Find the resource for this alias
                foreach ($this->morphTypes as $resourceClass) {
                    try {
                        $model = $resourceClass::newModel();
                        if (get_class($model) === $class || ltrim(get_class($model), '\\') === ltrim($class, '\\')) {
                            return $resourceClass::uriKey();
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolve a resource URI key to a model class name.
     */
    protected function resolveModelClass(string $resourceUriKey): ?string
    {
        foreach ($this->morphTypes as $resourceClass) {
            if ($resourceClass::uriKey() === $resourceUriKey) {
                try {
                    return get_class($resourceClass::newModel());
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Build the type options for the frontend dropdown.
     *
     * Each option is enriched with authorization flags for the related
     * resource, so the frontend can hide the "create" action per-type
     * when the current user is not allowed to create that type.
     *
     * @return list<array{value: string, label: string, authorizedToViewAny: bool, authorizedToCreate: bool}>
     */
    protected function buildTypeOptions(): array
    {
        $options = [];
        foreach ($this->morphTypes as $resourceClass) {
            $uriKey = $resourceClass::uriKey();
            $auth = $this->relatedResourceAuthorizations($uriKey);

            $options[] = [
                'value' => $uriKey,
                'label' => $resourceClass::singularLabel(),
                'authorizedToViewAny' => $auth['authorizedToViewAny'] ?? true,
                'authorizedToCreate' => $auth['authorizedToCreate'] ?? true,
            ];
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        $typeOptions = $this->buildTypeOptions();

        // MorphTo has multiple related resources; allow the create button
        // when at least one of the allowed morph types is creatable.
        $anyAuthorizedToCreate = $typeOptions === [] ? true : false;
        foreach ($typeOptions as $option) {
            if (($option['authorizedToCreate'] ?? true) === true) {
                $anyAuthorizedToCreate = true;
                break;
            }
        }

        return [
            'relationship' => $this->relationship,
            'morphTypeColumn' => $this->morphTypeColumn,
            'morphIdColumn' => $this->morphIdColumn,
            'titleAttribute' => $this->titleAttribute,
            'morphTypes' => $typeOptions,
            'relationSearchable' => $this->relationSearchable,
            'showCreateRelationButton' => $this->isShowCreateRelationButton() && $anyAuthorizedToCreate,
            'modalSize' => $this->modalSize->value,
            'withSubtitles' => $this->withSubtitles,
            'subtitleAttribute' => $this->withSubtitles ? $this->subtitleAttribute : null,
            'peekable' => $this->peekable,
        ] + $this->relationshipToolbarControls();
    }
}
