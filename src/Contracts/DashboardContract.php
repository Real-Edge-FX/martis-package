<?php

namespace Martis\Contracts;

/**
 * Contract for dashboard descriptors.
 *
 * Task 1 foundation only: this contract defines the minimum schema surface
 * needed for resources to advertise dashboard-level metadata before the full
 * dashboards system is implemented.
 */
interface DashboardContract
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
