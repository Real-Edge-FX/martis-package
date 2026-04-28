<?php

namespace Martis\Fields;

use Illuminate\Http\Request;

/**
 * Dropdown select field.
 *
 * Renders as a `<select>` in the React frontend.
 * Options may be a flat list of values or an associative label => value map.
 */
class Select extends Field
{
    /** @var list<array{label: string, value: scalar}> */
    protected array $options = [];

    /**
     * Lazy resolver — set when `options()` was called with a Closure
     * instead of an array. The closure runs at schema-render time so
     * options can pull from the DB / config / current user.
     */
    protected ?\Closure $optionsResolver = null;

    /**
     * Type.
     */
    public function type(): string
    {
        return 'select';
    }

    /**
     * Set the available options for the select.
     *
     * Accepts three formats:
     *   - Associative array: ['Active' => 1, 'Inactive' => 0]  (label => value)
     *   - Sequential array:  ['draft', 'published', 'archived'] (value used as label too)
     *   - Closure:           fn (Request|null $r) => User::pluck('name', 'id')->all()
     *
     * The closure form is evaluated lazily via `getOptions()` — perfect
     * for options that come from the database, depend on the active
     * user, or change per locale.
     *
     * @param  array<string, scalar>|list<scalar>|class-string<\UnitEnum>|\Closure(Request|null): array  $options
     */
    public function options(array|string|\Closure $options): static
    {
        // PHP 8.1+ Enum class — derive options from cases().
        // Backed enum: value => name (e.g. `'active' => 'Active'`).
        // Pure enum: name => name (case acts as both label + value).
        if (is_string($options) && enum_exists($options)) {
            $this->optionsResolver = null;
            $this->options = $this->normalizeEnumOptions($options);

            return $this;
        }

        if ($options instanceof \Closure) {
            $this->optionsResolver = $options;
            $this->options = [];

            return $this;
        }

        $this->optionsResolver = null;
        /** @var array<int|string, scalar> $options */
        $this->options = $this->normalizeOptions($options);

        return $this;
    }

    /**
     * Build the internal label/value shape from a PHP 8.1+ Enum class.
     *
     * Conventions:
     *  - **Backed enum (`enum Status: string`)** — `value` = case `value`,
     *    `label` = case `name` humanised via `Str::headline()` so a case
     *    `InProgress` reads as "In Progress" in the dropdown.
     *  - **Pure enum (`enum Status`)** — `value` = case `name`, `label`
     *    = humanised case `name`. Without backing values there is nothing
     *    else to persist.
     *
     * Override the labels by re-mapping post-call if the headline
     * transform is wrong for the consumer's domain (e.g. acronyms).
     *
     * @param  class-string<\UnitEnum>  $enumClass
     * @return list<array{label: string, value: scalar}>
     */
    protected function normalizeEnumOptions(string $enumClass): array
    {
        $out = [];

        foreach ($enumClass::cases() as $case) {
            $value = $case instanceof \BackedEnum ? $case->value : $case->name;
            $out[] = [
                'label' => \Illuminate\Support\Str::headline($case->name),
                'value' => $value,
            ];
        }

        return $out;
    }

    /**
     * Normalize a raw options array into the internal label/value
     * shape. Extracted so the Closure path can reuse it.
     *
     * @param  array<int|string, scalar>  $raw
     * @return list<array{label: string, value: scalar}>
     */
    protected function normalizeOptions(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            if (is_int($key)) {
                $out[] = ['label' => (string) $value, 'value' => $value];
            } else {
                $out[] = ['label' => $key, 'value' => $value];
            }
        }

        return $out;
    }

    /**
     * Define options from a stable [value => label] map.
     *
     * More ergonomic than `options()` when labels come from i18n, because
     * the value (what's persisted) stays unchanged while the label can
     * be translated:
     *
     *   Select::make('plan')->optionsFromMap([
     *       'free'       => __('plan.free'),
     *       'pro'        => __('plan.pro'),
     *       'enterprise' => __('plan.enterprise'),
     *   ]);
     *
     * @param  array<int|string, string>  $map  value => label pairs
     */
    public function optionsFromMap(array $map): static
    {
        $this->options = [];

        foreach ($map as $value => $label) {
            $this->options[] = ['label' => (string) $label, 'value' => $value];
        }

        return $this;
    }

    /**
     * Return the normalized options array.
     *
     * @return list<array{label: string, value: scalar}>
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
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return ['options' => $this->getOptions()];
    }
}
