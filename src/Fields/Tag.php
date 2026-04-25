<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Martis\Enums\ModalSize;

/**
 * Tag field — relational tagging via BelongsToMany.
 *
 * Tag is NOT a free string input — it is a relational field that operates on
 * a BelongsToMany Eloquent relation, with attach/detach, autocomplete and
 * preview/list options.
 *
 * Contexts:
 *  - create: yes
 *  - update: yes
 *  - detail: yes
 *  - index: yes (summarised representation)
 *
 * Configuration:
 *   Tag::make('tags', 'Tags')
 *       ->relatedResource('tags')    // URI key do resource relacionado
 *       ->titleAttribute('name')     // display attribute on the related model
 *       ->withPreview()              // mostra preview ao hovear
 *       ->displayAsList()            // exibe como lista em vez de chips
 *       ->showCreateRelationButton() // inline create relation button
 *       ->modalSize('7xl')           // size of the inline create modal
 *       ->preload()                  // preloads all available tags
 *
 * Fill: uses DeferredRelationSync to synchronize the pivot table after save.
 * Resolve: loads related models via the Eloquent relation.
 */
class Tag extends Field
{
    protected string $relationship;

    protected string $titleAttribute = 'name';

    protected ?string $relatedUriKey = null;

    protected bool $withPreview = false;

    protected bool $displayAsList = false;

    protected bool $showCreateRelationButton = false;

    protected ModalSize $modalSize = ModalSize::TwoExtraLarge;

    protected bool $preload = false;

    protected bool $relationSearchable = true;

    /** Create a new field instance. */
    protected function __construct(string $attribute, string $label, string $relationship = '')
    {
        parent::__construct($attribute, $label);
        $this->relationship = $relationship ?: $attribute;
    }

    /**
     * Create a Tag field.
     *
     * @param  string  $relationship  Eloquent relationship method name (e.g. "tags")
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
        return 'tag';
    }

    /**
     * Set the URI key of the related resource for autocomplete API calls.
     */
    public function relatedResource(string $uriKey): static
    {
        $this->relatedUriKey = $uriKey;

        return $this;
    }

    /**
     * Set the attribute on the related model used for display.
     * Defaults to "name".
     */
    public function titleAttribute(string $attribute): static
    {
        $this->titleAttribute = $attribute;

        return $this;
    }

    /**
     * Enable preview popover when hovering tags.
     */
    public function withPreview(): static
    {
        $this->withPreview = true;

        return $this;
    }

    /**
     * Display tags as a vertical list instead of horizontal chips.
     */
    public function displayAsList(): static
    {
        $this->displayAsList = true;

        return $this;
    }

    /**
     * Show a button to create a new related record inline.
     */
    public function showCreateRelationButton(): static
    {
        $this->showCreateRelationButton = true;

        return $this;
    }

    /**
     * Set the size of the inline creation modal.
     *
     * Accepts sizes: 'sm', 'md', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl', '6xl', '7xl'
     */
    public function modalSize(ModalSize $size): static
    {
        $this->modalSize = $size;

        return $this;
    }

    /**
     * Preload all available tags when the field initializes.
     * Use for small tag sets; prefer server-side search for large sets.
     */
    public function preload(): static
    {
        $this->preload = true;

        return $this;
    }

    /**
     * Configure whether the field supports text search.
     */
    public function relationSearchable(bool $value = true): static
    {
        $this->relationSearchable = $value;

        return $this;
    }

    /**
     * Get relationship.
     */
    public function getRelationship(): string
    {
        return $this->relationship;
    }

    /**
     * Get title attribute.
     */
    public function getTitleAttribute(): string
    {
        return $this->titleAttribute;
    }

    /**
     * Get related resource.
     */
    public function getRelatedResource(): ?string
    {
        return $this->relatedUriKey;
    }

    /**
     * Has preview.
     */
    public function hasPreview(): bool
    {
        return $this->withPreview;
    }

    /**
     * Is display as list.
     */
    public function isDisplayAsList(): bool
    {
        return $this->displayAsList;
    }

    /**
     * Is show create relation button.
     */
    public function isShowCreateRelationButton(): bool
    {
        return $this->showCreateRelationButton;
    }

    /**
     * Get modal size.
     */
    public function getModalSize(): ModalSize
    {
        return $this->modalSize;
    }

    /**
     * Is preload.
     */
    public function isPreload(): bool
    {
        return $this->preload;
    }

    /**
     * Resolve: load related models via the BelongsToMany relationship.
     *
     * Returns list of {id, title} pairs for the frontend.
     *
     * @return list<array{id: mixed, title: string|null}>
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        if ($this->resolveCallback !== null) {
            return ($this->resolveCallback)(null, $model, $this->relationship);
        }

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
     * Fill: register a deferred pivot sync after the model is saved.
     *
     * Uses DeferredRelationSync (same pattern as BelongsTo::multiple()).
     * The actual sync() is called after model save so the model has an ID.
     */
    public function fill(Model $model, mixed $value): void
    {
        if ($this->readonly) {
            return;
        }

        if ($this->fillCallback !== null) {
            ($this->fillCallback)($model, $value, $this->relationship);

            return;
        }

        $ids = $this->extractIds($value);
        DeferredRelationSync::register($model, $this->relationship, $ids);
    }

    /**
     * Extract IDs from the tag value.
     *
     * Accepts: [1, 2, 3] or [['id' => 1], ...] or JSON string.
     *
     * @return list<int|string>
     */
    protected function extractIds(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $this->extractIds($decoded);
            }

            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter(array_map(function (mixed $item): int|string|null {
                if (is_array($item) && isset($item['id'])) {
                    return $item['id'];
                }

                if (is_string($item) && ($item === '' || $item === 'null')) {
                    return null;
                }

                if (is_int($item) || (is_string($item) && is_numeric($item))) {
                    return $item;
                }

                return null;
            }, $value), fn (mixed $v): bool => $v !== null));
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
            'titleAttribute' => $this->titleAttribute,
            'relatedResource' => $this->relatedUriKey,
            'withPreview' => $this->withPreview,
            'displayAsList' => $this->displayAsList,
            'showCreateRelationButton' => $this->showCreateRelationButton,
            'modalSize' => $this->modalSize->value,
            'preload' => $this->preload,
            'relationSearchable' => $this->relationSearchable,
        ], fn (mixed $v): bool => $v !== null && $v !== false && $v !== '');
    }
}
