<?php

namespace Martis\Contracts;

/**
 * Contract for resource lens descriptors.
 *
 * Task 1 foundation only: this contract defines the minimum schema surface
 * needed for resources to advertise lenses before the full lenses engine
 * is implemented.
 */
interface LensContract
{
    public static function make(string $name, ?string $uriKey = null): static;

    public function name(): string;

    public function uriKey(): string;

    public function component(): ?string;

    /**
     * @return array<string, mixed>
     */
    public function meta(): array;

    /**
     * @return array{type: string, name: string, uriKey: string, component: string|null, meta: array<string, mixed>}
     */
    public function toArray(): array;
}
