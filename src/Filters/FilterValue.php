<?php

namespace Martis\Filters;

use Illuminate\Http\Request;
use Martis\Contracts\FilterContract;
use Martis\Enums\FilterType;

/**
 * The value a filter's `apply()` receives from the request.
 *
 * The `filters` query parameter is client input: its JSON is decoded and
 * handed to the filter under its URI key. A filter reads that value and
 * usually turns it into a query, so the package fixes what the value can be
 * before `apply()` runs, in every place filters are applied (the index, a
 * lens, the dashboard cards), instead of leaving each hand-written filter to
 * remember it.
 *
 * A `BooleanFilter` value is a map of its option values to a checked state.
 * Its keys are the options the filter declares and nothing else: a key the
 * request invents (`is_admin`, a column of a joined table, a JSON path
 * `settings->flag`) never reaches `apply()`, so a filter that uses a key as a
 * column name cannot be pointed at a column the filter does not offer. Each
 * remaining value is a boolean (`true`, `1`, `'1'`, `'true'`, `'on'` and
 * `'yes'` are checked, everything else is not).
 */
final class FilterValue
{
    /**
     * The value `apply()` should receive, or `null` when the request leaves
     * nothing to apply (a boolean filter whose submitted keys are all outside
     * its options).
     */
    public static function resolve(FilterContract $filter, Request $request, mixed $value): mixed
    {
        if ($filter->filterType() !== FilterType::Boolean) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $allowed = array_flip(self::optionValues($filter->options($request)));
        $checked = [];

        foreach ($value as $key => $state) {
            if (! isset($allowed[(string) $key])) {
                continue;
            }

            $checked[$key] = is_scalar($state) && filter_var($state, FILTER_VALIDATE_BOOLEAN);
        }

        return $checked === [] ? null : $checked;
    }

    /**
     * The values of a filter's options, flat or grouped
     * (`['Group' => ['Label' => 'value']]`), as strings.
     *
     * @param  array<array-key, mixed>  $options
     * @return list<string>
     */
    private static function optionValues(array $options): array
    {
        $values = [];

        foreach ($options as $value) {
            if (is_array($value)) {
                array_push($values, ...self::optionValues($value));
            } elseif (is_scalar($value)) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }
}
