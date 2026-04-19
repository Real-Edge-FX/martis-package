<?php

namespace Martis\Enums;

/**
 * Metric kind emitted in the schema payload — drives the React renderer
 * choice (big-number, trend chart, partition chart, progress bar).
 */
enum MetricType: string
{
    case Value = 'value';
    case Trend = 'trend';
    case Partition = 'partition';
    case Progress = 'progress';
}
