<?php

declare(strict_types=1);

namespace Martis\Contracts;

use Closure;
use Illuminate\Http\Request;

/**
 * Contract for Martis Tools.
 *
 * A Tool is a free-form sidebar page — non-resource, non-dashboard,
 * non-lens — that a consumer adds to the admin shell. The framework
 * ships the registration plumbing, the route surface, and the menu
 * integration; the rendered page itself is whatever React component
 * the consumer registers under the tool's component key.
 *
 * Compare with `DashboardContract` (renders a metric grid) and
 * `LensContract` (custom resource projection). Tools are the catch-all
 * for everything else: import wizards, system status pages, ad-hoc
 * reports, third-party embeds, etc.
 */
interface ToolContract
{
    public static function make(string $name, ?string $uriKey = null): static;

    /**
     * Human-readable label rendered in the menu and the page header.
     * Localisable — return a translation key wrapped via `__()` if
     * the tool needs to follow the user's locale.
     */
    public function name(): string;

    /**
     * URL slug — `/martis/tools/{uriKey}`. Stable across releases,
     * used for deep-link bookmarks and external references.
     */
    public function uriKey(): string;

    /**
     * Phosphor icon name surfaced in the menu (e.g. `wrench`,
     * `chart-bar`). Defaults to a generic tool icon when null.
     */
    public function icon(): ?string;

    /**
     * The React component key registered in `boot.ts` that renders
     * this tool's body. The Martis frontend looks up this key in the
     * componentRegistry when the user navigates to
     * `/martis/tools/{uriKey}`. When null the frontend renders an
     * empty shell and prints a console warning — exactly what
     * happens for an unregistered Resource component override.
     */
    public function component(): ?string;

    /**
     * Optional menu section label. When non-null the Tool surfaces
     * under that section in the auto-merged menu; when null it
     * lives in a default "Tools" section.
     */
    public function menuSection(): ?string;

    /**
     * Register an authorisation closure. Receives the Request,
     * returns a bool. The closure runs through `authorizedToSee()`.
     *
     * @param  Closure(Request): bool  $callback
     */
    public function canSee(Closure $callback): static;

    /**
     * True when the current user is allowed to see this tool —
     * controls both menu visibility and route access.
     */
    public function authorizedToSee(Request $request): bool;

    /**
     * Serialise the tool definition for the `/martis/api/tools`
     * endpoint and the menu.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
