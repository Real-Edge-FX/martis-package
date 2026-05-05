<?php

declare(strict_types=1);

namespace Martis\Gates;

use Closure;
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
 * resolved plan rank sits below the required rank". Without the
 * config in place the ranker degrades quietly: unknown plans rank
 * `-1`, comparisons fail open (no lock), and the trait stays inert.
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
     * `gates.plan_resolver` closure in `config/martis.php`.
     */
    protected function resolveCurrentPlan(?Authenticatable $user): ?string
    {
        $resolver = config('martis.gates.plan_resolver');
        if (! $resolver instanceof Closure) {
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
