<?php

namespace Martis\Metrics;

/**
 * Result for a Trend metric — time-series data for line/bar charts.
 */
class TrendResult extends MetricResult
{
    /** @var list<string> */
    protected array $labels = [];

    /** @var list<float|int> */
    protected array $values = [];

    protected ?string $prefix = null;

    protected ?string $suffix = null;

    protected bool $showLatestValue = false;

    protected bool $showSumValue = false;

    /**
     * @param  list<string>  $labels
     * @param  list<float|int>  $values
     */
    public function __construct(array $labels, array $values)
    {
        $this->labels = $labels;
        $this->values = $values;
    }

    public function prefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function suffix(string $suffix): static
    {
        $this->suffix = $suffix;

        return $this;
    }

    /**
     * Emphasize the latest data point value.
     */
    public function showLatestValue(): static
    {
        $this->showLatestValue = true;

        return $this;
    }

    /**
     * Show the sum of all values instead of the latest.
     */
    public function showSumValue(): static
    {
        $this->showSumValue = true;

        return $this;
    }

    public function toArray(): array
    {
        $data = [
            'labels' => $this->labels,
            'values' => $this->values,
        ];

        if ($this->prefix !== null) {
            $data['prefix'] = $this->prefix;
        }
        if ($this->suffix !== null) {
            $data['suffix'] = $this->suffix;
        }
        if ($this->showLatestValue) {
            $data['latestValue'] = end($this->values) ?: 0;
        }
        if ($this->showSumValue) {
            $data['sumValue'] = array_sum($this->values);
        }

        return $data;
    }
}
