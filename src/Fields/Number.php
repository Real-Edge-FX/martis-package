<?php

namespace Martis\Fields;

/**
 * Numeric input field.
 *
 * Renders as `<input type="number">` in the React frontend.
 * Use for integers, decimals, prices, quantities, etc.
 */
class Number extends Field
{
    protected int|float|null $min = null;

    protected int|float|null $max = null;

    protected int|float|null $step = null;

    /** {@inheritdoc} */
    public function type(): string
    {
        return 'number';
    }

    /**
     * Set the minimum allowed value.
     *
     * Replaces any previously registered `min:` rule so repeated calls
     * (including inside a `dependsOn` closure) do not accumulate stale entries.
     */
    public function min(int|float $min): static
    {
        $this->min = $min;
        $this->extraRules = array_values(
            array_filter($this->extraRules, fn ($r) => ! (is_string($r) && str_starts_with($r, 'min:')))
        );
        $this->extraRules[] = "min:{$min}";

        return $this;
    }

    /**
     * Set the maximum allowed value.
     *
     * Replaces any previously registered `max:` rule so repeated calls
     * (including inside a `dependsOn` closure) do not accumulate stale entries.
     */
    public function max(int|float $max): static
    {
        $this->max = $max;
        $this->extraRules = array_values(
            array_filter($this->extraRules, fn ($r) => ! (is_string($r) && str_starts_with($r, 'max:')))
        );
        $this->extraRules[] = "max:{$max}";

        return $this;
    }

    /**
     * Set the stepping interval for the input.
     */
    public function step(int|float $step): static
    {
        $this->step = $step;

        return $this;
    }

    /**
     * Enforce an integer value (adds Laravel `integer` rule).
     */
    public function integer(): static
    {
        $this->extraRules[] = 'integer';

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_filter([
            'min' => $this->min,
            'max' => $this->max,
            'step' => $this->step,
        ], fn (mixed $v): bool => $v !== null);
    }
}
