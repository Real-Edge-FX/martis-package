<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;

/**
 * BooleanGroup — map of named boolean flags stored as JSON.
 *
 * Laravel Nova v5 parity: BooleanGroup field.
 * Reference: https://nova.laravel.com/docs/v5/resources/fields#booleangroup-field
 *
 * The stored value is an associative array `['key' => bool, ...]`; the UI
 * renders one checkbox per option with its translated label.
 *
 * ⭐ Martis differentials:
 *  - `grouped([section => keys])` — organise options into collapsible
 *    sections so long flag lists (permissions, feature gates) stay
 *    manageable. Nova is a flat list only.
 *  - `minChecked(int)` / `maxChecked(int)` — validation constraints
 *    enforced on the backend AND surfaced as a live counter in the UI.
 *  - `requireAll()` / `requireAny()` convenience presets that compile
 *    down to the min/max constraints.
 *
 * Serialised to the frontend as `{ type: 'boolean_group', options,
 * labels, groups, hideFalseValues, hideTrueValues, noValueText,
 * minChecked, maxChecked }`.
 */
class BooleanGroup extends Field
{
    /** @var array<string, string> option key → raw label */
    protected array $options = [];

    /** @var array<string, string> option key → translated label (overrides raw) */
    protected array $labels = [];

    /** @var array<string, list<string>> section title → list of option keys (⭐ differential) */
    protected array $groups = [];

    protected bool $hideFalseValues = false;

    protected bool $hideTrueValues = false;

    protected ?string $noValueText = null;

    /** @var int|null ⭐ minimum checked count (inclusive) */
    protected ?int $minChecked = null;

    /** @var int|null ⭐ maximum checked count (inclusive) */
    protected ?int $maxChecked = null;

    public function type(): string
    {
        return 'boolean_group';
    }

    /**
     * Register the available flags.
     *
     * @param  array<string, string>  $options  key → label
     */
    public function options(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Override the raw option labels with translated versions.
     *
     * @param  array<string, string>  $labels
     */
    public function labels(array $labels): static
    {
        $this->labels = $labels;

        return $this;
    }

    /**
     * ⭐ Martis differential — organise options into named sections.
     * Rendered as collapsible panels in the UI.
     *
     * @param  array<string, list<string>>  $groups  section label → list of option keys
     */
    public function grouped(array $groups): static
    {
        $this->groups = $groups;

        return $this;
    }

    public function hideFalseValues(bool $value = true): static
    {
        $this->hideFalseValues = $value;

        return $this;
    }

    public function hideTrueValues(bool $value = true): static
    {
        $this->hideTrueValues = $value;

        return $this;
    }

    public function noValueText(string $text): static
    {
        $this->noValueText = $text;

        return $this;
    }

    /** ⭐ Martis differential — enforce a minimum number of checked flags. */
    public function minChecked(int $count): static
    {
        $this->minChecked = max(0, $count);

        return $this;
    }

    /** ⭐ Martis differential — cap the maximum number of checked flags. */
    public function maxChecked(int $count): static
    {
        $this->maxChecked = max(0, $count);

        return $this;
    }

    /** ⭐ Sugar for `minChecked(count(options))`. Forces every flag on. */
    public function requireAll(): static
    {
        return $this->minChecked(count($this->options));
    }

    /** ⭐ Sugar for `minChecked(1)`. Forces at least one flag on. */
    public function requireAny(): static
    {
        return $this->minChecked(1);
    }

    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $raw = parent::resolve($model, $attribute);

        if ($raw === null || $raw === '') {
            return $this->emptyPayload();
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->normalise($decoded);
            }
        }
        if (is_array($raw)) {
            return $this->normalise($raw);
        }

        return $this->emptyPayload();
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_filter([
            'options' => $this->options,
            'labels' => $this->labels !== [] ? $this->labels : null,
            'groups' => $this->groups !== [] ? $this->groups : null,
            'hideFalseValues' => $this->hideFalseValues ?: null,
            'hideTrueValues' => $this->hideTrueValues ?: null,
            'noValueText' => $this->noValueText,
            'minChecked' => $this->minChecked,
            'maxChecked' => $this->maxChecked,
        ], fn (mixed $v): bool => $v !== null);
    }

    /** @return array<string, bool> */
    public function getOptions(): array
    {
        return $this->options;
    }

    /** @return array<string, list<string>> */
    public function getGroups(): array
    {
        return $this->groups;
    }

    public function getMinChecked(): ?int
    {
        return $this->minChecked;
    }

    public function getMaxChecked(): ?int
    {
        return $this->maxChecked;
    }

    /** @return array<string, bool> */
    private function emptyPayload(): array
    {
        $out = [];
        foreach ($this->options as $key => $_) {
            $out[$key] = false;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, bool>
     */
    private function normalise(array $raw): array
    {
        $out = [];
        foreach ($this->options as $key => $_) {
            $out[$key] = (bool) ($raw[$key] ?? false);
        }

        return $out;
    }
}
