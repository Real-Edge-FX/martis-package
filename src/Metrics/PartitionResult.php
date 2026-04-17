<?php

namespace Martis\Metrics;

use Closure;

/**
 * Result for a Partition metric — grouped data for pie/donut charts.
 */
class PartitionResult extends MetricResult
{
    /** @var list<string> */
    protected array $labels = [];

    /** @var list<float|int> */
    protected array $values = [];

    /** @var list<string>|null */
    protected ?array $colors = null;

    protected ?Closure $labelCallback = null;

    /**
     * @param  array<string, float|int>  $data  Label => value pairs
     */
    public function __construct(array $data)
    {
        $this->labels = array_keys($data);
        $this->values = array_values($data);
    }

    /**
     * Set custom colors for partition slices.
     *
     * Two formats supported:
     * 1. Sequential array (order matches labels):
     *    ->colors(['#22c55e', '#f59e0b', '#3b82f6'])
     *
     * 2. Associative map (label => color, order-independent):
     *    ->colors(['active' => '#22c55e', 'paused' => '#f59e0b', 'archived' => '#6b7280'])
     *
     * @param  array<int|string, string>  $colors
     */
    public function colors(array $colors): static
    {
        $this->colors = $colors;

        return $this;
    }

    /**
     * Transform labels with a callback.
     */
    public function label(Closure $callback): static
    {
        $this->labelCallback = $callback;

        return $this;
    }

    public function toArray(): array
    {
        $labels = $this->labels;

        if ($this->labelCallback !== null) {
            $labels = array_map($this->labelCallback, $labels);
        }

        $data = [
            'labels' => $labels,
            'values' => $this->values,
        ];

        if ($this->colors !== null) {
            $data['colors'] = $this->colors;
        }

        return $data;
    }
}
