<?php

namespace Martis\Dashboards;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Contracts\DashboardContract;

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

    /**
     * Optional breadcrumb label override. When set, the React shell shows
     * this label as the deepest crumb instead of `name()`. Defaults to
     * null (the breadcrumb tracks `name()`).
     */
    protected ?string $breadcrumb = null;

    protected ?Closure $canSeeCallback = null;

    public function __construct(
        protected string $name,
        protected ?string $uriKey = null,
    ) {}

    public static function make(string $name, ?string $uriKey = null): static
    {
        return new static($name, $uriKey);
    }

    /** {@inheritdoc} */
    public function name(): string
    {
        return $this->name;
    }

    /** {@inheritdoc} */
    public function uriKey(): string
    {
        return $this->uriKey ?? Str::kebab($this->name);
    }

    /** {@inheritdoc} */
    public function component(): ?string
    {
        return $this->component;
    }

    public function componentKey(string $component): static
    {
        $this->component = $component;

        return $this;
    }

    /**
     * Override-friendly accessor. Subclasses can return a per-request
     * value (e.g. `__('app.dashboards.home.breadcrumb')`) the same way
     * they override `name()`. Return `null` to fall back to `name()`.
     */
    public function breadcrumb(): ?string
    {
        return $this->breadcrumb;
    }

    /**
     * Override the breadcrumb label without changing the page heading,
     * sidebar entry, or `document.title` (those keep reading `name()`).
     * Pass `null` to clear the override and fall back to `name()`.
     */
    public function withBreadcrumb(?string $breadcrumb): static
    {
        $this->breadcrumb = $breadcrumb;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Cards — metrics and custom cards displayed on this dashboard
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function cards(Request $request): array
    {
        return [];
    }

    // -------------------------------------------------------------------------
    // Filters — Martis extension (dashboard-level filters)
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function filters(Request $request): array
    {
        return [];
    }

    // -------------------------------------------------------------------------
    // Display options
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
    public function canSee(Closure $callback): static
    {
        $this->canSeeCallback = $callback;

        return $this;
    }

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
    public function meta(): array
    {
        return $this->meta;
    }

    // -------------------------------------------------------------------------
    // Serialization
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function toArray(): array
    {
        return [
            'type' => 'dashboard',
            'name' => $this->name(),
            'breadcrumb' => $this->breadcrumb(),
            'uriKey' => $this->uriKey(),
            'component' => $this->component(),
            'layout' => $this->layoutType(),
            'showRefreshButton' => $this->showRefreshButton(),
            'meta' => $this->meta(),
        ];
    }
}
