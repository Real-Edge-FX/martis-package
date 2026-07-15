<?php

namespace Martis\Enums;

/**
 * Filter kind emitted in the schema payload. Drives the React side's
 * component choice and keeps custom filters honest about their shape.
 */
enum FilterType: string
{
    case Boolean = 'boolean';
    case Select = 'select';
    case MultiSelect = 'multi-select';
    case Date = 'date';
    case DateRange = 'date-range';
}
