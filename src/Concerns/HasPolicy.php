<?php

declare(strict_types=1);

namespace Martis\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Martis\Authorization\PolicyResolver;

/**
 * Declarative Laravel Policy binding for non-Resource menu entities
 * (Dashboard, Tool, Card, Lens, Filter).
 *
 * Pre-v1.11 these classes had only `canSee(Closure)` for auth — a
 * single closure on the entity instance. That worked but lacked
 * the discoverability of Resource's `static $policy = SomePolicy::class`
 * convention. v1.11 unifies the pattern: declare a policy class
 * (or rely on auto-discovery `{policy_namespace}\{baseName}Policy`),
 * and `authorizedToView()` / equivalent walks Policy::view → canSee
 * closure → default true.
 *
 * `Resource` keeps its richer resolver (which also checks
 * `Gate::getPolicyFor(static::model())` for an Eloquent-bound policy)
 * — that pipeline does not generalise to entities without a Model.
 * The trait below covers the simple case: declarative `$policy` plus
 * convention auto-discovery, both routed through Laravel's Gate.
 *
 * Resolution is memoised per concrete class for the current request /
 * job / application lifecycle by the container-scoped
 * {@see PolicyResolver}; the policy instance is resolved from the
 * container on every call (v1.36.0, previously a static instance cache
 * that outlived the application which filled it).
 */
trait HasPolicy
{
    /**
     * Override on a subclass with `public static ?string $policy = MyPolicy::class;`
     * to bind a Laravel Policy explicitly. Leaving it `null` falls back
     * to convention auto-discovery in `{policy_namespace}\{baseName}Policy`.
     */
    public static ?string $policy = null;

    /**
     * Resolve the active policy instance:
     *
     *   1. Explicit `static::$policy` set + class exists → use it.
     *   2. Auto-discovery: `config('martis.policy_namespace') \ {baseName}Policy`.
     *   3. Otherwise `null`.
     *
     * `{baseName}` strips a trailing `Resource`, `Dashboard`, `Tool`,
     * `Card`, `Lens`, or `Filter` suffix so `ProLabDashboard` maps to
     * `App\Martis\Policies\ProLabPolicy` rather than `ProLabDashboardPolicy`.
     */
    public static function resolvePolicy(): ?object
    {
        return app(PolicyResolver::class)->resolve(
            static::class,
            static fn (): ?string => static::discoverHasPolicyClass(),
        );
    }

    /**
     * Walk the resolution order and return the policy class, or null.
     *
     * @return class-string|null
     */
    protected static function discoverHasPolicyClass(): ?string
    {
        if (static::$policy !== null && class_exists(static::$policy)) {
            return static::$policy;
        }

        $namespace = (string) config('martis.policy_namespace', 'App\\Martis\\Policies');
        $baseName = class_basename(static::class);
        // Strip the suffix that matches the entity kind so the policy
        // name is shorter and natural to read.
        $baseName = (string) preg_replace('/(Resource|Dashboard|Tool|Card|Lens|Filter)$/', '', $baseName);
        $policyClass = $namespace.'\\'.$baseName.'Policy';

        return class_exists($policyClass) ? $policyClass : null;
    }

    /**
     * Forget the memoised policy resolution of every entity (Resources
     * included, the memo is shared). Useful in tests that rebind the
     * container or swap `$policy` between expectations.
     */
    public static function flushPolicyCache(): void
    {
        app(PolicyResolver::class)->flush();
    }

    /**
     * Run a Gate ability against the resolved policy. Returns `null`
     * when no policy is configured and no method exists, so the
     * caller can fall back to a closure (`canSee`) or default.
     */
    protected function checkHasPolicyAbility(string $ability, Request $request): ?bool
    {
        $policy = static::resolvePolicy();

        if ($policy === null) {
            return null;
        }

        if (! method_exists($policy, $ability)) {
            return null;
        }

        $user = $request->user();

        // Use the active Gate so the policy resolves through the same
        // pipeline custom registrations rely on (before/after hooks,
        // facade override, etc.).
        return Gate::forUser($user)->allows($ability, [static::class]);
    }
}
