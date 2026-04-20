<?php

namespace Martis\Cards;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Contracts\CardContract;

/**
 * Minimal card descriptor used by the task-1 schema foundation.
 */
class Card implements CardContract
{
    /** Authorization callback. */
    protected ?Closure $canSeeCallback = null;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        protected string $name,
        protected ?string $uriKey = null,
        protected ?string $component = null,
        protected array $meta = [],
        protected int $width = 4,
        protected bool $framed = false,
    ) {}

    /**
     * Register a closure that decides whether the card is visible.
     *
     * Example: `$card->canSee(fn ($request) => $request->user()?->is_admin)`.
     */
    public function canSee(Closure $callback): static
    {
        $this->canSeeCallback = $callback;

        return $this;
    }

    /**
     * Resolve the canSee callback; defaults to true when absent.
     */
    public function authorizedToSee(Request $request): bool
    {
        if ($this->canSeeCallback === null) {
            return true;
        }

        return (bool) call_user_func($this->canSeeCallback, $request);
    }

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

    /**
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): static
    {
        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

    public function componentKey(string $component): static
    {
        $this->component = $component;

        return $this;
    }

    /**
     * Grid column span in the 12-column dashboard grid (1-12).
     */
    public function width(int $span): static
    {
        $this->width = max(1, min(12, $span));

        return $this;
    }

    /**
     * Wrap the custom component inside the Martis MetricCard chrome
     * (border, header with title/icon, padded body). Off by default
     * so that hero-style cards can render full-bleed.
     */
    public function framed(bool $framed = true): static
    {
        $this->framed = $framed;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    public function toArray(): array
    {
        return [
            'type' => 'card',
            'name' => $this->name(),
            'uriKey' => $this->uriKey(),
            'component' => $this->component(),
            'width' => $this->width,
            'framed' => $this->framed,
            'meta' => $this->meta(),
        ];
    }
}
