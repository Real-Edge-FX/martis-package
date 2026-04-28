<?php

namespace Martis\Contracts;

use Closure;
use Illuminate\Http\Request;
use Martis\Lenses\Lens;

/**
 * Contract for Martis lenses.
 *
 * A lens represents an alternative query / view of a Resource's dataset
 * (e.g. "Most Valuable Clients"). The full engine lives in
 * {@see Lens}; this contract enumerates the minimum API
 * shared by all lens implementations — concrete lenses extend the
 * abstract base class.
 */
interface LensContract
{
    /**
     * Return the human-readable label shown in the lens dropdown and
     * as the page heading when the lens is selected.
     */
    public function name(): string;

    /**
     * Return the stable URL segment used to address this lens. Always
     * kebab-cased, no leading slash. The router prepends
     * `/resources/{resource}/lens/` when building paths.
     */
    public function uriKey(): string;

    /**
     * Return the registered component key the frontend resolves to
     * render this lens, or `null` to fall back to the bundled lens
     * table.
     */
    public function component(): ?string;

    /**
     * Register the visibility callback used by `authorizedToSee()`.
     *
     * @param  Closure(Request): bool  $callback
     */
    public function canSee(Closure $callback): static;

    /**
     * Whether the current user may view this lens. Lenses filtered out
     * here are stripped from the schema response and direct GETs
     * respond with 403.
     */
    public function authorizedToSee(Request $request): bool;

    /**
     * Free-form payload forwarded to the lens frontend component.
     * Use it for layout hints the schema surface does not standardise.
     *
     * @return array<string, mixed>
     */
    public function meta(): array;

    /**
     * Serialize the lens into the schema envelope.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
