<?php

namespace Martis\Metrics;

use Martis\Enums\MetricType;

/**
 * A metric that renders as a chronological feed of recent events
 * (coloured Phosphor tile + actor/verb/target line + timestamp).
 *
 * Subclasses implement `calculate(Request $request): ActivityFeedResult`
 * and populate rows via `$result->add(...)` or `$result->items(...)`.
 *
 * @phpstan-consistent-constructor
 */
abstract class ActivityFeedMetric extends Metric
{
    public function metricType(): MetricType
    {
        return MetricType::ActivityFeed;
    }

    /**
     * Create an empty result instance.
     */
    protected function result(): ActivityFeedResult
    {
        return new ActivityFeedResult;
    }
}
