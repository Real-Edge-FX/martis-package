<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Enums\ModalSize;

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
     * Whether to show the inline create button for this relationship.
     * When true, a "+" button appears next to the dropdown.
     */
    protected bool|\Closure $showCreateRelationButton = false;

    /**
     * Modal size for the inline create dialog.
     * Defaults to 2xl (Nova v5 parity).
     */
    protected ModalSize $modalSize = ModalSize::TwoExtraLarge;

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

    /**
     * Whether soft-deleted records should be excluded from relatable options.
     * Nova v5 parity: ->withoutTrashed()
     */
    protected bool $withoutTrashed = false;

    /**
     * Whether to disable auto-reordering of associatables.
     * Nova v5 parity: ->dontReorderAssociatables()
     */
    protected bool $dontReorderAssociatables = false;

    /**
     * Closure to customize the relatable query for this field.
     * Nova v5 parity: ->relatableQueryUsing(fn($request, $query) => ...)
     */
    protected ?\Closure $relatableQueryClosure = null;

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

        // Attempt to load the related model via the relationship method.
        // Try both snake_case (e.g. current_team) and camelCase (e.g. currentTeam)
        // since Eloquent conventions use camelCase for relationship methods.
        $related = null;
        $camelRelationship = Str::camel($this->relationship);
        if (method_exists($model, $this->relationship)) {
            $related = $model->{$this->relationship};
        } elseif ($camelRelationship !== $this->relationship && method_exists($model, $camelRelationship)) {
            $related = $model->{$camelRelationship};
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
        $camelRelationship = Str::camel($this->relationship);
        $method = null;
        if (method_exists($model, $this->relationship)) {
            $method = $this->relationship;
        } elseif ($camelRelationship !== $this->relationship && method_exists($model, $camelRelationship)) {
            $method = $camelRelationship;
        }

        if ($method === null) {
            return [];
        }

        $related = $model->{$method};

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
     * Enable the inline create button for this relationship field.
     * Optionally accepts a closure for conditional display.
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
     *
     * Nova v5 parity: hideCreateRelationButton()
     */
    public function hideCreateRelationButton(): static
    {
        $this->showCreateRelationButton = false;

        return $this;
    }

    /**
     * Set the modal size for inline creation.
     *
     * Nova v5 parity: modalSize("sm" | "md" | "lg" | "xl" | "2xl" | ... | "7xl")
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
     * Exclude soft-deleted records from the relatable options list.
     *
     * Nova v5 parity: ->withoutTrashed()
     */
    public function withoutTrashed(): static
    {
        $this->withoutTrashed = true;

        return $this;
    }

    /**
     * Disable auto-reordering of relatable options (keep DB/query order).
     *
     * Nova v5 parity: ->dontReorderAssociatables()
     */
    public function dontReorderAssociatables(): static
    {
        $this->dontReorderAssociatables = true;

        return $this;
    }

    /**
     * Customize the relatable options query using a closure.
     *
     * The closure receives ($request, $query) and should modify the Builder in-place
     * or return a new Builder.
     *
     * Nova v5 parity: ->relatableQueryUsing(fn($request, $query) => $query->where('active', 1))
     *
     * @param  \Closure(Request, Builder<Model>): void  $closure
     */
    public function relatableQueryUsing(\Closure $closure): static
    {
        $this->relatableQueryClosure = $closure;

        return $this;
    }

    /**
     * Get the relatableQueryUsing closure (used by RelationshipQueryResolver).
     */
    public function getRelatableQueryClosure(): ?\Closure
    {
        return $this->relatableQueryClosure;
    }

    /**
     * Whether soft-deleted records should be excluded.
     */
    public function isWithoutTrashed(): bool
    {
        return $this->withoutTrashed;
    }

    /**
     * Whether auto-reordering of associatables is disabled.
     */
    public function isDontReorderAssociatables(): bool
    {
        return $this->dontReorderAssociatables;
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
     * Get the configured modal size.
     */
    public function getModalSize(): ModalSize
    {
        return $this->modalSize;
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
            'showCreateRelationButton' => $this->isShowCreateRelationButton(),
            'modalSize' => $this->getModalSize()->value,
            'withSubtitles' => $this->withSubtitles ?: null,
            'subtitleAttribute' => $this->withSubtitles ? $this->subtitleAttribute : null,
            'peekable' => $this->peekable,
            'withoutTrashed' => $this->withoutTrashed ?: null,
            'dontReorderAssociatables' => $this->dontReorderAssociatables ?: null,
        ], fn (mixed $v): bool => $v !== null);
    }
}
