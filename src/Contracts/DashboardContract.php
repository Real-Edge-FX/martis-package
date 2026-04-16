<?php

namespace Martis\Contracts;

use Closure;
use Illuminate\Http\Request;

/**
 * Contract for Martis dashboards.
 *
 * Dashboards are containers for metrics and custom cards,
 * with optional filters and authorization.
 */
interface DashboardContract
{
    public static function make(string $name, ?string $uriKey = null): static;

    public function name(): string;

    public function uriKey(): string;

    public function component(): ?string;

    /**
     * Get the cards (metrics) for this dashboard.
     *
     * @return list<MetricContract|array<string, mixed>>
     */
    public function cards(Request $request): array;

    /**
     * Get the filters for this dashboard. Martis extension.
     *
     * @return list<FilterContract|array<string, mixed>>
     */
    public function filters(Request $request): array;

    /**
     * Whether to show a manual refresh button.
     */
    public function showRefreshButton(): bool;

    public function canSee(Closure $callback): static;

    public function authorizedToSee(Request $request): bool;

    /**
     * @return array<string, mixed>
     */
    public function meta(): array;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
