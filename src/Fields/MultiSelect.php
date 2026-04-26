<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * MultiSelect field — select multiple values from a predefined option list.
 *
 * MultiSelect is a first-class field, not an improvised Select with multiple.
 * Persists multiple values as a JSON array.
 *
 * Contexts:
 *  - create: yes
 *  - update: yes
 *  - detail: yes (renders chips/labels of selected values)
 *  - index: yes (renders summarised representation)
 *
 * API:
 *  - options(['Label' => 'value']) ou options(['value1', 'value2'])
 *  - displayUsingLabels()   — displays labels instead of raw values in detail/index
 *
 * Storage format: JSON array of values, e.g. ["php","laravel","react"]
 * Supports groups: options(['Group' => ['Label' => 'value']])
 */
class MultiSelect extends Field
{
    /**
     * @var list<array{label: string, value: scalar, group?: string}>
     */
    protected array $options = [];

    /**
     * Lazy resolver — set when `options()` was called with a Closure
     * instead of an array. The closure runs at schema-render time so
     * options can pull from the DB / config / current user.
     */
    protected ?\Closure $optionsResolver = null;

    protected bool $displayLabels = false;

    /**
     * Per-value colour map. Each entry may be a semantic keyword
     * (info, success, warning, danger, neutral) or a hex string.
     *
     * @var array<string, string>
     */
    protected array $colorMap = [];

    /**
     * Type.
     */
    public function type(): string
    {
        return 'multi_select';
    }

    /**
     * Set the available options.
     *
     * Accepts four formats:
     *   - Sequential: ['php', 'laravel']          (value used as label)
     *   - Associative: ['PHP' => 'php']            (label => value)
     *   - Grouped: ['Backend' => ['PHP' => 'php']] (group => [label => value])
     *   - Closure: fn (Request|null $r) => [...]   (any of the above shapes)
     *
     * The closure form is evaluated lazily via `getOptions()` — perfect
     * for options that come from the database, depend on the active
     * user, or change per locale.
     *
     * @param  array<string, scalar|array<string, scalar>>|list<scalar>|\Closure(Request|null): array  $options
     */
    public function options(array|\Closure $options): static
    {
        if ($options instanceof \Closure) {
            $this->optionsResolver = $options;
            $this->options = [];

            return $this;
        }

        $this->optionsResolver = null;
        $this->options = $this->normalizeOptions($options);

        return $this;
    }

    /**
     * Normalize a raw options array into the internal label/value/group
     * shape. Extracted so the Closure path can reuse it.
     *
     * @param  array<int|string, scalar|array<string, scalar>>  $raw
     * @return list<array{label: string, value: scalar, group?: string}>
     */
    protected function normalizeOptions(array $raw): array
    {
        $out = [];

        foreach ($raw as $key => $value) {
            // Sequential
            if (is_int($key) && ! is_array($value)) {
                $out[] = ['label' => (string) $value, 'value' => $value];

                continue;
            }

            // Grouped: key is group label, value is nested array
            if (is_string($key) && is_array($value)) {
                foreach ($value as $label => $val) {
                    $out[] = ['label' => (string) $label, 'value' => $val, 'group' => $key];
                }

                continue;
            }

            // Associative: key is label, value is stored value
            if (is_string($key)) {
                $out[] = ['label' => $key, 'value' => $value];
            }
        }

        return $out;
    }

    /**
     * Display labels instead of raw values in index/detail views.
     */
    public function displayUsingLabels(): static
    {
        $this->displayLabels = true;

        return $this;
    }

    /**
     * Map each option value to a pill colour.
     *
     * Each entry may be a semantic keyword (info, success, warning,
     * danger, neutral) or a hex string like `#FF2D20`.
     *
     * @param  array<string, string>  $map
     */
    public function colors(array $map): static
    {
        $this->colorMap = array_map(
            static fn (mixed $v): string => (string) $v,
            $map,
        );

        return $this;
    }

    /**
     * @return list<array{label: string, value: scalar, group?: string}>
     */
    public function getOptions(): array
    {
        if ($this->optionsResolver !== null) {
            $request = $this->safeRequest();
            $resolved = ($this->optionsResolver)($request);

            return is_array($resolved) ? $this->normalizeOptions($resolved) : [];
        }

        return $this->options;
    }

    /**
     * Is displaying labels.
     */
    public function isDisplayingLabels(): bool
    {
        return $this->displayLabels;
    }

    /**
     * Resolve: decode JSON/array to list of scalar values.
     *
     * @return list<scalar>
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        if ($this->resolveCallback !== null) {
            return ($this->resolveCallback)(
                $model->getAttribute($attribute ?? $this->attribute),
                $model,
                $attribute ?? $this->attribute,
                $this->safeRequest(),
            );
        }

        $raw = $model->getAttribute($attribute ?? $this->attribute);

        return $this->decodeToArray($raw);
    }

    /**
     * Fill: accept array or JSON string, store as JSON array.
     */
    public function fill(Model $model, mixed $value): void
    {
        if ($this->readonly) {
            return;
        }

        if ($this->fillCallback !== null) {
            ($this->fillCallback)($model, $value, $this->attribute, $this->safeRequest());

            return;
        }

        $values = $this->decodeToArray($value);
        $model->setAttribute($this->attribute, empty($values) ? null : json_encode($values, JSON_THROW_ON_ERROR));
    }

    /**
     * Decode raw value to a flat list of scalars.
     *
     * @return list<scalar>
     */
    public function decodeToArray(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_array($raw)) {
            return array_values(array_filter($raw, fn (mixed $v): bool => is_scalar($v)));
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->decodeToArray($decoded);
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [
            'options' => $this->getOptions(),
            'displayLabels' => $this->displayLabels,
            'colorMap' => $this->colorMap,
        ];
    }
}
