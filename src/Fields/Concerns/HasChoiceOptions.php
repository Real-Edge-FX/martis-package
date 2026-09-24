<?php

namespace Martis\Fields\Concerns;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Traversable;

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
     * The values and labels of the static options, flipped for lookups and
     * built once for warnIfStoredAsLabel(); `options()` clears it.
     *
     * @var array{values: array<array-key, int>, labels: array<array-key, int>}|null
     */
    private ?array $optionsIndex = null;

    /**
     * Whether the static options came from a list (`array_is_list()`), keyed
     * 0, 1, 2... v1.x stored a list's items themselves, so a list of numbers
     * (`[1, 2, 3]`, `range(1, 12)`) shifted by one position with no error:
     * warnIfStoredAsLabel() checks such lists harder.
     */
    private bool $optionsFromList = false;

    /** Whether this field already warned, when no request can remember it. */
    private bool $optionsOrderWarned = false;

    /**
     * Set the options, in Nova's order.
     *
     *   - Map:        ['draft' => 'Draft', 'live' => 'Live']   value => label
     *   - List:       ['Small', 'Large']                       stores 0 and 1
     *   - Grouped:    ['MS' => ['label' => 'Small', 'group' => 'Men Sizes']]
     *   - Collection: User::query()->pluck('name', 'id')       any iterable or Arrayable, keys kept
     *   - Enum:       Status::class                            a backed case stores its value
     *   - Closure:    fn (Request|null $r) => User::query()->pluck('name', 'id')
     *
     * Nova 5 takes `iterable|callable|string` and reads it through
     * `collect()`; this takes the same iterables, plus any Arrayable. The
     * closure runs lazily in `getOptions()`. When each value is its own
     * label, pass `array_combine($values, $values)`.
     *
     * @param  iterable<int|string, mixed>|Arrayable<int|string, mixed>|class-string<\UnitEnum>|\Closure(Request|null): mixed  $options
     */
    public function options(iterable|Arrayable|string|\Closure $options): static
    {
        $this->optionsIndex = null;
        $this->optionsFromList = false;

        if ($options instanceof \Closure) {
            $this->optionsResolver = $options;
            $this->options = [];

            return $this;
        }

        $this->optionsResolver = null;

        if (is_string($options)) {
            if (! enum_exists($options)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s [%s]: options() received [%s], which is neither an iterable, a Closure nor an enum class.',
                    class_basename(static::class),
                    $this->attribute,
                    $options,
                ));
            }

            $this->options = $this->normalizeEnumOptions($options);

            return $this;
        }

        $raw = $this->resolvedOptionsToArray($options);
        $this->optionsFromList = $raw !== [] && array_is_list($raw);
        $this->options = $this->normalizeOptions($raw);

        return $this;
    }

    /**
     * The normalised options, running the lazy resolver when one is set. A
     * resolver may return an array, an Arrayable (a Collection) or any other
     * Traversable, exactly as Nova reads a closure through `collect()`; a
     * resolver returning anything else yields no options.
     *
     * @return list<array{label: string, value: int|string, group?: string}>
     */
    public function getOptions(): array
    {
        if ($this->optionsResolver === null) {
            return $this->options;
        }

        $resolved = ($this->optionsResolver)($this->safeRequest());

        return $this->normalizeOptions($this->resolvedOptionsToArray($resolved));
    }

    /**
     * Turn the options handed to `options()`, or returned by a resolver
     * closure, into a plain array, the way Nova reads them through
     * `collect()`: an array as-is, an Arrayable (a Collection) through
     * `->toArray()`, any other Traversable through `iterator_to_array()`
     * (keys kept), and anything else as no options at all.
     *
     * @return array<int|string, mixed>
     */
    private function resolvedOptionsToArray(mixed $resolved): array
    {
        if (is_array($resolved)) {
            return $resolved;
        }

        if ($resolved instanceof Arrayable) {
            return $resolved->toArray();
        }

        if ($resolved instanceof Traversable) {
            return iterator_to_array($resolved);
        }

        return [];
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
     * Warn when a stored value matches the label of an option and the value
     * of none: the sign of an options array still written label first (the
     * order before v2.0.0), or of a record saved while it was. Saving such a
     * record again would store the wrong value, so the warning is logged in
     * production too, once per model class and field per request. Only
     * static options are checked: a closure never runs just for this. The
     * caller passes the stored value, never what `resolveUsing()` made of it.
     *
     * Static options from a list are checked harder: v1.x stored the items of
     * `[1, 2, 3]` themselves, v2.0 stores their positions, so a stored 1 is
     * still a valid value (it now shows "2"). A stored value that is the
     * label of an option whose own value differs warns too; a list whose
     * labels equal their positions (`range(0, n)`) or are not numbers
     * (`['Small', 'Large']`) never matches.
     */
    protected function warnIfStoredAsLabel(Model $model, mixed $value): void
    {
        // A computed field stores nothing, so its value cannot be stale.
        if ($this->computed || $this->optionsResolver !== null || $this->options === []) {
            return;
        }

        $stored = array_filter(
            is_array($value) ? $value : [$value],
            static fn (mixed $item): bool => is_scalar($item) && $item !== '',
        );

        if ($stored === []) {
            return;
        }

        $this->optionsIndex ??= [
            'values' => array_flip(array_map(static fn (array $option): string => (string) $option['value'], $this->options)),
            'labels' => array_flip(array_column($this->options, 'label')),
        ];

        foreach ($stored as $item) {
            $item = (string) $item;

            if (! isset($this->optionsIndex['labels'][$item])) {
                continue;
            }

            if (! isset($this->optionsIndex['values'][$item])) {
                $this->logStoredAsLabel($model, $item, null);

                return;
            }

            $labelled = $this->options[$this->optionsIndex['labels'][$item]];

            if ($this->optionsFromList && (string) $labelled['value'] !== $item) {
                $shown = $this->options[$this->optionsIndex['values'][$item]]['label'];
                $this->logStoredAsLabel($model, $item, $shown);

                return;
            }
        }
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

    /**
     * @param  string|null  $shownAs  the label the value shows as now, when it
     *                                is also a valid position in a list
     */
    private function logStoredAsLabel(Model $model, string $value, ?string $shownAs): void
    {
        // Without an application (unit tests, raw scripts) there is no log.
        if (! app()->bound('log')) {
            return;
        }

        $key = $model::class.'|'.static::class.'::'.$this->attribute;
        $request = $this->safeRequest();

        if ($request !== null) {
            /** @var array<string, true> $warned */
            $warned = $request->attributes->get('martis.options_order_warned', []);

            if (isset($warned[$key])) {
                return;
            }

            $warned[$key] = true;
            $request->attributes->set('martis.options_order_warned', $warned);
        } elseif ($this->optionsOrderWarned) {
            return;
        }

        $this->optionsOrderWarned = true;
        $id = $model->getKey();

        $message = $shownAs === null
            ? sprintf(
                'Martis: %s #%s stores "%s" in %s [%s], which matches an option label and no option value. '
                .'Since v2.0.0 options() reads [value => label], as Nova does: flip the options array, '
                .'or fix the stored value if the record was saved while the array listed the label first. '
                .'See docs/upgrading.md.',
                $model::class,
                is_scalar($id) ? (string) $id : '?',
                $value,
                class_basename(static::class),
                $this->attribute,
            )
            : sprintf(
                'Martis: %s #%s stores "%s" in %s [%s], which is the label of another option of a list, so it shows as "%s". '
                .'Since v2.0.0 a list is keyed 0, 1, 2..., as in Nova, and v1.x stored the items themselves: '
                .'pass array_combine($values, $values) to keep storing them, or fix the stored value. '
                .'See docs/upgrading.md.',
                $model::class,
                is_scalar($id) ? (string) $id : '?',
                $value,
                class_basename(static::class),
                $this->attribute,
                $shownAs,
            );

        Log::warning($message, [
            'model' => $model::class,
            'key' => is_scalar($id) ? $id : null,
            'field' => static::class,
            'attribute' => $this->attribute,
            'value' => $value,
        ]);
    }
}
