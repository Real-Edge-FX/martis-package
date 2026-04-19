<?php

namespace Martis\Contracts;

use Closure;
use Illuminate\Http\Request;

/**
 * Contract for Martis lenses.
 *
 * A lens represents an alternative query / view of a Resource's dataset
 * (e.g. "Most Valuable Clients"). The full engine lives in
 * {@see \Martis\Lenses\Lens}; this contract enumerates the minimum API
 * shared by all lens implementations — concrete lenses extend the
 * abstract base class.
 */
interface LensContract
{
    public function name(): string;

    public function uriKey(): string;

    public function component(): ?string;

    public function canSee(Closure $callback): static;

    public function authorizedToSee(Request $request): bool;

    /**
     * @return array<string, mixed>
     */
    public function meta(): array;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
