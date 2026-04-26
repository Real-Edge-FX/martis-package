<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Martis\Enums\ChartType;

/**
 * Sparkline field — inline mini chart for trend visualization.
 *
 * Display-only field (not editable). Shows a small line or bar chart.
 *
 * API:
 *   - data($data)     — array of numbers, callable, or Trend metric
 *   - asBarChart()    — render as bar chart (default: line)
 *   - height($px)     — chart height in pixels
 *   - width($px)      — chart width in pixels
 *
 * Contexts: index (yes), detail (yes), create/update (no — display-only).
 */
class Sparkline extends Field
{
    /** @var list<int|float>|callable|null */
    protected mixed $chartData = null;

    // Chart type: line or bar
    protected ChartType $chartType = ChartType::Line;

    protected int $chartHeight = 30;

    protected ?int $chartWidth = null;

    /** @var string Color for the sparkline */
    protected string $chartColor = '#6366f1';

    /**
     * Type.
     */
    public function type(): string
    {
        return 'sparkline';
    }

    /**
     * Override make() to default to display-only (hidden from forms).
     * Sparkline is not an input — it is a read-only visualization.
     */
    public static function make(string $attribute, ?string $label = null): static
    {
        return parent::make($attribute, $label)->hideFromForms();
    }

    /**
     * Set the chart data.
     *
     * @param  list<int|float>|callable  $data
     */
    public function data(mixed $data): static
    {
        $this->chartData = $data;

        return $this;
    }

    /**
     * Render as a bar chart.
     */
    public function asBarChart(): static
    {
        $this->chartType = ChartType::Bar;

        return $this;
    }

    /**
     * Render as a line chart (default).
     */
    public function asLineChart(): static
    {
        $this->chartType = ChartType::Line;

        return $this;
    }

    /**
     * Set chart height in pixels.
     */
    public function height(int $px): static
    {
        $this->chartHeight = $px;

        return $this;
    }

    /**
     * Set chart width in pixels. Not to be confused with the inherited
     * `Field::width(string)` that controls the CSS column width; this
     * sets the SVG canvas size of the sparkline itself.
     */
    public function chartWidth(int $px): static
    {
        $this->chartWidth = $px;

        return $this;
    }

    /**
     * Set the chart line/bar color.
     */
    public function color(string $color): static
    {
        $this->chartColor = $color;

        return $this;
    }

    /**
     * Get chart type.
     */
    public function getChartType(): ChartType
    {
        return $this->chartType;
    }

    /**
     * Get chart height.
     */
    public function getChartHeight(): int
    {
        return $this->chartHeight;
    }

    /**
     * Get chart width.
     */
    public function getChartWidth(): ?int
    {
        return $this->chartWidth;
    }

    /**
     * Get chart color.
     */
    public function getChartColor(): string
    {
        return $this->chartColor;
    }

    /**
     * Resolve the chart data.
     * If data is a callable, invoke it with the model.
     * If data is a static array, return it.
     * Falls back to model attribute value (expects JSON array or comma-separated).
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        if ($this->resolveCallback !== null) {
            return ($this->resolveCallback)($model->getAttribute($attribute ?? $this->attribute), $model, $attribute ?? $this->attribute, $this->safeRequest());
        }

        if ($this->chartData !== null) {
            if (is_callable($this->chartData)) {
                $result = ($this->chartData)($model);

                return is_array($result) ? $result : [];
            }

            return $this->chartData;
        }

        // Fall back to model attribute
        $raw = $model->getAttribute($attribute ?? $this->attribute);

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Fill the model with sparkline data.
     * Accepts JSON array of numbers.
     */
    public function fill(Model $model, mixed $value): void
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        $model->setAttribute($this->attribute, $value);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_filter([
            'chartType' => $this->chartType->value,
            'chartHeight' => $this->chartHeight,
            'chartWidth' => $this->chartWidth,
            'chartColor' => $this->chartColor,
        ], fn (mixed $v): bool => $v !== null);
    }
}
