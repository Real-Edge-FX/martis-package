<?php

declare(strict_types=1);

namespace Martis\Gates;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Optional plan-rank helper for the soft-gate trait.
 *
 * Martis itself does not know about "plans" — that is consumer
 * domain (Stripe Cashier subscriptions, Spatie roles, custom
 * claims, whatever). To stay decoupled, the trait reaches the
 * ranker through this service, which the consumer configures via
 * `config/martis.php`:
 *
 *     'gates' => [
 *         'plan_resolver' => fn (?Authenticatable $user) =>
 *             $user?->hasRole('admin') ? 'admin'
 *             : ($user?->hasRole('pro') ? 'pro'
 *             : ($user?->hasRole('starter') ? 'starter' : 'free')),
 *
 *         'plan_rank' => [
 *             'free'    => 0,
 *             'starter' => 1,
 *             'pro'     => 2,
 *             'admin'   => 3,
 *         ],
 *     ],
 *
 * `requirePlan('pro')` then translates into "locked when the user's
 * resolved plan rank sits below the required rank".
 *
 * Failure modes when the config is absent or incomplete:
 *   - **Unknown required tier** (the string passed to `requirePlan` is
 *     not in `plan_rank`): fails **open** — the gate is skipped so a
 *     typo does not accidentally hide features.
 *   - **Resolver not configured or returns null**: fails **closed** —
 *     the user is treated as having no plan (rank -1) and is locked
 *     below every declared tier.
 *   - **User's resolved plan not in the rank table**: also fails
 *     **closed** — the unknown plan receives implicit rank -1, which
 *     is less than every declared rank (minimum 0), so the user is
 *     locked.
 */
class PlanRanker
{
    /**
     * Return `true` when the request's user does NOT meet the
     * required plan tier. Locked = does not have access.
     */
    public function isLockedFor(Request $request, string $required): bool
    {
        $ranks = $this->planRank();
        $requiredRank = $ranks[$required] ?? null;
        if ($requiredRank === null) {
            // The required tier is not declared in the rank table.
            // Fail open — locking on a typo would hide features
            // unexpectedly. Hosts that care can phpstan over the call
            // sites.
            return false;
        }

        $current = $this->resolveCurrentPlan($request->user());
        if ($current === null) {
            // No plan resolver configured (or the resolver returned
            // null for this user). We treat that as "no plan" =
            // rank -1, which sits below every declared tier — the
            // user is locked.
            return true;
        }

        $currentRank = $ranks[$current] ?? -1;

        return $currentRank < $requiredRank;
    }

    /**
     * Plan tier currently held by the user, as resolved by the
     * `gates.plan_resolver` callable in `config/martis.php`.
     *
     * v1.11.2: accepts any PHP callable, not only `Closure`. Closures
     * cannot survive `php artisan config:cache` (the cache uses
     * `var_export` and chokes on `Closure::__set_state()`); a
     * `[Class::class, 'method']` array or a class name with `__invoke`
     * does survive, so consumers that cache config can express the
     * resolver as a static method or invokable class instead of a
     * closure.
     */
    protected function resolveCurrentPlan(?Authenticatable $user): ?string
    {
        $resolver = config('martis.gates.plan_resolver');
        if (! is_callable($resolver)) {
            return null;
        }

        $value = $resolver($user);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, int>
     */
    protected function planRank(): array
    {
        /** @var array<string, int>|mixed $configured */
        $configured = config('martis.gates.plan_rank', []);

        if (! is_array($configured)) {
            return [];
        }

        $out = [];
        foreach ($configured as $name => $rank) {
            if (is_string($name) && is_int($rank)) {
                $out[$name] = $rank;
            }
        }

        return $out;
    }
}
