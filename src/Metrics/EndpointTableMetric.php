<?php

namespace Martis\Metrics;

use Martis\Enums\MetricType;

/**
 * A metric that renders as a compact HTTP route table with method chips,
 * throughput, latency, error-rate and share-of-traffic columns.
 *
 * Subclasses implement `calculate(Request $request): EndpointTableResult`
 * and populate rows via `$result->add(...)` or `$result->rows(...)`.
 *
 * @phpstan-consistent-constructor
 */
abstract class EndpointTableMetric extends Metric
{
    public function metricType(): MetricType
    {
        return MetricType::EndpointTable;
    }

    /**
     * Create an empty result instance.
     */
    protected function result(): EndpointTableResult
    {
        return new EndpointTableResult;
    }
}
