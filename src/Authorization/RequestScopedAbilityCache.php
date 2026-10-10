<?php

declare(strict_types=1);

namespace Martis\Authorization;

use Illuminate\Auth\Access\Events\GateEvaluated;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Stringable;

/**
 * Per-request memoisation of `$user->can(ability, $model)` results.
 *
 * Listens to `GateEvaluated` and caches `(user_class, user_id, ability,
 * argument 1, argument 2, ...) → bool` for the duration of the current
 * request. Every argument is part of the key (each model by class and key,
 * each string, number or boolean by value), so an ability that takes several
 * models, such as `attach{Model}($user, $parent, $related)`, keeps one answer
 * per combination. The user is keyed by class (its morph class) as well as by
 * id: the users of two guards (an admin and a site user) can share an id, and
 * must not share each other's answers. A subsequent lookup with the same
 * inputs returns the cached value without re-running the policy method.
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
 *   - **Unkeyable arguments skipped.** A call that holds a model with no
 *     key (or not stored), an array, a closure or any other object is not
 *     cached: a key must be deterministic, and such an argument cannot be.
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
 * `app(RequestScopedAbilityCache::class)->lookup($user, $ability, $model)`
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
    public function lookup(?Authenticatable $user, string $ability, mixed ...$arguments): ?bool
    {
        $userKey = $user === null ? null : $this->userKey($user);
        if ($userKey === null) {
            return null;
        }

        $key = $this->makeKey($userKey, $ability, $arguments);
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

        $userKey = $event->user === null ? null : $this->userKey($event->user);
        if ($userKey === null) {
            return;
        }

        $key = $this->makeKey($userKey, $event->ability, $event->arguments ?? []);
        if ($key === null) {
            return;
        }

        $this->cache[$key] = $this->allows($event->result);
    }

    public function clear(): void
    {
        $this->cache = [];
    }

    /**
     * A policy or Gate ability may answer with a `Response`, an object that
     * is always truthy: read it through `allowed()`, as the Gate does. The
     * event types its result as `bool|null`, but the Gate passes the raw
     * answer through.
     */
    protected function allows(mixed $result): bool
    {
        return $result instanceof Response ? $result->allowed() : (bool) $result;
    }

    /**
     * The user's part of the key: its class (the morph class of a model) and
     * its identifier, so an admin and a site user with the same id never
     * share an entry. Null when the identifier cannot be keyed.
     */
    protected function userKey(Authenticatable $user): ?string
    {
        $id = $user->getAuthIdentifier();
        if ($id instanceof Stringable) {
            $id = (string) $id;
        }
        if (! is_int($id) && ! is_string($id)) {
            return null;
        }

        $id = (string) $id;
        $class = $user instanceof Model ? $user->getMorphClass() : $user::class;

        return strlen($class).':'.$class.'|'.strlen($id).':'.$id;
    }

    /**
     * The key of one gate call: the user, the ability and every argument, in
     * order. An ability that takes several models (`attach{Model}($user,
     * $parent, $related)`, `detach{Model}`) keeps one answer per combination,
     * never the first model's answer for them all.
     *
     * A call is not cached (`null`) when one of its arguments cannot be keyed:
     * a model that is not stored or has no key (two different new records
     * would share one key, and a policy reads their attributes), an array, a
     * closure, any other object. Cache keys must be deterministic, so such a
     * call is skipped rather than cached wrong.
     *
     * @param  array<array-key, mixed>  $arguments  Only the order of the values matters, never the keys
     */
    protected function makeKey(string $userKey, string $ability, array $arguments): ?string
    {
        if ($arguments === []) {
            return sprintf('%s|%s|__GLOBAL__', $userKey, $ability);
        }

        $parts = [];
        foreach ($arguments as $argument) {
            $part = $this->argumentKey($argument);
            if ($part === null) {
                return null;
            }

            $parts[] = $part;
        }

        return sprintf('%s|%s|%s', $userKey, $ability, implode('|', $parts));
    }

    /**
     * One argument's part of the key, length-prefixed (`strlen:value`) so a
     * value that holds the `|` separator cannot be read as two arguments, and
     * typed so `1`, `'1'` and `true` stay apart. Null when it cannot be keyed
     * (see `makeKey()`).
     */
    protected function argumentKey(mixed $argument): ?string
    {
        if ($argument instanceof Model) {
            $key = $argument->getKey();
            if (! $argument->exists || (! is_int($key) && ! is_string($key)) || (string) $key === '') {
                return null;
            }

            return 'm'.$this->encode($argument::class).'#'.$this->encode((string) $key);
        }

        return match (true) {
            is_string($argument) => 's'.$this->encode($argument),
            is_int($argument) => 'i'.$this->encode((string) $argument),
            is_float($argument) => 'f'.$this->encode((string) $argument),
            is_bool($argument) => $argument ? 'b1' : 'b0',
            $argument === null => 'n',
            default => null,
        };
    }

    private function encode(string $value): string
    {
        return strlen($value).':'.$value;
    }
}
