<?php

namespace Martis\Dashboards;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Contracts\DashboardContract;
use Martis\Contracts\FilterContract;
use Martis\Contracts\MetricContract;
use Martis\Filters\Filter;

/**
 * Base class for Martis dashboards.
 *
 * Supports multiple dashboards with cards, authorization, refresh button.
 *
 * Martis extensions:
 * - Dashboard-level filters that affect all cards
 * - Responsive 12-column grid layout
 *
 * @phpstan-consistent-constructor
 */
class Dashboard implements DashboardContract
{
    /** @var array<string, mixed> */
    protected array $meta = [];

    protected ?string $component = null;

    protected ?Closure $canSeeCallback = null;

    public function __construct(
        protected string $name,
        protected ?string $uriKey = null,
    ) {}

    public static function make(string $name, ?string $uriKey = null): static
    {
        return new static($name, $uriKey);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function uriKey(): string
    {
        return $this->uriKey ?? Str::kebab($this->name);
    }

    public function component(): ?string
    {
        return $this->component;
    }

    public function componentKey(string $component): static
    {
        $this->component = $component;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Cards — metrics and custom cards displayed on this dashboard
    // -------------------------------------------------------------------------

    /**
     * Get the cards (metrics) for this dashboard.
     *
     * @return list<MetricContract|array<string, mixed>>
     */
    public function cards(Request $request): array
    {
        return [];
    }

    // -------------------------------------------------------------------------
    // Filters — Martis extension (dashboard-level filters)
    // -------------------------------------------------------------------------

    /**
     * Get the filters for this dashboard.
     *
     * Martis extension: dashboard-level filters affect all cards on this
     * dashboard.
     *
     * @return list<FilterContract|array<string, mixed>>
     */
    public function filters(Request $request): array
    {
        return [];
    }

    // -------------------------------------------------------------------------
    // Display options
    // -------------------------------------------------------------------------

    /**
     * Whether to show a manual refresh button.
     */
    public function showRefreshButton(): bool
    {
        return false;
    }

    /**
     * Layout type for the dashboard.
     *
     * 'cards' (default) renders the registered metrics grid.
     * 'default' triggers the built-in Martis landing layout
     * (stat summary + resource quick-access cards).
     */
    public function layoutType(): string
    {
        return 'cards';
    }

    // -------------------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------------------

    public function canSee(Closure $callback): static
    {
        $this->canSeeCallback = $callback;

        return $this;
    }

    public function authorizedToSee(Request $request): bool
    {
        if ($this->canSeeCallback === null) {
            return true;
        }

        return (bool) call_user_func($this->canSeeCallback, $request);
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
     * Serialize the dashboard definition for the API.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'dashboard',
            'name' => $this->name(),
            'uriKey' => $this->uriKey(),
            'component' => $this->component(),
            'layout' => $this->layoutType(),
            'showRefreshButton' => $this->showRefreshButton(),
            'meta' => $this->meta(),
        ];
    }
}
