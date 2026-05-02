<?php

namespace Martis\Menu;

use Closure;
use Illuminate\Http\Request;

/**
 * Mid-level navigation cluster: nests items inside a `MenuSection` to
 * give dense sidebars a third level of structure.
 *
 *   MenuSection
 *     └── MenuGroup ← this class (collapsible, can carry icon/path)
 *           └── MenuItem
 *
 * A MenuSection without a MenuGroup still works: items render directly
 * under the section. Reach for MenuGroup when a section gets too long
 * (Settings → Auth → Users / Roles / Permissions, for example) and a
 * flat list of items becomes hard to scan.
 *
 * MenuGroup serialises with `"type": "group"` so the frontend can pick
 * the right renderer; everything else mirrors MenuSection (icon,
 * collapsable, withMeta) plus a `path()` deep-link.
 */
class MenuGroup
{
    /** @var list<MenuItem> */
    protected array $items = [];

    /** @var Closure(Request): bool|bool|null */
    protected Closure|bool|null $visibleUsing = null;

    /** @var array<string, mixed> */
    protected array $meta = [];

    public function __construct(
        protected string $label,
        array $items = [],
        protected ?string $icon = null,
        protected bool $collapsable = true,
        protected ?string $path = null,
    ) {
        $this->items = $this->normalizeItems($items);
    }

    /**
     * @param  list<MenuItem|class-string<\Martis\Resource>>  $items
     */
    public static function make(string $label, array $items = []): self
    {
        return new self($label, $items);
    }

    /**
     * @param  list<MenuItem|class-string<\Martis\Resource>>  $items
     */
    public function items(array $items): self
    {
        $this->items = $this->normalizeItems($items);

        return $this;
    }

    public function add(MenuItem|string $item): self
    {
        $this->items[] = $this->normalizeItem($item);

        return $this;
    }

    public function icon(?string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function collapsable(bool $collapsable = true): self
    {
        $this->collapsable = $collapsable;

        return $this;
    }

    /**
     * Make the group label clickable. The frontend wraps the header in
     * a link to this URL, useful when the cluster has a dedicated
     * landing page (e.g. "/settings" for the Settings group).
     */
    public function path(?string $url): self
    {
        $this->path = $url;

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
     * @return array<string, mixed>|null
     */
    public function resolve(Request $request): ?array
    {
        if (! $this->isVisible($request)) {
            return null;
        }

        $resolvedItems = [];
        foreach ($this->items as $item) {
            $resolved = $item->resolve($request);
            if ($resolved !== null) {
                $resolvedItems[] = $resolved;
            }
        }

        if ($resolvedItems === []) {
            return null;
        }

        return array_merge([
            'type' => 'group',
            'label' => $this->label,
            'icon' => $this->icon,
            'collapsable' => $this->collapsable,
            'path' => $this->path,
            'items' => array_values($resolvedItems),
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
     * @param  list<MenuItem|class-string<\Martis\Resource>>  $items
     * @return list<MenuItem>
     */
    protected function normalizeItems(array $items): array
    {
        return array_values(array_map(
            fn (MenuItem|string $item): MenuItem => $this->normalizeItem($item),
            $items
        ));
    }

    protected function normalizeItem(MenuItem|string $item): MenuItem
    {
        if ($item instanceof MenuItem) {
            return $item;
        }

        return MenuItem::resource($item);
    }
}
