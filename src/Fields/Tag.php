<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Tag field — relational tagging via BelongsToMany.
 *
 * Paridade com Laravel Nova v5: Tag field.
 * Referência: https://nova.laravel.com/docs/v5/resources/fields#tag-field
 *
 * Tag NÃO é um input livre de strings — é um field relacional que opera sobre
 * uma relação BelongsToMany do Eloquent, com attach/detach, autocomplete e
 * opções de preview/list.
 *
 * Contextos:
 *  - create: sim
 *  - update: sim
 *  - detail: sim
 *  - index: sim (representação resumida)
 *
 * Configuração:
 *   Tag::make('tags', 'Tags')
 *       ->relatedResource('tags')    // URI key do resource relacionado
 *       ->titleAttribute('name')     // atributo de exibição no model relacionado
 *       ->withPreview()              // mostra preview ao hovear
 *       ->displayAsList()            // exibe como lista em vez de chips
 *       ->showCreateRelationButton() // botão de criar relação inline
 *       ->modalSize('7xl')           // tamanho do modal de criação inline
 *       ->preload()                  // precarrega todos os tags disponíveis
 *
 * Fill: usa DeferredRelationSync para sincronizar a pivot table após o save.
 * Resolve: carrega os models relacionados via a relação Eloquent.
 */
class Tag extends Field
{
    protected string $relationship;

    protected string $titleAttribute = 'name';

    protected ?string $relatedUriKey = null;

    protected bool $withPreview = false;

    protected bool $displayAsList = false;

    protected bool $showCreateRelationButton = false;

    protected string $modalSize = '2xl';

    protected bool $preload = false;

    protected bool $relationSearchable = true;

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
     * Accepts Nova-style sizes: 'sm', 'md', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl', '6xl', '7xl'
     */
    public function modalSize(string $size): static
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

    public function getRelationship(): string
    {
        return $this->relationship;
    }

    public function getTitleAttribute(): string
    {
        return $this->titleAttribute;
    }

    public function getRelatedResource(): ?string
    {
        return $this->relatedUriKey;
    }

    public function hasPreview(): bool
    {
        return $this->withPreview;
    }

    public function isDisplayAsList(): bool
    {
        return $this->displayAsList;
    }

    public function isShowCreateRelationButton(): bool
    {
        return $this->showCreateRelationButton;
    }

    public function getModalSize(): string
    {
        return $this->modalSize;
    }

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
            'modalSize' => $this->modalSize,
            'preload' => $this->preload,
            'relationSearchable' => $this->relationSearchable,
        ], fn (mixed $v): bool => $v !== null && $v !== false && $v !== '');
    }
}
