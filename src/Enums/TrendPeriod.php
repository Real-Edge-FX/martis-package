<?php

namespace Martis\Enums;

use Martis\Metrics\TrendMetric;

/**
 * Time-bucket unit for {@see TrendMetric} aggregation.
 */
enum TrendPeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
}
