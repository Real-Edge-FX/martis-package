<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * BelongsTo relationship field.
 *
 * Resolves the foreign key and the related model's display title so the
 * React frontend can render both the current value and a search/dropdown
 * for form editing.
 *
 * Unlike scalar fields, BelongsTo stores a foreign key (e.g. `author_id`)
 * but resolves via the relationship method (e.g. `author`) so it can
 * display a human-readable label instead of a bare integer.
 *
 * Supports single (standard BelongsTo) and multiple (BelongsToMany-style)
 * modes. In multiple mode, the frontend renders checkboxes and the backend
 * syncs a pivot table.
 *
 * @phpstan-consistent-constructor
 */
class BelongsTo extends Field
{
    protected string $relationship;

    protected string $titleAttribute = 'name';

    protected string $foreignKey;

    protected ?string $relatedUriKey = null;

    /**
     * Whether the dropdown should support text search.
     * Defaults to true — set to false via ->relationSearchable(false).
     */
    protected bool $relationSearchable = true;

    /**
     * When true, the field operates in many-to-many mode:
     * uses a pivot table and renders as multi-select with checkboxes.
     */
    protected bool $multiple = false;

    /**
     * Whether to display the related record as a clickable link on index/detail.
     * Defaults to true — set to false via ->displayAsLink(false).
     */
    protected bool $displayAsLink = true;

    /**
     * @param  string  $relationship  Eloquent relationship method name (e.g. "author")
     * @param  string  $foreignKey  Database column storing the FK (e.g. "author_id")
     */
    protected function __construct(
        string $attribute,
        string $label,
        string $relationship = '',
        string $foreignKey = '',
    ) {
        parent::__construct($attribute, $label);
        $this->relationship = $relationship ?: $attribute;
        $this->foreignKey = $foreignKey ?: "{$this->relationship}_id";
    }

    /**
     * Create a BelongsTo field.
     *
     * @param  string  $relationship  Eloquent relationship method name (e.g. "author")
     * @param  string|null  $label  Human-readable label
     */
    public static function make(string $relationship, ?string $label = null): static
    {
        // If caller passes the FK column (e.g. "user_id"), derive relationship name
        if (str_ends_with($relationship, '_id')) {
            $foreignKey = $relationship;
            $relationship = substr($relationship, 0, -3);
        } else {
            $foreignKey = "{$relationship}_id";
        }

        $label = $label ?? Str::title(str_replace('_', ' ', $relationship));

        return new static($foreignKey, $label, $relationship, $foreignKey);
    }

    public function type(): string
    {
        return 'belongs_to';
    }

    /**
     * Customize the attribute on the related model used as the display label.
     * Defaults to "name".
     */
    public function titleAttribute(string $attribute): static
    {
        $this->titleAttribute = $attribute;

        return $this;
    }

    /**
     * Override the foreign key column name.
     * Default: `{relationship}_id` (e.g. "author_id" for relationship "author").
     */
    public function foreignKey(string $key): static
    {
        $this->foreignKey = $key;

        return $this;
    }

    /**
     * Link to the related resource's URI key for the React dropdown to fetch options.
     * When set, the frontend can use `GET /martis/api/{relatedUriKey}` to load options.
     */
    public function relatedResource(string $uriKey): static
    {
        $this->relatedUriKey = $uriKey;

        return $this;
    }

    /**
     * Configure whether the dropdown supports text search.
     * Defaults to true.
     */
    public function relationSearchable(bool $value = true): static
    {
        $this->relationSearchable = $value;

        return $this;
    }

    /**
     * Enable multi-select mode (many-to-many via pivot table).
     *
     * In this mode, the field renders as a multi-select with checkboxes.
     * The Eloquent model must define a belongsToMany() relationship
     * matching the relationship name.
     *
     * Example:
     *   BelongsTo::make('authors', 'Authors')
     *       ->relatedResource('users')
     *       ->multiple()
     */
    public function multiple(bool $value = true): static
    {
        $this->multiple = $value;

        return $this;
    }

    /**
     * Configure whether the field displays as a clickable link on index/detail.
     * Defaults to true. Set to false to render as plain text.
     */
    public function displayAsLink(bool $value = true): static
    {
        $this->displayAsLink = $value;

        return $this;
    }

    /**
     * Resolve the field value: returns the foreign key value AND the display title.
     *
     * Single mode: `['id' => 42, 'title' => 'Jane Doe']`
     * Multiple mode: `[['id' => 1, 'title' => 'Jane'], ['id' => 2, 'title' => 'John']]`
     *
     * @return array{id: mixed, title: string|null}|list<array{id: mixed, title: string|null}>|null
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        if ($this->resolveCallback !== null) {
            return ($this->resolveCallback)($model->getAttribute($this->foreignKey), $model, $this->foreignKey);
        }

        if ($this->multiple) {
            return $this->resolveMultiple($model);
        }

        $foreignKeyValue = $model->getAttribute($this->foreignKey);

        if ($foreignKeyValue === null) {
            return null;
        }

        // Attempt to load the related model via the relationship method
        $related = null;
        if (method_exists($model, $this->relationship)) {
            $related = $model->{$this->relationship};
        }

        return [
            'id' => $foreignKeyValue,
            'title' => $related?->getAttribute($this->titleAttribute),
        ];
    }

    /**
     * Resolve for multiple mode: load all related models.
     *
     * @return list<array{id: mixed, title: string|null}>
     */
    protected function resolveMultiple(Model $model): array
    {
        if (! method_exists($model, $this->relationship)) {
            return [];
        }

        $related = $model->{$this->relationship};

        if ($related === null) {
            return [];
        }

        return $related->map(fn (Model $item): array => [
            'id' => $item->getKey(),
            'title' => $item->getAttribute($this->titleAttribute),
        ])->values()->all();
    }

    /**
     * Fill the foreign key column on the model (single mode)
     * or sync pivot table (multiple mode).
     */
    public function fill(Model $model, mixed $value): void
    {
        if ($this->readonly) {
            return;
        }

        if ($this->fillCallback !== null) {
            ($this->fillCallback)($model, $value, $this->foreignKey);

            return;
        }

        if ($this->multiple) {
            // Multiple mode: sync happens after model is saved (needs ID).
            // Store the IDs in a static registry for deferred sync.
            $ids = $this->extractMultipleIds($value);
            DeferredRelationSync::register($model, $this->relationship, $ids);

            return;
        }

        // Accept either a raw ID or an array with 'id' key
        $id = is_array($value) ? ($value['id'] ?? null) : $value;

        // Convert empty strings to null (from FormData serialization)
        if ($id === '' || $id === 'null') {
            $id = null;
        }

        $model->setAttribute($this->foreignKey, $id);
    }

    /**
     * Extract IDs from the multiple-mode value.
     *
     * @return list<int|string>
     */
    protected function extractMultipleIds(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        // Array of IDs
        if (is_array($value)) {
            // Could be [1, 2, 3] or [['id' => 1], ['id' => 2]]
            return array_values(array_filter(array_map(function (mixed $item): int|string|null {
                if (is_array($item) && isset($item['id'])) {
                    return $item['id'];
                }
                if (is_string($item) && ($item === '' || $item === 'null')) {
                    return null;
                }

                return $item;
            }, $value), fn ($v): bool => $v !== null));
        }

        // JSON string
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $this->extractMultipleIds($decoded);
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_filter([
            'relationship' => $this->relationship,
            'foreignKey' => $this->foreignKey,
            'titleAttribute' => $this->titleAttribute,
            'relatedResource' => $this->relatedUriKey,
            'relatedLabel' => $this->relatedUriKey ? Str::title(str_replace('_', ' ', $this->relationship)) : null,
            'relationSearchable' => $this->relationSearchable,
            'multiple' => $this->multiple ?: null,
            'displayAsLink' => $this->displayAsLink,
        ], fn (mixed $v): bool => $v !== null);
    }
}
