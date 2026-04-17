<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Enums\ModalSize;
use Martis\Enums\PhosphorIcon;

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
     * Whether the peek/preview icon is shown for the related record.
     * Defaults to true (Nova v5 parity: peekable).
     *
     * When enabled, a small preview icon appears next to the related record link.
     * Hovering the icon fetches the related resource's preview fields
     * (via the peek endpoint, which uses fieldsForPreview()) and shows them
     * in a compact card — aligned with Nova v5's concept of peeking.
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
     * Whether to show the resource icon in the inline create modal header.
     * When true and no override is set, the related resource icon() is used.
     */
    protected bool $showResourceIcon = false;

    /**
     * Override icon for the inline create modal header.
     */
    protected ?PhosphorIcon $resourceIconOverride = null;

    /**
     * Subtitle config for the inline create modal header.
     * false = disabled, true = use resource subtitle(), string = fixed subtitle.
     */
    protected bool|string $resourceSubtitleValue = false;

    /**
     * Custom icon for the inline create button.
     */
    protected ?PhosphorIcon $createButtonIconValue = null;

    /**
     * Custom color for the inline create button.
     */
    protected ?string $createButtonColorValue = null;

    /**
     * Configurable placeholder text shown when no value is selected.
     * When null, falls back to the translated "select_field" key.
     */
    protected ?string $placeholder = null;

    /**
     * Custom color for the resource icon in the inline create modal header.
     * Accepts any CSS color string (hex, rgb, var(...)).
     */
    protected ?string $iconColor = null;

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

    /**
     * Type.
     */
    public function type(): string
    {
        return 'belongs_to';
    }

    /**
     * Return the foreign key as the serialization attribute.
     * This ensures the JSON key matches the actual DB column when foreignKey() is used.
     */
    public function attribute(): string
    {
        return $this->foreignKey;
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
     * @return array<string, mixed>|null
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

        /** @var array<string, mixed> $data */
        $data = [
            'id' => $foreignKeyValue,
            'title' => $related?->getAttribute($this->titleAttribute),
        ];

        if ($this->withSubtitles) {
            $data['subtitle'] = $related?->getAttribute($this->subtitleAttribute);
        }

        return $data;
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

        // Convert empty strings to null (from FormData serialization)
        if ($id === '' || $id === 'null') {
            $id = null;
        }

        $model->setAttribute($this->foreignKey, $id);
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
     * Nova v5 parity: ->modalSize(ModalSize::LG)
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
     * Enable or disable the peek/preview icon on the display component.
     * Defaults to true — pass false or call noPeeking() to disable.
     *
     * When enabled, a small preview icon appears next to the related record link.
     * Hovering the icon fetches the related resource's peek fields and shows
     * them in a compact card. Peek content is governed by fieldsForPreview()
     * on the related resource — not by a custom column list on this field.
     *
     * Nova v5 parity: ->peekable()
     */
    public function peekable(bool $value = true): static
    {
        $this->peekable = $value;

        return $this;
    }

    /**
     * Disable the peek/preview icon for this relationship.
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
     * Show the related resource icon in the inline create modal header.
     * Without args: uses the related resource icon().
     * With a PhosphorIcon: overrides with that specific icon.
     */
    public function resourceIcon(?PhosphorIcon $icon = null): static
    {
        $this->showResourceIcon = true;
        $this->resourceIconOverride = $icon;

        return $this;
    }

    /**
     * Show a subtitle in the inline create modal header.
     * true = uses the related resource subtitle().
     * string = uses the fixed string.
     */
    public function resourceSubtitle(bool|string $value = true): static
    {
        $this->resourceSubtitleValue = $value;

        return $this;
    }

    /**
     * Set a custom icon for the inline create button.
     * Defaults to Plus when not configured.
     */
    public function createButtonIcon(PhosphorIcon $icon): static
    {
        $this->createButtonIconValue = $icon;

        return $this;
    }

    /**
     * Set a custom color for the inline create button.
     * Accepts hex (e.g. #4F46E5) or any CSS color value.
     */
    public function createButtonColor(string $color): static
    {
        $this->createButtonColorValue = $color;

        return $this;
    }

    /**
     * Set the column attribute used as the display label in index/table cells.
     * Shorthand for titleAttribute() — uses the named column as the display text.
     *
     * Usage: BelongsTo::make("author")->displayColumn("full_name")
     */
    public function displayColumn(string $column): static
    {
        $this->titleAttribute = $column;

        return $this;
    }

    /**
     * Set a custom placeholder text shown on the trigger button when no value is selected.
     * Defaults to the translated "Select {field}..." string.
     *
     * Usage: BelongsTo::make("author")->placeholder("Choose an author...")
     */
    public function placeholder(string $text): static
    {
        $this->placeholder = $text;

        return $this;
    }

    /**
     * Set a custom color for the resource icon in the inline create modal header.
     *
     * Usage: BelongsTo::make("author")->resourceIcon()->iconColor("#6366f1")
     */
    public function iconColor(string $color): static
    {
        $this->iconColor = $color;

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
        $declared = $this->showCreateRelationButton instanceof \Closure
            ? (bool) ($this->showCreateRelationButton)(request())
            : $this->showCreateRelationButton;

        if (! $declared) {
            return false;
        }

        $auth = $this->relatedResourceAuthorizations($this->relatedUriKey);
        if ($auth === []) {
            return $declared;
        }

        return (bool) ($auth['authorizedToCreate'] ?? true);
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
            'displayAsLink' => $this->displayAsLink,
            'showCreateRelationButton' => $this->isShowCreateRelationButton(),
            'modalSize' => $this->getModalSize()->value,
            'withSubtitles' => $this->withSubtitles ?: null,
            'subtitleAttribute' => $this->withSubtitles ? $this->subtitleAttribute : null,
            'peekable' => $this->peekable,
            'withoutTrashed' => $this->withoutTrashed ?: null,
            'dontReorderAssociatables' => $this->dontReorderAssociatables ?: null,
            'showResourceIcon' => $this->showResourceIcon ?: null,
            'resourceIconOverride' => $this->resourceIconOverride?->value,
            'resourceSubtitle' => $this->resourceSubtitleValue !== false ? $this->resourceSubtitleValue : null,
            'createButtonIcon' => $this->createButtonIconValue?->value,
            'createButtonColor' => $this->createButtonColorValue,
        ], fn (mixed $v): bool => $v !== null) + $this->relatedResourceAuthorizations($this->relatedUriKey);
    }
}
