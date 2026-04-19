<?php

namespace Martis\Filters;

use Martis\Enums\FilterType;

/**
 * A multi-checkbox filter that lets users toggle multiple boolean options.
 *
 * Override options() to provide the available toggles:
 *
 *     public function options(Request $request): array
 *     {
 *         return [
 *             'Admin'  => 'is_admin',
 *             'Active' => 'is_active',
 *         ];
 *     }
 *
 * The $value passed to apply() will be an associative array of
 * option values mapped to their checked state (true/false).
 *
 * @phpstan-consistent-constructor
 */
abstract class BooleanFilter extends Filter
{
    public function filterType(): FilterType
    {
        return FilterType::Boolean;
    }
}
