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
 * Track C hook: override `resolveOptions()` in a subclass to supply paginated
 * options from a custom source (e.g. MorphTo, remote API).
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
        $label = $label ?? Str::title(str_replace('_', ' ', $relationship));
        $foreignKey = "{$relationship}_id";

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
     * Resolve the field value: returns the foreign key value AND the display title.
     *
     * Returns an array so the frontend has both the stored ID and human label:
     * `['id' => 42, 'title' => 'Jane Doe']`
     *
     * Falls back to just the foreign key when the relationship is not loaded.
     *
     * @return array{id: mixed, title: string|null}|null
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        if ($this->resolveCallback !== null) {
            return ($this->resolveCallback)($model->getAttribute($this->foreignKey), $model, $this->foreignKey);
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
     * Fill the foreign key column on the model.
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

        // Accept either a raw ID or an array with 'id' key
        $id = is_array($value) ? ($value['id'] ?? null) : $value;
        $model->setAttribute($this->foreignKey, $id);
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
            'relatedUriKey' => $this->relatedUriKey,
        ], fn (mixed $v): bool => $v !== null);
    }
}
