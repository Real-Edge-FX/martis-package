<?php

namespace Martis\Lenses;

use Illuminate\Support\Str;
use Martis\Contracts\LensContract;

/**
 * Minimal lens descriptor used by the task-1 schema foundation.
 */
class Lens implements LensContract
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        protected string $name,
        protected ?string $uriKey = null,
        protected ?string $component = null,
        protected array $meta = [],
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
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    public function toArray(): array
    {
        return [
            'type' => 'lens',
            'name' => $this->name(),
            'uriKey' => $this->uriKey(),
            'component' => $this->component(),
            'meta' => $this->meta(),
        ];
    }
}
