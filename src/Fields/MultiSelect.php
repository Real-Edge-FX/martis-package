<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\Concerns\HasChoiceOptions;

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
 *  - options(['php' => 'PHP', 'go' => 'Go'])   [value => label], as in Nova
 *  - options(['php' => ['label' => 'PHP', 'group' => 'Backend']])   grouped, as in Nova
 *  - options(Tag::class) or options(fn (Request|null $r) => [...])   enum class, closure
 *  - displayUsingLabels()   displays labels instead of raw values in detail/index
 *
 * Storage format: JSON array of values, e.g. ["php","laravel","react"]
 */
class MultiSelect extends Field
{
    use HasChoiceOptions;

    protected bool $displayLabels = false;

    /**
     * Per-value colour map. Each entry may be a semantic keyword
     * (info, success, warning, danger, neutral) or a hex string.
     *
     * @var array<string, string>
     */
    protected array $colorMap = [];

    /** {@inheritdoc} */
    public function type(): string
    {
        return 'multi_select';
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
     * Is displaying labels.
     */
    public function isDisplayingLabels(): bool
    {
        return $this->displayLabels;
    }

    /** {@inheritdoc} */
    public function hasStructuredValue(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * Delegates to {@see Field::resolve()}, which reads the model once. With
     * no `resolveCallback` the stored value comes back raw, and `MultiSelect`
     * decodes it to a list; a `resolveCallback` receives the raw value, as
     * before, and its result is returned as is.
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $value = parent::resolve($model, $attribute);

        return $this->resolveCallback !== null ? $value : $this->decodeToArray($value);
    }

    /**
     * The stored values, before `resolveUsing()`, are checked against static
     * options, see HasChoiceOptions::warnIfStoredAsLabel().
     */
    protected function inspectResolvedValue(Model $model, string $attribute, mixed $value): void
    {
        if ($this->checksStoredOptionOrder()) {
            $this->warnIfStoredAsLabel($model, $this->decodeToArray($value));
        }
    }

    /** {@inheritdoc} */
    public function fill(Model $model, mixed $value): void
    {
        if ($this->isReadonly()) {
            return;
        }

        if ($this->fillCallback !== null) {
            ($this->fillCallback)($model, $value, $this->attribute, $this->safeRequest());

            return;
        }

        // A computed field has no backing attribute to write (see Field::fill()).
        if ($this->computed) {
            return;
        }

        $values = $this->decodeToArray($value);
        $model->setAttribute(
            $this->attribute,
            $this->storableStructuredValue($model, $this->attribute, $values === [] ? null : $values),
        );
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
