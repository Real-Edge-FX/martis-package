<?php

namespace Martis\Metrics;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Martis\Cache\MartisCache;
use Martis\Contracts\MetricContract;
use Martis\Enums\CardStyle;
use Martis\Enums\MetricType;
use Martis\Enums\MetricWidthPreset;

/**
 * Base class for all Martis metrics.
 *
 * Metrics compute analytical data displayed as cards on dashboards and
 * resource index pages.
 *
 * Martis extensions:
 * - Responsive 12-column grid with widthMd/widthLg breakpoints
 * - Auto-refresh polling via refreshEvery()
 *
 * @phpstan-consistent-constructor
 */
abstract class Metric implements MetricContract
{
    /** @var array<string, mixed> */
    protected array $meta = [];

    protected ?string $component = null;

    /** Grid width in 12-column system (default: 4 = one-third). */
    protected int $width = 4;

    /** Responsive width from md breakpoint. */
    protected ?int $widthMd = null;

    /** Responsive width from lg breakpoint. */
    protected ?int $widthLg = null;

    /** Card height in pixels. Martis extension. */
    protected ?int $height = null;

    /** Visual card style. Martis extension. */
    protected CardStyle $cardStyle = CardStyle::Default;

    /** Phosphor icon name for the card header. Martis extension. */
    protected ?string $icon = null;

    /**
     * Custom chart color (CSS color value or var). Martis extension.
     * Used by Trend and Progress metrics for line/bar/progress fill.
     * Falls back to --martis-accent if null.
     */
    protected ?string $color = null;

    /**
     * Query scope applied by dashboard filters.
     * Set by MetricController before calling resolve().
     *
     * @var Closure(Builder): Builder|null
     */
    protected ?Closure $filterScope = null;

    /** Authorization callback. */
    protected ?Closure $canSeeCallback = null;

    /** Restrict to detail view only. */
    protected bool $onlyOnDetail = false;

    /** Auto-refresh interval in seconds. Martis extension. */
    protected ?int $refreshInterval = null;

    public function __construct(
        protected string $name,
        protected ?string $uriKey = null,
    ) {}

    public static function make(string $name, ?string $uriKey = null): static
    {
        return new static($name, $uriKey);
    }

    /**
     * Calculate the metric value.
     */
    abstract public function calculate(Request $request): mixed;

    /**
     * The metric type identifier.
     */
    abstract public function metricType(): MetricType;

    /** {@inheritDoc} */
    public function name(): string
    {
        return $this->name;
    }

    /** {@inheritDoc} */
    public function uriKey(): string
    {
        return $this->uriKey ?? Str::kebab($this->name);
    }

    /** {@inheritDoc} */
    public function component(): ?string
    {
        return $this->component;
    }

    /**
     * Set a custom frontend component key.
     */
    public function componentKey(string $component): static
    {
        $this->component = $component;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Width — 12-column grid with responsive breakpoints
    // -------------------------------------------------------------------------

    /**
     * Set the card width.
     *
     * Accepts either a 12-column grid value (1-12) or a {@see MetricWidthPreset}
     * case. Fraction strings ('1/3', '1/2', '2/3', 'full') are also accepted
     * and auto-converted via the preset enum.
     */
    public function width(int|string|MetricWidthPreset $width): static
    {
        $this->width = $this->normalizeWidth($width);

        return $this;
    }

    /**
     * Set responsive width from md breakpoint (>= 768px).
     * Martis extension.
     */
    public function widthMd(int $width): static
    {
        $this->widthMd = max(1, min(12, $width));

        return $this;
    }

    /**
     * Set responsive width from lg breakpoint (>= 1024px).
     * Martis extension.
     */
    public function widthLg(int $width): static
    {
        $this->widthLg = max(1, min(12, $width));

        return $this;
    }

    /**
     * Convert fraction width strings or {@see MetricWidthPreset} to a
     * 12-column grid value.
     */
    protected function normalizeWidth(int|string|MetricWidthPreset $width): int
    {
        if ($width instanceof MetricWidthPreset) {
            return $width->toGridCols();
        }

        if (is_int($width)) {
            return max(1, min(12, $width));
        }

        return (MetricWidthPreset::tryFrom($width) ?? MetricWidthPreset::OneThird)->toGridCols();
    }

    // -------------------------------------------------------------------------
    // Ranges
    // -------------------------------------------------------------------------

    /**
     * Get the available date ranges for this metric.
     *
     * @return array<int|string, string>
     */
    public function ranges(): array
    {
        try {
            return [
                30 => __('martis::metrics.30_days'),
                60 => __('martis::metrics.60_days'),
                365 => __('martis::metrics.365_days'),
                'TODAY' => __('martis::metrics.today'),
                'MTD' => __('martis::metrics.month_to_date'),
                'QTD' => __('martis::metrics.quarter_to_date'),
                'YTD' => __('martis::metrics.year_to_date'),
            ];
        } catch (\Throwable) {
            // Fallback when translator is not available (e.g. unit tests)
            return [
                30 => '30 Days',
                60 => '60 Days',
                365 => '365 Days',
                'TODAY' => 'Today',
                'MTD' => 'Month To Date',
                'QTD' => 'Quarter To Date',
                'YTD' => 'Year To Date',
            ];
        }
    }

    // -------------------------------------------------------------------------
    // Caching
    // -------------------------------------------------------------------------

    /**
     * How long to cache the metric result. Return null to disable caching.
     */
    public function cacheFor(): ?DateTimeInterface
    {
        return null;
    }

    /**
     * Resolve the metric, applying caching if configured.
     *
     * @return array<string, mixed>
     */
    public function resolve(Request $request): array
    {
        $cacheFor = $this->cacheFor();

        // Per-class `cacheFor()` short-circuits the global Martis cache —
        // honour it directly and skip the centralized layer so users
        // overriding the method retain full control.
        if ($cacheFor !== null) {
            $range = $request->query('range', '30');
            $filters = $request->query('filters', '');
            $cacheKey = 'martis_metric_'.md5($this->uriKey().'_'.$range.'_'.$filters.'_'.app()->getLocale());

            return Cache::remember($cacheKey, $cacheFor, fn () => $this->resolveResult($request));
        }

        // Fall through to the central MartisCache so the runtime kill-
        // switch ("cache.metrics.enabled = false", `martis:cache:disable
        // metrics`, `?nocache=1`) and the master switch all apply.
        try {
            $cache = app(MartisCache::class);
        } catch (\Throwable) {
            // Outside Laravel (raw unit tests) — fall back to direct compute.
            return $this->resolveResult($request);
        }

        $range = $request->query('range', '30');
        $filters = $request->query('filters', '');
        // Include the current locale so `__()`-derived labels (trend buckets,
        // partition slice names, progress summaries) stay in sync when the
        // user switches language — otherwise a cached payload keeps serving
        // the previous locale until the TTL expires.
        $key = md5($this->uriKey().'_'.$range.'_'.$filters.'_'.app()->getLocale());

        return $cache->remember('metrics', $key, fn () => $this->resolveResult($request));
    }

    /**
     * Calculate and serialize the result.
     *
     * @return array<string, mixed>
     */
    protected function resolveResult(Request $request): array
    {
        $result = $this->calculate($request);

        if ($result instanceof MetricResult) {
            return $result->toArray();
        }

        if (is_array($result)) {
            return $result;
        }

        return ['value' => $result];
    }

    // -------------------------------------------------------------------------
    // Dashboard filter integration
    // -------------------------------------------------------------------------

    /**
     * Set a query scope that dashboard filters will apply to all aggregate queries.
     * Called by MetricController before resolve().
     *
     * @param  Closure(\Illuminate\Database\Eloquent\Builder): \Illuminate\Database\Eloquent\Builder  $scope
     */
    public function withFilterScope(Closure $scope): static
    {
        $this->filterScope = $scope;

        return $this;
    }

    /**
     * Apply the dashboard filter scope to a query builder.
     * Called by aggregate helpers (count, sum, etc.) in subclasses.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Model>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Model>
     */
    protected function applyFilterScope(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        if ($this->filterScope !== null) {
            $query = call_user_func($this->filterScope, $query);
        }

        return $query;
    }

    // -------------------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function canSee(Closure $callback): static
    {
        $this->canSeeCallback = $callback;

        return $this;
    }

    /** {@inheritDoc} */
    public function authorizedToSee(Request $request): bool
    {
        if ($this->canSeeCallback === null) {
            return true;
        }

        return (bool) call_user_func($this->canSeeCallback, $request);
    }

    // -------------------------------------------------------------------------
    // Display options
    // -------------------------------------------------------------------------

    /**
     * Only show this metric on the resource detail page.
     */
    public function onlyOnDetail(): static
    {
        $this->onlyOnDetail = true;

        return $this;
    }

    /**
     * Set the card height in pixels. Martis extension.
     *
     * Controls the minimum height of the card content area.
     * Useful for aligning cards in a row to the same height.
     */
    public function height(int $pixels): static
    {
        $this->height = max(50, $pixels);

        return $this;
    }

    /**
     * Set the visual card style. Martis extension.
     *
     * Applies a colored accent to the card (left border + header tint).
     */
    public function style(CardStyle $style): static
    {
        $this->cardStyle = $style;

        return $this;
    }

    /**
     * Set a Phosphor icon for the card header. Martis extension.
     *
     * @param  string  $icon  Phosphor icon name (e.g. 'users', 'currency-dollar', 'chart-line')
     */
    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Set auto-refresh interval in seconds. Martis extension.
     */
    public function refreshEvery(int $seconds): static
    {
        $this->refreshInterval = max(5, $seconds);

        return $this;
    }

    /**
     * Set the chart color for this metric. Martis extension.
     *
     * Accepts any CSS color value (hex, rgb, rgba, var(--name), or named color).
     * Used by:
     * - TrendMetric: line/area color
     * - ProgressMetric: progress bar fill
     * - ValueMetric: change indicator color (optional)
     *
     * @param  string  $color  CSS color value
     */
    public function color(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Metadata
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): static
    {
        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    // -------------------------------------------------------------------------
    // Serialization
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'metric',
            'metricType' => $this->metricType()->value,
            'name' => $this->name(),
            'uriKey' => $this->uriKey(),
            'component' => $this->component(),
            'width' => $this->width,
            'widthMd' => $this->widthMd,
            'widthLg' => $this->widthLg,
            'ranges' => $this->ranges(),
            'refreshEvery' => $this->refreshInterval,
            'onlyOnDetail' => $this->onlyOnDetail,
            'height' => $this->height,
            'style' => $this->cardStyle->value,
            'icon' => $this->icon,
            'color' => $this->color,
            'meta' => $this->meta(),
        ];
    }
}
