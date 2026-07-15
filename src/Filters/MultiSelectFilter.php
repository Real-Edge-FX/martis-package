<?php

namespace Martis\Filters;

use Martis\Enums\FilterType;

/**
 * A searchable dropdown filter that lets users pick MULTIPLE values from a
 * list — an "any of these" facet (e.g. a growing set of tags). The selected
 * value is an array of the chosen `value`s.
 *
 * Mirrors {@see SelectFilter}: override `options()` for the choices and
 * `apply()` to match. Because the value is an array, `apply()` is typically a
 * `whereIn()` (ANY-match):
 *
 *     use Martis\Filters\MultiSelectFilter;
 *
 *     class TagsFilter extends MultiSelectFilter
 *     {
 *         public function options(Request $request): array
 *         {
 *             return ['PHP' => 'php', 'Laravel' => 'laravel', 'Vue' => 'vue'];
 *         }
 *
 *         public function apply(Request $request, Builder $query, mixed $value): Builder
 *         {
 *             return $query->whereHas('tags', fn ($q) => $q->whereIn('slug', (array) $value));
 *         }
 *     }
 *
 * Martis skips empty selections before `apply()` runs (an empty array carries
 * no constraint, so it shows all records), which means you never have to guard
 * against `whereIn('col', [])` compiling to `WHERE 0 = 1`. If you call `apply()`
 * directly outside the request pipeline, add your own `empty($value)` guard.
 *
 * Enable search with `->searchable()` (inherited from SelectFilter). The React
 * side renders a PrimeReact MultiSelect.
 *
 * @phpstan-consistent-constructor
 */
abstract class MultiSelectFilter extends SelectFilter
{
    public function filterType(): FilterType
    {
        return FilterType::MultiSelect;
    }
}
