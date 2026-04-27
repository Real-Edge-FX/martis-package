<?php

namespace Martis\Menu;

use Closure;
use Illuminate\Http\Request;
use Martis\Contracts\ToolContract;
use Martis\Enums\MenuItemType;
use Martis\Resource;

class MenuItem
{
    /** @var Closure(Request): bool|bool|null */
    protected Closure|bool|null $visibleUsing = null;

    /** @var array<string, mixed> */
    protected array $meta = [];

    /** @var class-string<ToolContract>|ToolContract|null */
    protected string|ToolContract|null $tool = null;

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
     * @param  class-string<Resource>  $resourceClass
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
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): self
    {
        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

    /**
     * @return class-string<Resource>|null
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

        if ($this->tool !== null) {
            return $this->resolveToolItem($request);
        }

        if ($this->resourceClass !== null) {
            return $this->resolveResourceItem($request);
        }

        if ($this->label === null || $this->url === null) {
            return null;
        }

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

        if (! $resourceClass::displayInNavigation()) {
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
     * @param  class-string<Resource>  $resourceClass
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
