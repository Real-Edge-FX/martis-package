<?php

namespace Martis\Enums;

/**
 * Time-bucket unit for {@see \Martis\Metrics\TrendMetric} aggregation.
 */
enum TrendPeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
}
