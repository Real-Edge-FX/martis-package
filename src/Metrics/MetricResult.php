<?php

namespace Martis\Metrics;

/**
 * Base class for metric calculation results.
 */
abstract class MetricResult
{
    /**
     * Serialize the result for the API response.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}
