<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;

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

    protected bool $displayLabels = false;

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
     * Accepts three formats:
     *   - Sequential: ['php', 'laravel']          (value used as label)
     *   - Associative: ['PHP' => 'php']            (label => value)
     *   - Grouped: ['Backend' => ['PHP' => 'php']] (group => [label => value])
     *
     * @param  array<string, scalar|array<string, scalar>>|list<scalar>  $options
     */
    public function options(array $options): static
    {
        $this->options = [];

        foreach ($options as $key => $value) {
            // Sequential
            if (is_int($key) && ! is_array($value)) {
                $this->options[] = ['label' => (string) $value, 'value' => $value];

                continue;
            }

            // Grouped: key is group label, value is nested array
            if (is_string($key) && is_array($value)) {
                foreach ($value as $label => $val) {
                    $this->options[] = ['label' => (string) $label, 'value' => $val, 'group' => $key];
                }

                continue;
            }

            // Associative: key is label, value is stored value
            if (is_string($key)) {
                $this->options[] = ['label' => $key, 'value' => $value];
            }
        }

        return $this;
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
     * @return list<array{label: string, value: scalar, group?: string}>
     */
    public function getOptions(): array
    {
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
            ($this->fillCallback)($model, $value, $this->attribute);

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
            'options' => $this->options,
            'displayLabels' => $this->displayLabels,
        ];
    }
}
