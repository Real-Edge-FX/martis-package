<?php

namespace Martis\Contracts;

/**
 * Contract for resource filter descriptors.
 *
 * Task 1 foundation only: this contract defines the minimum schema surface
 * needed for resources to advertise filters before the full filters engine
 * is implemented.
 */
interface FilterContract
{
    /**
     * Create a new filter descriptor.
     */
    public static function make(string $name, ?string $uriKey = null): static;

    /**
     * Human-readable filter name.
     */
    public function name(): string;

    /**
     * Stable URI key used in schema payloads and future requests.
     */
    public function uriKey(): string;

    /**
     * Optional frontend component key for custom rendering.
     */
    public function component(): ?string;

    /**
     * Extra metadata forwarded to the frontend.
     *
     * @return array<string, mixed>
     */
    public function meta(): array;

    /**
     * Serialize the descriptor for the schema endpoint.
     *
     * @return array{type: string, name: string, uriKey: string, component: string|null, meta: array<string, mixed>}
     */
    public function toArray(): array;
}
