<?php

namespace Martis\Metrics;

/**
 * Result for a Progress metric — current vs target with progress bar.
 */
class ProgressResult extends MetricResult
{
    protected float|int $current;

    protected float|int $target;

    protected ?string $prefix = null;

    protected ?string $suffix = null;

    protected bool $avoid = false;

    public function __construct(float|int $current, float|int $target)
    {
        if ($target < 0) {
            throw new \InvalidArgumentException('ProgressResult target must be >= 0.');
        }

        $this->current = $current;
        $this->target = $target;
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
     * Shortcut for dollar prefix.
     */
    public function dollars(): static
    {
        return $this->prefix('$');
    }

    /**
     * Shortcut for euro prefix.
     */
    public function euros(): static
    {
        return $this->prefix('€');
    }

    /**
     * Indicate the goal is to minimize (avoid) rather than maximize.
     */
    public function avoid(): static
    {
        $this->avoid = true;

        return $this;
    }

    public function toArray(): array
    {
        $percentage = $this->target > 0
            ? round(($this->current / $this->target) * 100, 1)
            : 0;

        $data = [
            'current' => $this->current,
            'target' => $this->target,
            'percentage' => $percentage,
            'avoid' => $this->avoid,
        ];

        if ($this->prefix !== null) {
            $data['prefix'] = $this->prefix;
        }
        if ($this->suffix !== null) {
            $data['suffix'] = $this->suffix;
        }

        return $data;
    }
}
