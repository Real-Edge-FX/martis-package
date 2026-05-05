<?php

declare(strict_types=1);

namespace Martis\Concerns;

use Closure;
use Illuminate\Http\Request;
use Martis\Gates\PlanRanker;

/**
 * Soft-gate primitive shared across menu entities (Dashboard, Tool,
 * Resource, Card, Lens, Filter).
 *
 * `canSee()` is a hard hide: when it returns false the entity is
 * filtered out before any descriptor is built; the user never knows
 * it exists. That works for "this user is not an admin" but kills
 * the upsell story for plan-gated features — a free user that is
 * not allowed to see what they could buy never converts.
 *
 * `lockedFor()` is the soft alternative. The entity stays visible,
 * the sidebar paints a lock icon + the configured badge, and the
 * click is intercepted client-side: instead of navigating, the SPA
 * opens a modal driven by `lockModal(...)` data — typically an
 * upsell with a CTA to the upgrade flow. Direct URL access is
 * stopped server-side too via the route guard layer that ships in
 * the same release.
 *
 * The two mechanisms compose with explicit precedence: `canSee()`
 * wins. When `canSee()` returns false, `lockedFor()` is never
 * evaluated; the user sees nothing. Devs typically reach for one
 * or the other per entity, not both.
 *
 * `lockPreset(string $name)` reads `config('martis.gates.presets.<name>')`
 * and applies its `badge` + `modal` keys in one call. Hosts can
 * declare a preset per plan tier and reuse across entities; the
 * `requirePlan(string $tier)` shortcut combines a preset with the
 * `PlanRanker` so a single line covers the common case.
 *
 * Consumers using the {@see HasBadge} trait already have `withBadge`
 * declared; `lockPreset` calls into that setter when the preset
 * carries a `badge` key. The trait gracefully no-ops on the badge
 * side when `HasBadge` is absent (the `method_exists` guard below).
 */
trait HasGate
{
    /**
     * Returns true when the entity is locked for the given request.
     * `null` means no soft gate configured (the entity is always
     * unlocked for users that pass `canSee()`).
     *
     * @var Closure(Request): bool|null
     */
    protected ?Closure $lockedForCallback = null;

    /**
     * Modal payload rendered when a locked user clicks the entry.
     *
     * Shape:
     *   - `title`: string, mandatory
     *   - `message`: string, mandatory; supports plain text or HTML
     *     when `messageHtml` is true
     *   - `messageHtml`: bool, default false
     *   - `cta`: optional `{ label: string, url: string, target?: '_self'|'_blank' }`
     *   - `dismiss`: bool, default true; when false the modal can only
     *     be closed by following the CTA
     *   - `icon`: optional Phosphor icon name; defaults to `'lock'`
     *     in the SPA
     *
     * @var array<string, mixed>|null
     */
    protected ?array $lockModalConfig = null;

    /**
     * Plan tier this entity requires. `null` means no plan check.
     * The {@see PlanRanker} consumes this against the resolved user
     * plan to decide whether the entity is locked.
     */
    protected ?string $requiredPlan = null;

    /**
     * Register a closure that decides whether the entity is locked
     * for the active request. The closure should return `true` when
     * the user is locked (no access; modal will fire).
     *
     * @param  Closure(Request): bool|null  $callback
     */
    public function lockedFor(?Closure $callback): static
    {
        $this->lockedForCallback = $callback;

        return $this;
    }

    /**
     * Configure the modal payload shown to a locked user. Clearing
     * the modal (passing `null`) leaves the lock check intact but
     * removes any consumer-side modal — the SPA falls back to the
     * Martis default copy in that case.
     *
     * @param  array<string, mixed>|null  $modal
     */
    public function lockModal(?array $modal): static
    {
        $this->lockModalConfig = $modal;

        return $this;
    }

    /**
     * Apply the badge + modal pair declared under
     * `config('martis.gates.presets.<name>')`. Returns `$this` so
     * the call chains with the predicate setter:
     *
     *     $this->lockedFor(fn ($r) => ! $r->user()?->hasRole('pro'))
     *          ->lockPreset('pro');
     *
     * Unknown preset names log via Laravel's facade (no exception
     * thrown) and the entity behaves as if no preset had been
     * applied. Same forgiving stance the `density` preset takes on
     * the preferences side.
     */
    public function lockPreset(string $name): static
    {
        /** @var array<string, mixed> $presets */
        $presets = (array) config('martis.gates.presets', []);
        if (! array_key_exists($name, $presets) || ! is_array($presets[$name])) {
            return $this;
        }

        /** @var array<string, mixed> $preset */
        $preset = $presets[$name];

        if (isset($preset['badge']) && is_array($preset['badge'])) {
            // Every class that uses HasGate also uses HasBadge in v1.11+,
            // so withBadge() is always available — the trait pair is the
            // documented contract, enforced at install time.
            /** @var array{text?: string, tone?: string} $badge */
            $badge = $preset['badge'];
            $this->withBadge(
                (string) ($badge['text'] ?? ''),
                (string) ($badge['tone'] ?? 'neutral'),
            );
        }

        if (isset($preset['modal']) && is_array($preset['modal'])) {
            /** @var array<string, mixed> $modal */
            $modal = $preset['modal'];
            $this->lockModalConfig = $modal;
        }

        return $this;
    }

    /**
     * Shortcut that combines `requirePlan` with the matching preset.
     * The host declares plan tiers via `config('martis.gates.plan_rank')`
     * and a `plan_resolver` closure; the {@see PlanRanker} compares
     * the user's resolved plan against `$tier` and locks when the
     * user sits below the required rank.
     *
     * Calling `requirePlan('pro')` without configuring the resolver
     * is a no-op — the trait stays inert, the entity unlocks for
     * everyone. Hosts that wire the resolver get the gate for free.
     */
    public function requirePlan(string $tier): static
    {
        $this->requiredPlan = $tier;

        return $this;
    }

    /**
     * Resolve the plan-rank check into a callable predicate. Returns
     * `null` when no plan tier is configured for this entity.
     *
     * @return Closure(Request): bool|null
     */
    protected function planLockPredicate(): ?Closure
    {
        if ($this->requiredPlan === null) {
            return null;
        }

        $tier = $this->requiredPlan;

        return static function (Request $request) use ($tier): bool {
            /** @var PlanRanker $ranker */
            $ranker = app(PlanRanker::class);

            return $ranker->isLockedFor($request, $tier);
        };
    }

    /**
     * Evaluate every configured lock predicate in order. The first
     * one to return `true` wins and locks the entity. When nothing
     * locks, returns `false` (entity accessible).
     */
    public function isLockedFor(Request $request): bool
    {
        $predicates = array_filter([
            $this->lockedForCallback,
            $this->planLockPredicate(),
        ]);

        foreach ($predicates as $predicate) {
            if ((bool) call_user_func($predicate, $request) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * The modal payload to render for a locked user, or `null` when
     * the entity is unlocked for the active request.
     *
     * @return array<string, mixed>|null
     */
    public function lockPayloadFor(Request $request): ?array
    {
        if (! $this->isLockedFor($request)) {
            return null;
        }

        return [
            'reason' => $this->requiredPlan === null ? 'gated' : 'plan:'.$this->requiredPlan,
            'modal' => $this->lockModalConfig,
        ];
    }

    /**
     * Convenience used by `toArray()` implementations where the
     * caller does not pass a Request explicitly. Reads the active
     * request from the container (`request()` helper). Returns null
     * when no request is bound — useful in CLI / queue contexts
     * where the gate has no meaning.
     *
     * @return array<string, mixed>|null
     */
    public function lockPayloadNow(): ?array
    {
        try {
            /** @var Request|null $request */
            $request = app('request');
        } catch (\Throwable) {
            return null;
        }

        if (! $request instanceof Request) {
            return null;
        }

        return $this->lockPayloadFor($request);
    }
}
