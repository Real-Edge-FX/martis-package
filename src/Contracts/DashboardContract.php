<?php

namespace Martis\Contracts;

use Closure;
use Illuminate\Http\Request;

/**
 * Contract for Martis dashboards.
 *
 * Dashboards are containers for metrics and custom cards, with optional
 * filters and authorisation. Concrete dashboard classes wire their own
 * card list and visibility rules; the schema and dashboard controllers
 * rely on this contract to serialise and authorise them without
 * knowing the concrete subclass.
 */
interface DashboardContract
{
    /**
     * Build a new dashboard descriptor.
     *
     * @param  string  $name  Human-readable label rendered as the
     *                        dashboard title and tab label.
     * @param  string|null  $uriKey  Stable identifier used in URLs.
     *                               Falls back to a kebab-case slug
     *                               derived from `$name`.
     */
    public static function make(string $name, ?string $uriKey = null): static;

    /**
     * Return the dashboard title shown to the end user.
     */
    public function name(): string;

    /**
     * Return the stable URL identifier for this dashboard.
     */
    public function uriKey(): string;

    /**
     * Return the registered component key the frontend resolves to
     * render this dashboard, or `null` to fall back to the bundled
     * grid layout.
     */
    public function component(): ?string;

    /**
     * Return the cards (metrics + custom cards) rendered on this
     * dashboard, in display order.
     *
     * @return list<MetricContract|array<string, mixed>>
     */
    public function cards(Request $request): array;

    /**
     * Return the dashboard-level filters. Filter values are forwarded
     * to every card via the metric scope hook so totals stay coherent.
     * Martis extension.
     *
     * @return list<FilterContract|array<string, mixed>>
     */
    public function filters(Request $request): array;

    /**
     * Whether the dashboard renders a manual refresh button next to
     * its filter row. Useful for dashboards aggregating slow queries
     * where automatic polling is too expensive.
     */
    public function showRefreshButton(): bool;

    /**
     * Register the visibility callback used by `authorizedToSee()`.
     * The closure receives the current `Request` and returns `bool`.
     *
     * @param  Closure(Request): bool  $callback
     */
    public function canSee(Closure $callback): static;

    /**
     * Whether the current user may view this dashboard. Dashboards
     * filtered out here are dropped from the schema response and
     * direct GETs respond with 403.
     */
    public function authorizedToSee(Request $request): bool;

    /**
     * Return the free-form payload forwarded to the dashboard frontend
     * component (`component()`). Use it for layout hints or
     * dashboard-specific configuration the schema does not standardise.
     *
     * @return array<string, mixed>
     */
    public function meta(): array;

    /**
     * Serialize the dashboard into the schema envelope.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
