<?php

namespace Martis\Metrics;

/**
 * Result for an EndpointTable metric — compact list of HTTP routes with
 * throughput, latency, error and share columns. Renders as the
 * `EndpointTableCard` in the React layer.
 */
class EndpointTableResult extends MetricResult
{
    /** @var array<int, array<string, mixed>> */
    protected array $rows = [];

    protected float $errorWarnThreshold = 0.2;

    /**
     * Add a route row.
     *
     * @param  string  $method  HTTP verb (GET/POST/PUT/PATCH/DELETE).
     * @param  string  $path  Route path (rendered in mono).
     * @param  int|null  $rpm  Requests per minute.
     * @param  int|null  $latencyMs  P95 latency in milliseconds.
     * @param  float|null  $errorRate  Error ratio 0..1.
     * @param  float|null  $share  Share of total traffic 0..100.
     */
    public function add(
        string $method,
        string $path,
        ?int $rpm = null,
        ?int $latencyMs = null,
        ?float $errorRate = null,
        ?float $share = null,
    ): static {
        $this->rows[] = array_filter(
            [
                'method' => $method,
                'path' => $path,
                'rpm' => $rpm,
                'latencyMs' => $latencyMs,
                'errorRate' => $errorRate,
                'share' => $share,
            ],
            fn ($value) => $value !== null,
        );

        return $this;
    }

    /**
     * Bulk set rows — each row must match the {@see add()} shape.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function rows(array $rows): static
    {
        $this->rows = $rows;

        return $this;
    }

    /**
     * Highlight the Err column when the rate exceeds this ratio
     * (default: 0.2 = 20%).
     */
    public function errorWarnThreshold(float $threshold): static
    {
        $this->errorWarnThreshold = $threshold;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'errorWarnThreshold' => $this->errorWarnThreshold,
        ];
    }
}
