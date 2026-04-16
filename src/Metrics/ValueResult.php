<?php

namespace Martis\Metrics;

/**
 * Result for a Value metric — single number with optional comparison.
 */
class ValueResult extends MetricResult
{
    protected float|int $value;

    protected float|int|null $previous = null;

    protected ?string $prefix = null;

    protected ?string $suffix = null;

    protected ?string $format = null;

    protected bool $allowZeroResult = false;

    public function __construct(float|int $value)
    {
        $this->value = $value;
    }

    /**
     * Set the previous period value for comparison.
     */
    public function previous(float|int|null $value): static
    {
        $this->previous = $value;

        return $this;
    }

    /**
     * Set a prefix (e.g. '$').
     */
    public function prefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    /**
     * Set a suffix (e.g. ' users').
     */
    public function suffix(string $suffix): static
    {
        $this->suffix = $suffix;

        return $this;
    }

    /**
     * Shortcut for currency prefix.
     */
    public function currency(string $symbol = '$'): static
    {
        $this->prefix = $symbol;

        return $this;
    }

    /**
     * Shortcut for euro currency.
     */
    public function dollars(): static
    {
        return $this->currency('$');
    }

    /**
     * Shortcut for euro currency.
     */
    public function euros(): static
    {
        return $this->currency('€');
    }

    /**
     * Set a custom format string.
     */
    public function format(string $format): static
    {
        $this->format = $format;

        return $this;
    }

    /**
     * Display 0 instead of hiding.
     */
    public function allowZeroResult(): static
    {
        $this->allowZeroResult = true;

        return $this;
    }

    public function toArray(): array
    {
        $data = [
            'value' => $this->value,
        ];

        if ($this->previous !== null) {
            $data['previous'] = $this->previous;

            if ($this->previous > 0) {
                $data['change'] = round((($this->value - $this->previous) / $this->previous) * 100, 1);
            }
        }

        if ($this->prefix !== null) {
            $data['prefix'] = $this->prefix;
        }
        if ($this->suffix !== null) {
            $data['suffix'] = $this->suffix;
        }
        if ($this->format !== null) {
            $data['format'] = $this->format;
        }

        return $data;
    }
}
