<?php

namespace Martis\Fields;

use Closure;
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
 *
 * When the field is shown on a form, a written series is validated: a list
 * of at most `maxPoints($n)` numbers (1000 unless raised). An unbounded
 * series would be stored as sent and then drawn by every page that renders
 * the record.
 */
class Sparkline extends Field
{
    /** The most numbers a written series may hold unless `maxPoints()` says otherwise. */
    public const DEFAULT_MAX_POINTS = 1000;

    /** @var list<int|float>|callable|null */
    protected mixed $chartData = null;

    // Chart type: line or bar
    protected ChartType $chartType = ChartType::Line;

    protected int $chartHeight = 30;

    protected ?int $chartWidth = null;

    /** @var string Color for the sparkline */
    protected string $chartColor = '#6366f1';

    protected int $maxPoints = self::DEFAULT_MAX_POINTS;

    /** {@inheritdoc} */
    public function type(): string
    {
        return 'sparkline';
    }

    /** {@inheritdoc} */
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
     * Set the most numbers a written series may hold (default 1000). A
     * longer series fails validation when the field is written.
     */
    public function maxPoints(int $max): static
    {
        $this->maxPoints = max(1, $max);

        return $this;
    }

    /**
     * Get the most numbers a written series may hold.
     */
    public function getMaxPoints(): int
    {
        return $this->maxPoints;
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

    /** {@inheritdoc} */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $attr = $attribute ?? $this->attribute;

        if ($this->resolveCallback !== null) {
            return ($this->resolveCallback)($this->resolveAttribute($model, $attr), $model, $attr, $this->safeRequest());
        }

        if ($this->chartData !== null) {
            if (is_callable($this->chartData)) {
                $result = ($this->chartData)($model);

                return is_array($result) ? $result : [];
            }

            return $this->chartData;
        }

        // Fall back to the model attribute (or the computed value)
        $raw = $this->resolveAttribute($model, $attr);

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

    /** {@inheritdoc} */
    public function hasStructuredValue(): bool
    {
        return true;
    }

    /** {@inheritdoc} */
    public function fill(Model $model, mixed $value): void
    {
        // A computed field has no backing attribute to write (see Field::fill()).
        if ($this->computed) {
            return;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        $model->setAttribute($this->attribute, $value);
    }

    /**
     * {@inheritdoc}
     *
     * A written series is a list of at most `maxPoints()` numbers. `fill()`
     * stores whatever array it is given, and the chart draws every point of
     * what is stored, so an unbounded or non-numeric series would fail the
     * pages that render the record for everyone who opens it. A field the
     * package does not fill (readonly, computed, `fillUsing()`) keeps its own
     * rules.
     */
    public function buildRules(?string $context = null): array
    {
        $rules = parent::buildRules($context);

        if ($this->fillCallback !== null || $this->computed || $this->isReadonly()) {
            return $rules;
        }

        $rules[] = 'array';
        $rules[] = 'max:'.$this->maxPoints;
        $rules[] = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value) || $this->isListOfNumbers($value)) {
                return;
            }

            $label = $this->label();

            $fail(self::translate(
                'martis::messages.sparkline_numbers',
                ['attribute' => $label],
                "The {$label} must be a list of numbers.",
            ));
        };

        return $rules;
    }

    /**
     * Whether `$value` is a list whose entries are all finite numbers.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function isListOfNumbers(array $value): bool
    {
        if (! array_is_list($value)) {
            return false;
        }

        foreach ($value as $point) {
            if (! is_int($point) && ! (is_float($point) && is_finite($point))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a translation with a hard-coded English fallback when the
     * translator binding is unavailable (unit tests outside the container).
     *
     * @param  array<string, string>  $replace
     */
    private static function translate(string $key, array $replace, string $fallback): string
    {
        try {
            $translated = trans($key, $replace);
        } catch (\Throwable) {
            return $fallback;
        }
        if (! is_string($translated) || $translated === $key) {
            return $fallback;
        }

        return $translated;
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
