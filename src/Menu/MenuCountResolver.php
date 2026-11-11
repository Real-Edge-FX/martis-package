<?php

namespace Martis\Menu;

use Closure;
use Martis\Exceptions\MenuCountFailedException;
use Martis\MartisManager;
use Throwable;
use UnexpectedValueException;

/**
 * Single choke point for running a `menuCount()` call under the
 * package's failure policy.
 *
 * A throwing counter never propagates (one broken badge must not take
 * the sidebar or the badges endpoint down), but it is no longer
 * silent either:
 *
 *   - the failure is wrapped in `MenuCountFailedException` (naming the
 *     Resource / Tool class and its badge key, with the original
 *     exception as `previous`) and sent through `report()`, so it lands
 *     in whatever channel the app's exception handler is configured
 *     for. `martis.navigation.counts.report_failures` (default `true`)
 *     turns the reporting off.
 *   - the optional `$onFailure` callback receives the same wrapper, so a
 *     caller can surface the failure where it makes sense (the badges
 *     endpoint uses it for its dev-only `_failed` diagnostics).
 *
 * Both `NavigationController::buildBadges()` and the `MenuItem`
 * resolvers go through here so the four `menuCount()` call sites share
 * one behaviour.
 */
final class MenuCountResolver
{
    /**
     * @param  string  $subjectClass  Resource or Tool class that owns the counter.
     * @param  string  $badgeKey  Badges-map key: `resource:{uriKey}` or `tool:{uriKey}`.
     * @param  Closure(): ?int  $count  The `menuCount()` call itself.
     * @param  (Closure(MenuCountFailedException): void)|null  $onFailure  Invoked after reporting when the counter threw.
     */
    public static function resolve(string $subjectClass, string $badgeKey, Closure $count, ?Closure $onFailure = null): ?int
    {
        try {
            return $count();
        } catch (Throwable $e) {
            self::fail(new MenuCountFailedException($subjectClass, $badgeKey, $e), $onFailure);

            return null;
        }
    }

    /**
     * Run the batch resolver registered with `Martis::menuCountsUsing()` (v2.20.0)
     * under the same failure policy: a throwing resolver, or one that answers
     * anything but an array, is reported and answers nothing, so every
     * resource is resolved by its own `menuCount()`.
     *
     * @param  Closure(): mixed  $batch  Calls the registered resolver.
     * @param  (Closure(MenuCountFailedException): void)|null  $onFailure  Invoked after reporting when the resolver failed.
     * @return array<array-key, mixed>
     */
    public static function resolveBatch(Closure $batch, ?Closure $onFailure = null): array
    {
        try {
            $counts = $batch();

            if (! is_array($counts)) {
                throw new UnexpectedValueException(sprintf(
                    'The resolver registered with Martis::menuCountsUsing() must return an array of counts keyed by resource class, %s returned.',
                    get_debug_type($counts),
                ));
            }

            return $counts;
        } catch (Throwable $e) {
            self::fail(new MenuCountFailedException(MartisManager::class, 'batch', $e), $onFailure);

            return [];
        }
    }

    /** @param  (Closure(MenuCountFailedException): void)|null  $onFailure */
    private static function fail(MenuCountFailedException $failure, ?Closure $onFailure): void
    {
        if ((bool) config('martis.navigation.counts.report_failures', true)) {
            report($failure);
        }

        if ($onFailure !== null) {
            $onFailure($failure);
        }
    }
}
