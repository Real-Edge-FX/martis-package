<?php

declare(strict_types=1);

namespace Martis\Authorization;

use Illuminate\Auth\Access\Events\GateEvaluated;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-request memoisation of `$user->can(ability, $model)` results.
 *
 * Listens to `GateEvaluated` and caches `(user_id, ability, model_class, model_id)
 * → bool` for the duration of the current request. A subsequent
 * lookup with the same inputs returns the cached value without
 * re-running the policy method.
 *
 * Wins:
 *   - The Resource layer evaluates the same gate from many surfaces
 *     in one request (sidebar menu, schema authorization block,
 *     per-record authorization block, action visibility). Without
 *     a cache, each call re-runs the policy method with its full
 *     model-load chain.
 *   - For non-Spatie apps Laravel's Gate has no cache of its own;
 *     this listener fills the gap.
 *
 * Boundaries:
 *   - **Per-request only.** State is reset on the next request because
 *     this listener is registered as a request-scoped singleton
 *     (`app->scoped`). Stale data across requests is impossible.
 *   - **Closure-based gates skipped.** Only fully-deterministic
 *     `(ability, model)` keys are cached. Closure gates that depend
 *     on `$request` state, time-of-day, etc., are too dynamic to
 *     cache safely; the listener falls through.
 *   - **Result === null skipped.** Laravel passes a `?bool` so a
 *     `null` (no policy / undefined ability) is left uncached so
 *     the next call still sees the natural "fall through to default"
 *     behaviour.
 *
 * The cache only OBSERVES `GateEvaluated`. It does not short-circuit
 * the gate — every check still hits the policy at least once per
 * request. Subsequent checks read from `Map<string, bool>`.
 *
 * Note: `lookup()` is a public API intended for host-application code
 * and future internal consumers (Resource layer sidebar, per-record
 * authorization block, action visibility) to short-circuit redundant
 * `$user->can()` calls within the same request. The package does not
 * yet call `lookup()` internally; when wiring it in, call
 * `app(RequestScopedAbilityCache::class)->lookup($userId, $ability, $model)`
 * before any redundant `can()` check and skip the check when the
 * returned value is non-null.
 *
 * Off by default. Flip `MARTIS_AUTHZ_REQUEST_CACHE=true` to enable.
 */
class RequestScopedAbilityCache
{
    /** @var array<string, bool> */
    protected array $cache = [];

    /**
     * @return bool|null `null` when the call was not cacheable;
     *                   otherwise the cached or fresh result.
     */
    public function lookup(int|string|null $userId, string $ability, mixed ...$arguments): ?bool
    {
        if ($userId === null) {
            return null;
        }

        $key = $this->makeKey($userId, $ability, $arguments);
        if ($key === null) {
            return null;
        }

        return $this->cache[$key] ?? null;
    }

    public function handle(GateEvaluated $event): void
    {
        if (! (bool) config('martis.authz.request_cache', false)) {
            return;
        }

        if ($event->result === null) {
            return; // policy not registered — fall through to defaults
        }

        $userId = $event->user?->getAuthIdentifier();
        if ($userId === null) {
            return;
        }

        $key = $this->makeKey($userId, $event->ability, $event->arguments ?? []);
        if ($key === null) {
            return;
        }

        $this->cache[$key] = (bool) $event->result;
    }

    public function clear(): void
    {
        $this->cache = [];
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    protected function makeKey(int|string $userId, string $ability, array $arguments): ?string
    {
        $modelClass = null;
        $modelId = null;
        foreach ($arguments as $arg) {
            if ($arg instanceof Model) {
                $modelClass = $arg::class;
                $id = $arg->getKey();
                $modelId = is_int($id) || is_string($id) ? $id : null;
                break;
            }
            if (is_string($arg) && $modelClass === null) {
                $modelClass = $arg; // Gate::denies('view', Post::class) form
            }
        }

        // Drop calls that pass complex arguments we cannot key on
        // (closures, plain arrays, request bags). Cache keys must
        // be deterministic — non-Model non-string args are too
        // ambiguous, skip rather than cache wrong.
        if ($modelClass === null && $arguments === []) {
            $modelClass = '__GLOBAL__';
        } elseif ($modelClass === null) {
            return null;
        }

        return sprintf('%s|%s|%s|%s', (string) $userId, $ability, $modelClass, (string) ($modelId ?? ''));
    }
}
