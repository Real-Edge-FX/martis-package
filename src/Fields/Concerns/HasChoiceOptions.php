<?php

namespace Martis\Fields\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The option list of a choice field (Select, MultiSelect), in Nova's order.
 *
 * `options()` reads `[value => label]`: the key is what the field stores
 * and the value is what the user sees, so `User::pluck('name', 'id')`
 * stores the id. A list is a map keyed 0, 1, 2..., so `['Small', 'Large']`
 * stores 0 and 1, as in Nova. A grouped option maps its value to
 * `['label' => ..., 'group' => ...]`. Filters keep Nova's filter order,
 * `[label => value]` (see Filter::options()).
 *
 * Before v2.0.0 the array was read label first; see docs/upgrading.md.
 */
trait HasChoiceOptions
{
    /** @var list<array{label: string, value: int|string, group?: string}> */
    protected array $options = [];

    /**
     * Lazy resolver, set when `options()` receives a Closure. It runs when
     * the options are read, so they can come from the database, the config
     * or the current user.
     */
    protected ?\Closure $optionsResolver = null;

    /**
     * Set the options, in Nova's order.
     *
     *   - Map:     ['draft' => 'Draft', 'live' => 'Live']   value => label
     *   - List:    ['Small', 'Large']                       stores 0 and 1
     *   - Grouped: ['MS' => ['label' => 'Small', 'group' => 'Men Sizes']]
     *   - Enum:    Status::class                            a backed case stores its value
     *   - Closure: fn (Request|null $r) => User::query()->pluck('name', 'id')->all()
     *
     * The closure runs lazily in `getOptions()`. When each value is its own
     * label, pass `array_combine($values, $values)`.
     *
     * @param  array<int|string, mixed>|class-string<\UnitEnum>|\Closure(Request|null): mixed  $options
     */
    public function options(array|string|\Closure $options): static
    {
        if ($options instanceof \Closure) {
            $this->optionsResolver = $options;
            $this->options = [];

            return $this;
        }

        $this->optionsResolver = null;

        if (is_string($options)) {
            if (! enum_exists($options)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s [%s]: options() received [%s], which is neither an array, a Closure nor an enum class.',
                    class_basename(static::class),
                    $this->attribute,
                    $options,
                ));
            }

            $this->options = $this->normalizeEnumOptions($options);

            return $this;
        }

        $this->options = $this->normalizeOptions($options);

        return $this;
    }

    /**
     * The normalised options, running the lazy resolver when one is set. A
     * resolver that returns anything but an array yields no options.
     *
     * @return list<array{label: string, value: int|string, group?: string}>
     */
    public function getOptions(): array
    {
        if ($this->optionsResolver === null) {
            return $this->options;
        }

        $resolved = ($this->optionsResolver)($this->safeRequest());

        if (! is_array($resolved)) {
            return [];
        }

        /** @var array<int|string, mixed> $resolved */
        return $this->normalizeOptions($resolved);
    }

    /**
     * Normalise a `[value => label]` array into the payload shape.
     *
     * @param  array<int|string, mixed>  $raw
     * @return list<array{label: string, value: int|string, group?: string}>
     */
    protected function normalizeOptions(array $raw): array
    {
        $out = [];

        foreach ($raw as $value => $label) {
            $out[] = is_array($label)
                ? $this->normalizeGroupedOption($value, $label)
                : ['label' => $this->optionText($value, $label, 'label'), 'value' => $value];
        }

        return $out;
    }

    /**
     * Options from an enum class: a backed case stores its value, a pure
     * case its name, and the label is the case name as a headline
     * (`InProgress` reads "In Progress").
     *
     * @param  class-string<\UnitEnum>  $enumClass
     * @return list<array{label: string, value: int|string}>
     */
    protected function normalizeEnumOptions(string $enumClass): array
    {
        $out = [];

        foreach ($enumClass::cases() as $case) {
            $out[] = [
                'label' => Str::headline($case->name),
                'value' => $case instanceof \BackedEnum ? $case->value : $case->name,
            ];
        }

        return $out;
    }

    /**
     * One grouped option in Nova's format. Any other array is rejected:
     * before v2.0.0 a MultiSelect group was `['Group' => ['Label' => 'value']]`,
     * which Nova's order reads as an option whose value is the group name.
     *
     * @param  array<array-key, mixed>  $entry
     * @return array{label: string, value: int|string, group?: string}
     */
    private function normalizeGroupedOption(int|string $value, array $entry): array
    {
        if (! array_key_exists('label', $entry) || array_diff(array_keys($entry), ['label', 'group']) !== []) {
            throw new \InvalidArgumentException(sprintf(
                '%s [%s]: the option [%s] maps to an array that is not a grouped option. '
                .'Since v2.0.0 options() reads [value => label], as Nova does; '
                ."a grouped option is [value => ['label' => ..., 'group' => ...]]. See docs/upgrading.md.",
                class_basename(static::class),
                $this->attribute,
                $value,
            ));
        }

        $option = ['label' => $this->optionText($value, $entry['label'], 'label'), 'value' => $value];

        $group = $entry['group'] ?? null;
        if ($group !== null && $group !== '') {
            $option['group'] = $this->optionText($value, $group, 'group');
        }

        return $option;
    }

    /** A label or group name as text; anything that cannot be a string is rejected. */
    private function optionText(int|string $value, mixed $text, string $what): string
    {
        if (is_scalar($text) || $text === null || $text instanceof \Stringable) {
            return (string) $text;
        }

        throw new \InvalidArgumentException(sprintf(
            '%s [%s]: the %s of option [%s] must be a string, got %s.',
            class_basename(static::class),
            $this->attribute,
            $what,
            $value,
            get_debug_type($text),
        ));
    }
}
