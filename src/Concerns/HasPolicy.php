<?php

declare(strict_types=1);

namespace Martis\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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
 */
trait HasPolicy
{
    /**
     * Cache of resolved policy instances per concrete class. Populated
     * lazily on the first `resolvePolicy()` call; survives the request
     * lifecycle (the container scope is request-scoped already).
     *
     * @var array<class-string, object|false>
     */
    protected static array $resolvedHasPolicyPolicies = [];

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
        $key = static::class;

        if (array_key_exists($key, self::$resolvedHasPolicyPolicies)) {
            $cached = self::$resolvedHasPolicyPolicies[$key];

            return $cached === false ? null : $cached;
        }

        if (static::$policy !== null && class_exists(static::$policy)) {
            $instance = app(static::$policy);
            self::$resolvedHasPolicyPolicies[$key] = $instance;

            return $instance;
        }

        $namespace = (string) config('martis.policy_namespace', 'App\\Martis\\Policies');
        $baseName = class_basename(static::class);
        // Strip the suffix that matches the entity kind so the policy
        // name is shorter and natural to read.
        $baseName = (string) preg_replace('/(Resource|Dashboard|Tool|Card|Lens|Filter)$/', '', $baseName);
        $policyClass = $namespace.'\\'.$baseName.'Policy';

        if (class_exists($policyClass)) {
            $instance = app($policyClass);
            self::$resolvedHasPolicyPolicies[$key] = $instance;

            return $instance;
        }

        self::$resolvedHasPolicyPolicies[$key] = false;

        return null;
    }

    /**
     * Forget the per-class policy resolution cache. Useful in tests
     * that rebind the container between expectations.
     */
    public static function flushPolicyCache(): void
    {
        self::$resolvedHasPolicyPolicies = [];
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
