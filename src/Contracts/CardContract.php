<?php

namespace Martis\Contracts;

/**
 * Contract for card descriptors.
 *
 * Task 1 foundation only: this contract defines the minimum schema surface
 * needed for resources to advertise cards before the full cards / metrics
 * system is implemented.
 */
interface CardContract
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
