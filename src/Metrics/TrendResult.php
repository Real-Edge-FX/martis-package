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

    protected bool $sparkline = false;

    protected ?float $change = null;

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

    /**
     * Render the metric as an inline sparkline + delta pill instead of
     * the full Chart.js panel. Pairs with `.martis-dash-kpis` rows to
     * fit four trend metrics across the top of a dashboard.
     */
    public function sparkline(bool $enabled = true): static
    {
        $this->sparkline = $enabled;

        return $this;
    }

    /**
     * Override the period-over-period delta percentage shown next to
     * the sparkline. By default the result computes its own delta
     * between the last and first value, so most callers leave this
     * alone.
     */
    public function change(?float $percentage): static
    {
        $this->change = $percentage;

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
        if ($this->showLatestValue && $this->values !== []) {
            $data['latestValue'] = end($this->values);
        }
        if ($this->showSumValue) {
            $data['sumValue'] = array_sum($this->values);
        }
        if ($this->sparkline) {
            $data['sparkline'] = true;

            // computeDelta() returns null when the series can't produce a
            // meaningful delta (empty, or first bucket is zero — common
            // for brand-new data). Omit `change` entirely in that case:
            // emitting `change: null` made the card render the literal
            // string "null%", because the frontend guard only checked
            // `!== undefined` and a JSON null is not undefined.
            $change = $this->change ?? $this->computeDelta();
            if ($change !== null) {
                $data['change'] = $change;
            }
        }

        return $data;
    }

    /**
     * Period-over-period percentage change from the first non-null
     * value in the series to the last. Returns null when the data
     * cannot produce a meaningful delta (empty / first value zero).
     */
    protected function computeDelta(): ?float
    {
        if ($this->values === []) {
            return null;
        }
        $first = $this->values[0];
        $last = end($this->values);
        if ($first === 0 || $first === 0.0 || ! is_numeric($first) || ! is_numeric($last)) {
            return null;
        }

        return round((($last - $first) / $first) * 100, 1);
    }
}
