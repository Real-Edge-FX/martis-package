<?php

declare(strict_types=1);

namespace Martis\Concerns;

/**
 * Declarative menu badge support — shared across every entity that
 * surfaces in the panel chrome (Dashboard, Tool, Resource, Card,
 * Lens, Filter).
 *
 * The badge is a small pill rendered next to the item label in the
 * sidebar (and any other surface that lists the entity). It is purely
 * decorative; the click behaviour is unaffected.
 *
 * Pre-v1.11 badges only existed on `Menu\MenuItem::withBadge(...)`,
 * which forced consumers to drop the auto-build and write a custom
 * `Martis::mainMenu(...)` resolver just to attach a "Pro" or "Beta"
 * label. v1.11+ exposes the same pill on the entity classes
 * themselves so the auto-build can emit it directly.
 *
 * Tones map to the same semantic palette used by the Badge field:
 * `neutral`, `info`, `success`, `warning`, `danger`, `accent`.
 *
 * Precedence (when both class-level and `MenuItem` builder set a badge):
 * the `MenuItem` builder wins. The class supplies the default; an
 * explicit override at the menu builder level is more specific and
 * takes precedence (see `MenuItem::resolve()` injection at the tail
 * of the resolved array).
 */
trait HasBadge
{
    /**
     * Decorative pill. `null` means no badge.
     *
     * @var array{text: string, tone: string}|null
     */
    protected ?array $badge = null;

    /**
     * Attach a textual badge (e.g. "New", "Beta", "Pro") next to the
     * item label. Returns `$this` for fluent chaining.
     *
     * Accepted tones: `neutral` (default), `info`, `success`,
     * `warning`, `danger`, `accent`. Unknown tones fall through to
     * `neutral` on the SPA side via the same data-tone CSS lookup
     * the Badge field uses, so a typo never crashes — it just renders
     * as a neutral pill.
     */
    public function withBadge(string $text, string $tone = 'neutral'): static
    {
        $this->badge = ['text' => $text, 'tone' => $tone];

        return $this;
    }

    /**
     * Override-friendly accessor. Subclasses can derive the badge
     * dynamically (e.g. `return $this->isTrial() ? ['text' => 'Trial', 'tone' => 'info'] : null`)
     * by overriding this method instead of calling the setter.
     *
     * @return array{text: string, tone: string}|null
     */
    public function badge(): ?array
    {
        return $this->badge;
    }
}
