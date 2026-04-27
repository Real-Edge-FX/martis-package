<?php

namespace Martis\Contracts;

use Closure;
use DateTimeInterface;
use Illuminate\Http\Request;
use Martis\Enums\MetricType;

/**
 * Contract for resource and dashboard metrics.
 *
 * Metrics compute and display analytical data: values, trends,
 * partitions, and progress indicators.
 */
interface MetricContract
{
    /**
     * Create a new metric instance.
     */
    public static function make(string $name, ?string $uriKey = null): static;

    /**
     * Calculate the metric value for the given request.
     */
    public function calculate(Request $request): mixed;

    /**
     * The metric type identifier.
     */
    public function metricType(): MetricType;

    /**
     * Human-readable metric name.
     */
    public function name(): string;

    /**
     * Stable URI key for API requests.
     */
    public function uriKey(): string;

    /**
     * Get the available date ranges for this metric.
     *
     * @return array<int|string, string>
     */
    public function ranges(): array;

    /**
     * How long to cache the metric result.
     */
    public function cacheFor(): ?DateTimeInterface;

    /**
     * Set a callback that determines if the metric should be visible.
     */
    public function canSee(Closure $callback): static;

    /**
     * Determine if the metric should be visible for the given request.
     */
    public function authorizedToSee(Request $request): bool;

    /**
     * Serialize the metric definition for the schema endpoint.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
