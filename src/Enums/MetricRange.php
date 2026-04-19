<?php

namespace Martis\Enums;

/**
 * Named date-range presets for value/trend/partition metrics. Numeric
 * day ranges (e.g. 30, 60, 365) remain plain integers and sit alongside
 * the presets in the user-facing `ranges()` map.
 */
enum MetricRange: string
{
    case Today = 'TODAY';
    case MonthToDate = 'MTD';
    case QuarterToDate = 'QTD';
    case YearToDate = 'YTD';
}
