<?php

namespace Martis\Menu;

use Closure;
use Illuminate\Http\Request;
use Martis\Contracts\ToolContract;
use Martis\Dashboards\Dashboard;
use Martis\Enums\MenuItemType;
use Martis\Filters\Filter;
use Martis\Lenses\Lens;
use Martis\Resource;

class MenuItem
{
    /** @var Closure(Request): bool|bool|null */
    protected Closure|bool|null $visibleUsing = null;

    /** @var array<string, mixed> */
    protected array $meta = [];

    /** @var class-string<ToolContract>|ToolContract|null */
    protected string|ToolContract|null $tool = null;

    /** @var class-string<Dashboard>|Dashboard|null */
    protected string|Dashboard|null $dashboard = null;

    /** @var class-string<Lens>|null */
    protected ?string $lensClass = null;

    /** @var class-string<Filter>|null */
    protected ?string $filterClass = null;

    protected mixed $filterValue = null;

    /** @var array<string, mixed>|null */
    protected ?array $badge = null;

    protected function __construct(
        protected MenuItemType $type,
        protected ?string $label = null,
        protected ?string $url = null,
        protected ?string $icon = null,
        protected bool $external = false,
        protected ?string $resourceClass = null,
    ) {}

    public static function make(string $label, string $url): self
    {
        return static::link($label, $url);
    }

    public static function link(string $label, string $url): self
    {
        return new self(MenuItemType::Link, $label, $url);
    }

    public static function externalLink(string $label, string $url): self
    {
        return new self(MenuItemType::Link, $label, $url, external: true);
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    public static function resource(string $resourceClass): self
    {
        return new self(MenuItemType::Resource, resourceClass: $resourceClass);
    }

    /**
     * Build a menu item from a registered Tool. Pass either a class-string
     * or an instance — the menu reads `name()`, `uriKey()`, `icon()` and
     * `authorizedToSee()` lazily at request time so the rendered menu
     * reflects the live state of the tool.
     *
     * @param  class-string<ToolContract>|ToolContract  $tool
     */
    public static function tool(string|ToolContract $tool): self
    {
        $item = new self(MenuItemType::Tool);
        $item->tool = $tool;

        return $item;
    }

    /**
     * Build a menu item from a Dashboard class. The dashboard's `name()`,
     * `uriKey()`, `icon()` and `authorizedToSee()` are resolved lazily at
     * request time, mirroring the behaviour of `MenuItem::tool()`.
     *
     * @param  class-string<Dashboard>|Dashboard  $dashboard
     */
    public static function dashboard(string|Dashboard $dashboard): self
    {
        $item = new self(MenuItemType::Dashboard);
        $item->dashboard = $dashboard;

        return $item;
    }

    /**
     * Build a menu item that points to a Lens registered on a Resource.
     * Resolves to `/resources/{resourceUriKey}/lens/{lensUriKey}` and is
     * dropped from the menu when either the Resource's `authorizedToViewAny`
     * or the Lens' `authorizedToSee()` denies the current user.
     *
     * @param  class-string<resource>  $resourceClass
     * @param  class-string<Lens>  $lensClass
     */
    public static function lens(string $resourceClass, string $lensClass): self
    {
        $item = new self(MenuItemType::Lens, resourceClass: $resourceClass);
        $item->lensClass = $lensClass;

        return $item;
    }

    /**
     * Build a menu item that links to a Resource index pre-filtered with
     * a single filter set to a fixed value. Pair the factory with
     * `->applies($filterClass, $value)` to specify the filter:
     *
     *   MenuItem::filter('Active Tickets', TicketResource::class)
     *       ->applies(StatusFilter::class, 'open');
     *
     * The URL serialises to `/resources/{uriKey}?filters={"<filterUriKey>":<value>}`.
     *
     * @param  class-string<resource>  $resourceClass
     */
    public static function filter(string $label, string $resourceClass): self
    {
        $item = new self(MenuItemType::Filter, $label, resourceClass: $resourceClass);

        return $item;
    }

    /**
     * Pin the filter and value applied by a {@see static::filter()} item.
     * Has no effect on items built through any other factory.
     *
     * @param  class-string<Filter>  $filterClass
     */
    public function applies(string $filterClass, mixed $value): self
    {
        $this->filterClass = $filterClass;
        $this->filterValue = $value;

        return $this;
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function icon(?string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function path(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function external(bool $external = true): self
    {
        $this->external = $external;

        return $this;
    }

    /**
     * @param  Closure(Request): bool|bool  $callback
     */
    public function canSee(Closure|bool $callback): self
    {
        $this->visibleUsing = $callback;

        return $this;
    }

    /**
     * Attach a textual badge (e.g. "New", "Beta", "Pro") next to the item
     * label. Distinct from the count badge surfaced by Resource items —
     * this one is purely decorative and consumer-controlled.
     *
     * Tones map to the same semantic palette used by the Badge field:
     * `neutral`, `info`, `success`, `warning`, `danger`, `accent`.
     */
    public function withBadge(string $text, string $tone = 'neutral'): self
    {
        $this->badge = ['text' => $text, 'tone' => $tone];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): self
    {
        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

    /**
     * @return class-string<resource>|null
     */
    public function resourceClass(): ?string
    {
        return $this->resourceClass;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(Request $request): ?array
    {
        if (! $this->isVisible($request)) {
            return null;
        }

        $resolved = match (true) {
            $this->tool !== null => $this->resolveToolItem($request),
            $this->dashboard !== null => $this->resolveDashboardItem($request),
            $this->lensClass !== null => $this->resolveLensItem($request),
            $this->filterClass !== null && $this->resourceClass !== null => $this->resolveFilterItem($request),
            $this->resourceClass !== null => $this->resolveResourceItem($request),
            $this->label !== null && $this->url !== null => $this->resolveLinkItem(),
            default => null,
        };

        if ($resolved === null) {
            return null;
        }

        if ($this->badge !== null) {
            $resolved['badge'] = $this->badge;
        }

        // v1.11.0+ — propagate the soft-gate lock from the underlying
        // entity into the resolved menu item. The auto-build path
        // already gets this via the entity's `toArray()`, but the
        // mainMenu custom resolver path produces a thinner shape that
        // does not call `toArray()` for tools/dashboards/lenses, so
        // we ask the entity directly here.
        $lock = $this->resolveEntityLock($request);
        if ($lock !== null) {
            $resolved['lock'] = $lock;
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveEntityLock(Request $request): ?array
    {
        $entity = match (true) {
            $this->tool !== null => is_string($this->tool) ? new $this->tool : $this->tool,
            $this->dashboard !== null => is_string($this->dashboard) ? new $this->dashboard : $this->dashboard,
            default => null,
        };

        if ($entity !== null && method_exists($entity, 'lockPayloadFor')) {
            return $entity->lockPayloadFor($request);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveLinkItem(): array
    {
        return array_merge([
            'type' => $this->type->value,
            'label' => $this->label,
            'url' => $this->url,
            'icon' => $this->icon,
            'external' => $this->external,
        ], $this->meta);
    }

    /**
     * Resolve a Tool-backed menu entry. Tools that deny `authorizedToSee()`
     * for the current user are silently dropped from the menu.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveToolItem(Request $request): ?array
    {
        $tool = is_string($this->tool) ? new $this->tool : $this->tool;

        if (! $tool instanceof ToolContract || ! $tool->authorizedToSee($request)) {
            return null;
        }

        return array_merge([
            'type' => MenuItemType::Tool->value,
            'label' => $this->label ?? $tool->name(),
            'url' => $this->url ?? '/tools/'.$tool->uriKey(),
            'icon' => $this->icon ?? $tool->icon(),
            'external' => $this->external,
            'uriKey' => $tool->uriKey(),
            'component' => $tool->component(),
        ], $this->meta);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveDashboardItem(Request $request): ?array
    {
        $dashboard = is_string($this->dashboard) ? new $this->dashboard : $this->dashboard;

        if (! $dashboard instanceof Dashboard || ! $dashboard->authorizedToSee($request)) {
            return null;
        }

        return array_merge([
            'type' => MenuItemType::Dashboard->value,
            'label' => $this->label ?? $dashboard->name(),
            'url' => $this->url ?? '/dashboards/'.$dashboard->uriKey(),
            // Prefer the MenuItem-level icon override; fall back to the
            // dashboard's own icon() (set via withIcon()). The instanceof
            // Dashboard guard above makes the call safe even though
            // DashboardContract does not declare icon().
            'icon' => $this->icon ?? $dashboard->icon(),
            'external' => $this->external,
            'uriKey' => $dashboard->uriKey(),
        ], $this->meta);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveLensItem(Request $request): ?array
    {
        $resourceClass = $this->resourceClass;
        $lensClass = $this->lensClass;

        if ($resourceClass === null || $lensClass === null) {
            return null;
        }
        if (! is_subclass_of($resourceClass, Resource::class)) {
            return null;
        }
        if (! is_subclass_of($lensClass, Lens::class)) {
            return null;
        }

        $resource = new $resourceClass;
        if (! $resource->authorizedToViewAny($request)) {
            return null;
        }

        $lens = new $lensClass;
        if (! $lens->authorizedToSee($request)) {
            return null;
        }

        return array_merge([
            'type' => MenuItemType::Lens->value,
            'label' => $this->label ?? $lens->name(),
            'url' => $this->url ?? '/resources/'.$resourceClass::uriKey().'/lens/'.$lens->uriKey(),
            'icon' => $this->icon,
            'external' => $this->external,
            'uriKey' => $lens->uriKey(),
            'resourceUriKey' => $resourceClass::uriKey(),
        ], $this->meta);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveFilterItem(Request $request): ?array
    {
        $resourceClass = $this->resourceClass;
        $filterClass = $this->filterClass;

        if ($resourceClass === null || $filterClass === null) {
            return null;
        }
        if (! is_subclass_of($resourceClass, Resource::class)) {
            return null;
        }

        $resource = new $resourceClass;
        if (! $resource->authorizedToViewAny($request)) {
            return null;
        }

        // Filter::__construct requires a `$name` (used as the human label
        // in the index toolbar). When the menu item only needs the
        // filter's URI key — which subclasses should override anyway —
        // we instantiate with an empty name as a placeholder.
        $filter = new $filterClass('');
        if (! method_exists($filter, 'uriKey')) {
            return null;
        }

        $payload = json_encode([$filter->uriKey() => $this->filterValue], JSON_THROW_ON_ERROR);
        $url = '/resources/'.$resourceClass::uriKey().'?filters='.rawurlencode($payload);

        return array_merge([
            'type' => MenuItemType::Filter->value,
            'label' => $this->label,
            'url' => $this->url ?? $url,
            'icon' => $this->icon,
            'external' => $this->external,
            'resourceUriKey' => $resourceClass::uriKey(),
            'filterUriKey' => $filter->uriKey(),
        ], $this->meta);
    }

    protected function isVisible(Request $request): bool
    {
        if ($this->visibleUsing instanceof Closure) {
            return (bool) call_user_func($this->visibleUsing, $request);
        }

        if (is_bool($this->visibleUsing)) {
            return $this->visibleUsing;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveResourceItem(Request $request): ?array
    {
        $resourceClass = $this->resourceClass;

        if ($resourceClass === null || ! is_subclass_of($resourceClass, Resource::class)) {
            return null;
        }

        $resource = new $resourceClass;

        if (! $resourceClass::displayInNavigation() || ! $resourceClass::routable()) {
            return null;
        }

        if (! $resource->authorizedToViewAny($request)) {
            return null;
        }

        /** @var array<string, mixed> $resourceMeta */
        $resourceMeta = $resource->toArray();

        return array_merge($resourceMeta, [
            'type' => MenuItemType::Resource->value,
            'label' => $this->label ?? $resourceMeta['label'],
            'icon' => $this->icon ?? $resourceMeta['icon'],
            'url' => $this->url ?? '/resources/'.$resourceClass::uriKey(),
            'external' => $this->external,
            'count' => $this->resolveMenuCount($resourceClass, $request),
        ], $this->meta);
    }

    /**
     * Resolve the navigation count for a resource, respecting the global
     * kill-switch and the per-resource opt-out. Any exception from user
     * code is swallowed so a broken count never hides the navigation.
     *
     * @param  class-string<resource>  $resourceClass
     */
    protected function resolveMenuCount(string $resourceClass, Request $request): ?int
    {
        if (! (bool) config('martis.navigation.counts.enabled', true)) {
            return null;
        }

        if (! $resourceClass::showMenuCount()) {
            return null;
        }

        try {
            return $resourceClass::menuCount($request);
        } catch (\Throwable) {
            return null;
        }
    }
}
