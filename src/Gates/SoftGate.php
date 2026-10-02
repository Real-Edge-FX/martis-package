<?php

declare(strict_types=1);

namespace Martis\Gates;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Martis\Concerns\HasGate;
use Martis\Support\TranslatedLine;

/**
 * The server-side half of the soft gate (`lockedFor()`, `requirePlan()`,
 * {@see HasGate}).
 *
 * The page endpoints of a locked dashboard or tool answer `200` with
 * `{ locked: true, lock }`, so the SPA renders the lock modal as a full-page
 * state. Every endpoint that serves the entity's DATA (a dashboard card, a
 * resource's records, a tool's routes and fields, a lens, a card, a picker)
 * answers through here instead: `403` with the same `lock` payload, so a user
 * a plan or a feature flag locks out cannot read or write through a URL the
 * page never calls. One shape, one status, for every data endpoint.
 *
 * `canSee()` and the policies still win: a user who may not see the entity is
 * answered as before (404 / 403 without a lock payload), and the lock is
 * only ever told to a user who passes them.
 */
final class SoftGate
{
    /**
     * The lock payload of `$entity` for the request (`{ reason, modal }`), or
     * `null` when it is not locked or cannot be (a plain array, an object
     * without the {@see HasGate} trait).
     *
     * @return array<string, mixed>|null
     */
    public static function lockOf(mixed $entity, Request $request): ?array
    {
        if (! is_object($entity) || ! method_exists($entity, 'lockPayloadFor')) {
            return null;
        }

        $lock = $entity->lockPayloadFor($request);

        return is_array($lock) ? $lock : null;
    }

    /**
     * Whether `$entity` is locked for the request.
     */
    public static function isLocked(mixed $entity, Request $request): bool
    {
        return self::lockOf($entity, $request) !== null;
    }

    /**
     * A cache-key part for the lock state of `$entities`: empty when none is
     * locked for the request, otherwise a short hash of the ones that are.
     * A payload cached with a locked entity's data withheld (a locked Card's
     * `meta`) must not outlive the lock, so its key carries this: a plan
     * change lands on the next request, not when the cache expires.
     *
     * @param  iterable<mixed>  $entities
     */
    public static function fingerprint(iterable $entities, Request $request): string
    {
        $locked = [];

        foreach ($entities as $entity) {
            if (! self::isLocked($entity, $request)) {
                continue;
            }

            $locked[] = is_object($entity) && method_exists($entity, 'uriKey') ? $entity::class.'@'.$entity->uriKey() : get_debug_type($entity);
        }

        return $locked === [] ? '' : substr(sha1(implode('|', $locked)), 0, 10);
    }

    /**
     * The `403` a data endpoint answers when the entity is locked for the
     * request, or `null` when it is not.
     */
    public static function refusalFor(mixed $entity, Request $request): ?JsonResponse
    {
        $lock = self::lockOf($entity, $request);

        return $lock === null ? null : self::refusal($lock);
    }

    /**
     * The `403` for a lock payload: the error envelope of the other
     * refusals (`message`, `errors`) plus `locked: true` and the `lock`
     * (`reason`, `modal`) the pages read.
     *
     * @param  array<string, mixed>  $lock
     */
    public static function refusal(array $lock): JsonResponse
    {
        return new JsonResponse([
            'message' => TranslatedLine::get('martis::messages.feature_locked'),
            'errors' => [],
            'locked' => true,
            'lock' => $lock,
        ], 403);
    }
}
