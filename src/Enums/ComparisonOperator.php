<?php

namespace Martis\Enums;

/**
 * Comparison operators for date and numeric filters.
 */
enum ComparisonOperator: string
{
    case Equals = '=';
    case GreaterThanOrEqual = '>=';
    case LessThanOrEqual = '<=';
    case GreaterThan = '>';
    case LessThan = '<';
}
